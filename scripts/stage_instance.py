#!/usr/bin/env python3
"""Create an isolated, loopback-only rehearsal from a live instance; never switch the live site."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import subprocess
import sys
import time
import urllib.request


def read_env(path):
    values={}
    for line in path.read_text(encoding='utf-8-sig').splitlines():
        line=line.strip()
        if not line or line.startswith('#'): continue
        key,sep,value=line.partition('=')
        if not sep or not re.fullmatch('[A-Z][A-Z0-9_]*',key):
            raise ValueError('Invalid environment file')
        values[key]=value.strip().strip('\'"')
    return values


def private_write(path,content):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600)
    with os.fdopen(fd,'w',encoding='utf-8',newline='\n') as stream: stream.write(content)
    path.chmod(0o600)


def validate_target(source,target,project,source_project,port,source_db):
    if not re.fullmatch(r'ucasser-stage-[a-z0-9-]{1,32}',project):
        raise ValueError('Stage project must start with ucasser-stage-')
    if project==source_project or target==source or target.parent!=source.parent or target.name!=project:
        raise ValueError('Stage must be a separate sibling directory named after its new project')
    if target.exists() or target.is_symlink():
        raise ValueError('Stage target already exists; it will not be overwritten')
    if not 1024<=port<=65535: raise ValueError('Invalid stage port')
    database=project.replace('-','_')
    if database==source_db: raise ValueError('Stage database must differ from source')
    return database


def stage_compose(root,project,images,port,db_name):
    mount=lambda name,destination: f'{project}_{name}:{destination}'
    return {
        'name':project,
        'services':{
            'db':{'image':images['db'],'environment':{
                'MARIADB_DATABASE':db_name,'MARIADB_USER':'stage',
                'MARIADB_PASSWORD':'${DB_PASSWORD}','MARIADB_ROOT_PASSWORD':'${DB_ROOT_PASSWORD}'},
                'command':['--innodb-buffer-pool-size=64M','--max-connections=30','--performance-schema=OFF'],
                'volumes':[mount('database','/var/lib/mysql')],'mem_limit':'256m',
                'healthcheck':{'test':['CMD','healthcheck.sh','--connect','--innodb_initialized'],'interval':'3s','timeout':'5s','retries':40}},
            'app':{'image':images['app'],'env_file':['.env.stage'],
                'environment':{'DB_ROOT_PASSWORD':''},
                'volumes':[{'type':'bind','source':str(root),'target':'/var/www/forum','read_only':True},
                           mount('vendor','/var/www/forum/vendor'),mount('assets','/var/www/forum/public/assets'),mount('storage','/var/www/forum/storage')],
                'mem_limit':'192m','depends_on':{'db':{'condition':'service_healthy'}}},
            'web':{'image':images['web'],'ports':[f'127.0.0.1:{port}:80'],
                'networks':['default','preview'],
                'volumes':[{'type':'bind','source':str(root/'public'),'target':'/var/www/forum/public','read_only':True},
                           mount('assets','/var/www/forum/public/assets')+':ro',
                           {'type':'bind','source':str(root/'docker/web/default.conf'),'target':'/etc/nginx/conf.d/default.conf','read_only':True}],
                'mem_limit':'32m','depends_on':['app']},
        },
        'volumes':{project+'_'+key:{'name':project+'_'+key,**({'external':True} if key!='database' else {})} for key in ['database','vendor','assets','storage']},
        'networks':{'default':{'internal':True},'preview':{}},
    }


def prepare_mount_points(root):
    # Docker cannot create nested mount points inside a read-only source bind.
    for relative in ['vendor','storage','public/assets']:(root/relative).mkdir(parents=True,exist_ok=True)


FACTS=r'''<?php
$c=require 'config/environment.php';$d=$c['database'];
$p=new PDO('mysql:host='.$d['host'].';port='.$d['port'].';dbname='.$d['database'].';charset=utf8mb4',$d['username'],$d['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$p->exec('SET SESSION TRANSACTION READ ONLY');$p->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
$out=['tables'=>[]];$prefix=$d['prefix'];if(!preg_match('/^[A-Za-z0-9_]*$/D',$prefix))throw new RuntimeException('prefix');
foreach($p->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME') as $r){$t=$r[0];if(!preg_match('/^[A-Za-z0-9_]+$/D',$t))continue;$out['tables'][$t]=(int)$p->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn();}
$q=$p->query('SELECT value FROM `'.$prefix.'settings` WHERE `key`=\'extensions_enabled\'');$out['extensions']=json_decode($q->fetchColumn(),true);
$p->rollBack();echo json_encode($out);
'''

SANITIZE=r'''<?php
$c=require 'config/environment.php';$d=$c['database'];
if(!str_starts_with($d['database'],'ucasser_stage_') || !str_starts_with($c['url'],'http://127.0.0.1:'))throw new RuntimeException('Not staging');
$p=new PDO('mysql:host='.$d['host'].';port='.$d['port'].';dbname='.$d['database'].';charset=utf8mb4',$d['username'],$d['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$prefix=$d['prefix'];if(!preg_match('/^[A-Za-z0-9_]*$/D',$prefix))throw new RuntimeException('prefix');
$p->beginTransaction();$q=$p->prepare('UPDATE `'.$prefix.'settings` SET value=? WHERE `key`=?');
foreach(['mail_driver'=>'null','allow_sign_up'=>'0'] as $k=>$v)$q->execute([$v,$k]);
$title=$p->query('SELECT value FROM `'.$prefix.'settings` WHERE `key`=\'forum_title\'')->fetchColumn();$q->execute([$title.'（部署预览）','forum_title']);
foreach(['access_tokens','email_tokens','password_tokens','registration_tokens','queue_jobs','queue_failed_jobs'] as $t){$exists=$p->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$exists->execute([$prefix.$t]);if($exists->fetchColumn())$p->exec('DELETE FROM `'.$prefix.$t.'`');}
$p->commit();echo "Stage mail, signup, tokens and queued jobs isolated\n";
'''


class Stage:
    def __init__(self,args):
        self.args=args
        self.source=Path(args.source_root).resolve()
        self.target=Path(args.stage_root).resolve()
        self.values=read_env(self.source/args.env_file)
        self.project=args.project
        self.db_name=validate_target(self.source,self.target,self.project,self.values.get('COMPOSE_PROJECT_NAME'),args.port,self.values.get('DB_NAME'))
        self.env={**os.environ,'FORUM_ENV_FILE':str(self.source/args.env_file)}
        self.source_dc=['docker','compose','--env-file',str(self.source/args.env_file),'-f',str(self.source/'compose.yaml'),'-p',self.values['COMPOSE_PROJECT_NAME']]
        self.stage_dc=['docker','compose','--env-file',str(self.target/'.env.stage'),'-f',str(self.target/'compose.stage.json'),'-p',self.project]
        self.git=['git','-c','safe.directory='+str(self.source),'-C',str(self.source)]
        self.log=[]

    def run(self,command,*,data=None,cwd=None):
        result=subprocess.run([str(x) for x in command],cwd=cwd or self.source,env=self.env,
            stdout=subprocess.PIPE,stderr=subprocess.PIPE,**({'input':data} if data is not None else {'stdin':subprocess.DEVNULL}))
        if result.returncode:
            # Command arguments/errors may contain DB data. Keep a private diagnostic only.
            message=result.stderr.decode('utf-8',errors='replace')
            for key in ['DB_PASSWORD','DB_ROOT_PASSWORD']:
                if self.values.get(key):message=message.replace(self.values[key],'[REDACTED]')
            if self.target.exists(): private_write(self.target/'.runtime/stage-error.log',message)
            raise RuntimeError('Stage command failed; source was not switched. Inspect the private stage-error.log if present.')
        return result.stdout

    def inspect(self,service):
        container=self.run([*self.source_dc,'ps','-q',service]).decode().strip()
        if not container or '\n' in container: raise ValueError('Exactly one running source '+service+' is required')
        item=json.loads(self.run(['docker','inspect',container]))[0]
        if item['Config']['Labels'].get('com.docker.compose.project')!=self.values['COMPOSE_PROJECT_NAME']:
            raise ValueError('Unexpected source project')
        return container,item

    def facts(self,container):
        return json.loads(self.run(['docker','exec','-i','-u','www-data',container,'php'],data=FACTS.encode()))

    def create(self):
        services={key:self.inspect(key) for key in ['app','db','web']}
        images={key:value[1]['Image'] for key,value in services.items()}
        mounts={m['Destination']:m for m in services['app'][1]['Mounts']}
        for target in ['/var/www/forum/vendor','/var/www/forum/storage','/var/www/forum/public/assets']:
            if mounts.get(target,{}).get('Type')!='volume':raise ValueError('Source requires named runtime volumes')
        ref=self.args.ref or self.run([*self.git,'rev-parse','HEAD']).decode().strip()
        if not re.fullmatch('[a-f0-9]{40}',ref):raise ValueError('Use a full commit SHA')
        if self.run([*self.git,'show',ref+':composer.lock']).replace(b'\r\n',b'\n')!=(self.source/'composer.lock').read_bytes().replace(b'\r\n',b'\n'):
            raise ValueError('Dependency lock differs; stage cannot reuse source dependencies')
        if (self.source/'config.php').read_text(encoding='utf-8').replace('\r\n','\n')!="<?php\nreturn require __DIR__.'/config/environment.php';\n":
            raise ValueError('Expected environment-bridge config.php')
        if self.run(['docker','ps','-aq','--filter','label=com.docker.compose.project='+self.project]).strip():
            raise ValueError('Stage project already has containers')
        for name in ['database','vendor','assets','storage']:
            if self.run(['docker','volume','ls','-q','--filter','name=^'+self.project+'_'+name+'$']).strip():
                raise ValueError('Stage volume already exists')
        with socket.socket() as probe:probe.bind(('127.0.0.1',self.args.port))
        plan={'source_project':self.values['COMPOSE_PROJECT_NAME'],'stage_project':self.project,'source_commit':self.run([*self.git,'rev-parse','HEAD']).decode().strip(),
              'stage_commit':ref,'stage_root':str(self.target),'stage_url':f'http://127.0.0.1:{self.args.port}',
              'source_changes':'none; consistent DB dump and read-only volume copies only','stage_email':'disabled','stage_network':'app/db internal; web loopback only; no runner or scheduler','create':self.args.create}
        if not self.args.create:
            print(json.dumps(plan,ensure_ascii=False,indent=2));return
        if shutil.disk_usage(self.source.parent).free<2*1024**3:raise ValueError('Stage requires at least 2 GiB free disk')
        if Path('/proc/meminfo').exists():
            available=re.search(r'MemAvailable:\s+(\d+)',Path('/proc/meminfo').read_text())
            if available and int(available[1])<512*1024:raise ValueError('Stage requires at least 512 MiB available memory')
        before=self.facts(services['app'][0])
        print('Creating isolated stage; live instance remains running',flush=True)
        self.run(['git','-c','safe.directory='+str(self.source),'clone','--no-hardlinks','--no-checkout','--',str(self.source),str(self.target)])
        self.run(['git','-C',str(self.target),'checkout','--detach',ref])
        (self.target/'.runtime').mkdir(mode=0o700,exist_ok=True)
        self.values_for_stage={'APP_ENV':'development','APP_URL':plan['stage_url'],'APP_DEBUG':'false','FORUM_TITLE':'部署预览','TZ':'Asia/Shanghai',
            'HTTP_BIND':'127.0.0.1','HTTP_PORT':str(self.args.port),'COMPOSE_PROJECT_NAME':self.project,
            'DB_HOST':'db','DB_PORT':'3306','DB_NAME':self.db_name,'DB_USER':'stage','DB_PREFIX':self.values.get('DB_PREFIX',''),
            'DB_PASSWORD':secrets.token_hex(24),'DB_ROOT_PASSWORD':secrets.token_hex(24)}
        private_write(self.target/'.env.stage',''.join(k+'='+v+'\n' for k,v in self.values_for_stage.items()))
        (self.target/'config.php').write_text("<?php\nreturn require __DIR__.'/config/environment.php';\n",encoding='utf-8')
        prepare_mount_points(self.target)
        for dist in (self.target/'extensions').glob('*/js/dist'):
            for frontend in ['admin','forum','common']:(dist/frontend).mkdir(exist_ok=True)
        config=stage_compose(self.target,self.project,images,self.args.port,self.db_name)
        (self.target/'compose.stage.json').write_text(json.dumps(config,indent=2)+'\n',encoding='utf-8')
        # The copy helpers have no network, mount source volumes read-only and never use source credentials.
        for suffix,source_mount in [('vendor','/var/www/forum/vendor'),('assets','/var/www/forum/public/assets')]:
            self.run(['docker','run','--rm','--network','none','-v',mounts[source_mount]['Name']+':/source:ro','-v',self.project+'_'+suffix+':/target',images['app'],'sh','-c','cp -a /source/. /target/'])
        self.run(['docker','run','--rm','--network','none','-v',mounts['/var/www/forum/storage']['Name']+':/source:ro','-v',self.project+'_storage:/target',images['app'],'sh','-c',
            'for name in campus-chat-images campus-post-images; do if [ -d /source/$name ]; then cp -a /source/$name /target/; fi; done; chown -R 33:33 /target'])
        print('Runtime copies ready; taking a consistent source database snapshot',flush=True)
        dump=self.run(['docker','exec',services['db'][0],'sh','-c','MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb-dump -u "$MARIADB_USER" --single-transaction --quick --skip-comments --skip-add-locks --hex-blob "$MARIADB_DATABASE"'])
        if len(dump)<100:raise ValueError('Source dump is empty')
        private_write(self.target/'.runtime/source-snapshot.sql',dump.decode('utf-8'))
        self.run([*self.stage_dc,'up','-d','--wait','db'])
        self.run([*self.stage_dc,'exec','-T','db','sh','-c','MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb -u "$MARIADB_USER" "$MARIADB_DATABASE"'],data=dump)
        self.run([*self.stage_dc,'run','--rm','--no-deps','-T','-u','www-data','app','php'],data=SANITIZE.encode())
        self.run([*self.stage_dc,'run','--rm','--no-deps','-T','-u','0','app','php','scripts/prepare-runtime.php'])
        self.run([*self.stage_dc,'run','--rm','--no-deps','-T','-u','0','app','chown','-R','33:33','storage','public/assets'])
        self.run([*self.stage_dc,'run','--rm','--no-deps','-T','-u','www-data','app','php'],data=b"<?php require 'vendor/autoload.php'; $c=(require 'site.php')->bootApp()->getContainer(); $c->make(Flarum\\Extension\\ExtensionManager::class)->enable('flarum-messages'); echo 'Stage messages enabled';")
        for command in [['migrate'],['campus:setup'],['cache:clear']]:
            self.run([*self.stage_dc,'run','--rm','--no-deps','-T','-u','www-data','app','php','flarum',*command])
        self.run([*self.stage_dc,'up','-d','app','web'])
        app=self.run([*self.stage_dc,'ps','-q','app']).decode().strip()
        staged=self.facts(app)
        after=self.facts(services['app'][0])
        for table in ['users','campus_aggregation_candidates','campus_courses','campus_course_offerings']:
            table=self.values.get('DB_PREFIX','')+table
            if before['tables'].get(table)==after['tables'].get(table) and staged['tables'].get(table)!=before['tables'].get(table):
                raise RuntimeError('Staged business record count mismatch: '+table)
        opener=urllib.request.build_opener(urllib.request.ProxyHandler({}))
        deadline=time.monotonic()+30
        while True:
            try:
                with opener.open(plan['stage_url']+'/api',timeout=4) as response:
                    if json.load(response).get('data',{}).get('type')!='forums':raise RuntimeError('Stage API health failed')
                break
            except OSError:
                if time.monotonic()>=deadline:raise RuntimeError('Stage API did not become reachable; source was not changed') from None
                time.sleep(1)
        report={**plan,'snapshot_sha256':hashlib.sha256(dump).hexdigest(),'source_before':before,'source_after':after,'staged':staged,
            'ready_for_review':True,'production_switched':False,'note':'No content transfer, operator grant, collector authorization or live upgrade performed.'}
        private_write(self.target/'.runtime/stage-report.json',json.dumps(report,ensure_ascii=False,indent=2)+'\n')
        print(json.dumps({'stage_url':plan['stage_url'],'stage_root':str(self.target),'report':str(self.target/'.runtime/stage-report.json'),
            'staged_business_counts_checked':True,'source_changed_during_snapshot':before['tables']!=after['tables'],
            'messages_enabled_in_stage':'flarum-messages' in staged['extensions'],'production_switched':False},ensure_ascii=False,indent=2))


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source-root',required=True)
    parser.add_argument('--env-file',default='.env.test')
    parser.add_argument('--stage-root',required=True)
    parser.add_argument('--project',default='ucasser-stage-reconcile')
    parser.add_argument('--port',type=int,default=18081)
    parser.add_argument('--ref',help='Full source-compatible commit SHA; defaults to current live commit')
    parser.add_argument('--create',action='store_true',help='Create the separate stage; without this flag only show a plan')
    args=parser.parse_args()
    if os.name!='nt':os.umask(0o022)
    try:Stage(args).create()
    except (OSError,ValueError,RuntimeError,subprocess.SubprocessError) as error:
        print(str(error),file=sys.stderr);return 1
    return 0


if __name__=='__main__':raise SystemExit(main())
