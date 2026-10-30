#!/usr/bin/env python3
"""Read-only whole-instance inventory; compare code, data domains and runtime configuration."""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
from urllib.parse import urlsplit

PHP = r'''<?php
function inventoryCourseLink(array $row, array $paths): array {
    $values=[];
    foreach (['code','name','college','semester'] as $key) {
        $values[$key]=$row[$key]===null?null:(string)$row[$key];
    }
    $values['category_path']=$paths[$row['resource_category_id']]??null;
    return $values;
}
try {
    $cfg = require getcwd().'/config/environment.php';
    $d = $cfg['database'];
    $pdo = new PDO('mysql:host='.$d['host'].';port='.$d['port'].';dbname='.$d['database'].';charset=utf8mb4', $d['username'], $d['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    $prefix=$d['prefix']; $q=chr(96);
    if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) throw new RuntimeException();
    $out=['tables'=>[], 'settings'=>[], 'other_setting_keys'=>[], 'content'=>[]];
    $schema=[];
    foreach ($pdo->query('SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION') as $row) {
        $name=$row['TABLE_NAME'];
        if (!str_starts_with($name,$prefix)) continue;
        $schema[substr($name,strlen($prefix))][]=array_intersect_key($row,array_flip(['COLUMN_NAME','COLUMN_TYPE','IS_NULLABLE','COLUMN_KEY']));
    }
    foreach ($schema as $table=>$columns) {
        if (!preg_match('/^[A-Za-z0-9_]+$/D',$table)) continue;
        $out['tables'][$table]=['count'=>(int)$pdo->query('SELECT COUNT(*) FROM '.$q.$prefix.$table.$q)->fetchColumn(),'schema'=>hash('sha256',json_encode($columns))];
    }
    $allowed=['forum_title','forum_description','welcome_title','welcome_message','default_route','default_locale','theme_primary_color','theme_secondary_color','theme_dark_mode','allow_sign_up','extensions_enabled','mail_driver','mail_port','mail_encryption','ucasser-campus.theme_initialized','ucasser-campus.redesign_initialized'];
    foreach ($pdo->query('SELECT '.$q.'key'.$q.','.$q.'value'.$q.' FROM '.$q.$prefix.'settings'.$q.' ORDER BY '.$q.'key'.$q) as $row) {
        if (in_array($row['key'],$allowed,true)) $out['settings'][$row['key']]=$row['value'];
        else $out['other_setting_keys'][$row['key']]=$row['value']!==null && $row['value']!=='';
    }
    // Only explicitly public catalog/configuration fields are fingerprinted.
    // No account rows, private messages, credentials or audit bodies are read.
    $domains=[
        'campus_colleges'=>['code','name','external_key','status','position','aliases'],
        'campus_courses'=>['name','type','grade','semester','nature','assessment','resources'],
        'campus_course_offerings'=>['code','name','college','semester','nature','description','external_key'],
        'ucasser_resource_categories'=>['kind','name','is_campus','position'],
        'ucasser_resources'=>['title','resource_type','status','external_key'],
        'campus_sections'=>['key','is_open','visibility'],
        'tags'=>['name','slug','description','color','icon','is_hidden','is_primary'],
        'campus_aggregation_sources'=>['name','adapter','trust','visibility','enabled'],
        'groups'=>['name_singular','name_plural','color','icon','is_hidden'],
        'group_permission'=>['group_id','permission'],
        'migrations'=>['migration','extension'],
    ];
    foreach ($domains as $table=>$wanted) {
        if (!isset($schema[$table])) continue;
        $cols=array_values(array_intersect($wanted,array_column($schema[$table],'COLUMN_NAME')));
        if (!$cols) continue;
        $hashes=[];
        foreach ($pdo->query('SELECT '.$q.implode($q.','.$q,$cols).$q.' FROM '.$q.$prefix.$table.$q) as $row) {
            $values=[];
            foreach ($cols as $key) $values[$key]=$row[$key]===null?null:(string)$row[$key];
            $hashes[]=hash('sha256',json_encode($values,JSON_UNESCAPED_UNICODE));
        }
        sort($hashes,SORT_STRING);
        $out['content'][$table]=['fields'=>$cols,'digest'=>hash('sha256',implode("\n",$hashes))];
    }
    if (isset($schema['ucasser_resource_categories'])) {
        $nodes=[];
        foreach ($pdo->query('SELECT id,parent_id,name,kind FROM '.$q.$prefix.'ucasser_resource_categories'.$q) as $row) $nodes[$row['id']]=$row;
        $paths=[];
        foreach ($nodes as $id=>$node) {
            $parts=[]; $seen=[]; $next=$id;
            while (isset($nodes[$next]) && !isset($seen[$next]) && count($seen)<32) {
                $seen[$next]=true; array_unshift($parts,$nodes[$next]['kind'].':'.$nodes[$next]['name']);
                $next=$nodes[$next]['parent_id'];
            }
            $paths[$id]=implode('/',$parts).($next!==null?'/(unresolved)':'');
        }
        $tree=array_values($paths); sort($tree,SORT_STRING);
        $out['content']['resource_category_tree']=['digest'=>hash('sha256',implode("\n",$tree))];
        if (isset($schema['campus_course_offerings']) && in_array('resource_category_id',array_column($schema['campus_course_offerings'],'COLUMN_NAME'),true)) {
            $links=[];
            foreach ($pdo->query('SELECT code,name,college,semester,resource_category_id FROM '.$q.$prefix.'campus_course_offerings'.$q) as $row) {
                // PDO's default FETCH_BOTH also includes numeric column keys.
                // Select semantic fields explicitly so category IDs never enter the digest.
                $links[]=hash('sha256',json_encode(inventoryCourseLink($row,$paths),JSON_UNESCAPED_UNICODE));
            }
            sort($links,SORT_STRING);
            $out['content']['course_resource_links']=['method'=>'semantic-v2','digest'=>hash('sha256',implode("\n",$links))];
        }
    }
    foreach (['campus_aggregation_items'=>['visibility','verification'],'campus_aggregation_candidates'=>['status','origin','visibility'],'campus_aggregation_sources'=>['adapter','enabled'],'campus_import_batches'=>['kind','status']] as $table=>$fields) {
        if (!isset($schema[$table])) continue;
        foreach ($fields as $field) {
            if (!in_array($field,array_column($schema[$table],'COLUMN_NAME'),true)) continue;
            $out['states'][$table][$field]=$pdo->query('SELECT '.$q.$field.$q.' AS state,COUNT(*) AS total FROM '.$q.$prefix.$table.$q.' GROUP BY '.$q.$field.$q)->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    $stmt=$pdo->prepare('SELECT id,is_email_confirmed FROM '.$q.$prefix.'users'.$q.' WHERE username=?');
    $stmt->execute(['preview_operator']);
    if (($user=$stmt->fetch(PDO::FETCH_ASSOC)) && isset($schema['campus_operators'])) {
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM '.$q.$prefix.'campus_operators'.$q.' WHERE user_id=?');
        $stmt->execute([$user['id']]);
        $out['operator_account']=['exists'=>true,'confirmed'=>(bool)$user['is_email_confirmed'],'operator'=>(bool)$stmt->fetchColumn()];
    } else $out['operator_account']=['exists'=>(bool)$user,'operator'=>false];
    $out['collector_credentials_readable']=is_readable(getcwd().'/.runtime/we-mp-rss.env');
    foreach (['public/assets/avatars','storage/campus-chat-images','storage/campus-post-images'] as $directory) {
        $hashes=[]; $bytes=0; $unreadable=0;
        if (is_dir($directory)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile() || $file->isLink() || $file->getFilename()==='.gitignore') continue;
                if (!is_readable($file->getPathname())) { $unreadable++; continue; }
                $hashes[]=hash_file('sha256',$file->getPathname()); $bytes+=$file->getSize();
            }
        }
        sort($hashes,SORT_STRING);
        $out['uploads'][$directory]=['files'=>count($hashes),'bytes'=>$bytes,'unreadable'=>$unreadable,'digest'=>hash('sha256',implode("\n",$hashes))];
    }
    $pdo->rollBack();
    echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    fwrite(STDERR,"Read-only database inventory failed (connection details withheld).\n");
    exit(1);
}
'''

COLLECTOR = r'''
from pathlib import Path
import sqlite3,json,hashlib
from urllib.parse import quote
root=Path('/app/data')
report={}
paths=[]
for pattern in ['*.db','*.sqlite','*.sqlite3','*/*.db','*/*.sqlite','*/*.sqlite3']:
    paths.extend(root.glob(pattern))
for path in sorted(set(paths))[:32]:
    if path.is_symlink(): continue
    try:
        db=sqlite3.connect('file:'+quote(str(path),safe='/')+'?mode=ro',uri=True,timeout=5)
        db.execute('PRAGMA query_only=ON')
        db.execute('BEGIN')
        tables={}
        for (name,) in db.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name").fetchall():
            if not name.replace('_','').isalnum(): continue
            tables[name]={'count':db.execute('SELECT COUNT(*) FROM "'+name+'"').fetchone()[0]}
            if name not in ('feeds','mps','articles'): continue
            allowed=['id','mp_id','mp_name','faker_id','title']
            columns=[row[1] for row in db.execute('PRAGMA table_info("'+name+'")')]
            selected=[x for x in allowed if x in columns]
            if not selected: continue
            rows=[]
            for row in db.execute('SELECT '+','.join('"'+x+'"' for x in selected)+' FROM "'+name+'"'):
                rows.append(hashlib.sha256(json.dumps(row,ensure_ascii=False).encode()).hexdigest())
            tables[name]['public_metadata_digest']=hashlib.sha256('\n'.join(sorted(rows)).encode()).hexdigest()
        db.rollback()
        db.close()
        report[str(path.relative_to(root))]=tables
    except sqlite3.Error:
        report[str(path.relative_to(root))]={'unavailable':True}
print(json.dumps({'sqlite_databases':report,'requires_review':not bool(report)},ensure_ascii=False))
'''

def run(command, root, *, data=None, env=None, timeout=70):
    options={'input':data} if data is not None else {'stdin':subprocess.DEVNULL}
    result=subprocess.run(command,cwd=root,stdout=subprocess.PIPE,stderr=subprocess.PIPE,
                          env=env,timeout=timeout,**options)
    if result.returncode:
        raise RuntimeError(f'{command[0]} inspection failed (exit {result.returncode}; credentials withheld)')
    return result.stdout

def digest_pairs(pairs):
    value=json.dumps(sorted(pairs),ensure_ascii=False,separators=(',',':')).encode()
    return hashlib.sha256(value).hexdigest()

def code_inventory(root):
    git=['git','-c',f'safe.directory={root}']
    paths=run([*git,'ls-files','-z'],root).decode().strip('\0').split('\0')
    groups,missing,excluded={},{},[]
    for name in paths:
        if not name:
            continue
        path=root/name
        sensitive=(name.startswith(('.runtime/','backups/'))
                   or (path.name.startswith('.env') and path.name!='.env.example')
                   or path.name in ('config.php','auth.json')
                   or path.suffix.lower() in ('.key','.pem','.pfx','.p12','.sql','.log'))
        if sensitive:
            excluded.append(name)
            continue
        if not path.is_file() or path.is_symlink():
            missing[name]='missing or symlink'
            continue
        content=path.read_bytes()
        try:
            content.decode('utf-8')
            content=content.replace(b'\r\n',b'\n')
        except UnicodeDecodeError:
            pass
        group='/'.join(name.split('/')[:3]) if name.startswith('extensions/') else name.split('/')[0]
        groups.setdefault(group,[]).append((name,hashlib.sha256(content).hexdigest()))
    return {
        'commit':run([*git,'rev-parse','HEAD'],root).decode().strip(),
        'branch':run([*git,'branch','--show-current'],root).decode().strip(),
        'tracked_files':len(paths),'missing_files':missing,'excluded_sensitive_paths':excluded,
        'modified_files':run([*git,'diff','HEAD','--name-only'],root).decode().splitlines(),
        'untracked_files':[name for name in run([*git,'ls-files','--others','--exclude-standard','-z'],root).decode().strip('\0').split('\0') if name and not name.startswith('.impeccable/')],
        'groups':{name:{'files':len(values),'digest':digest_pairs(values)} for name,values in sorted(groups.items())},
    }

def collect(args):
    root=Path(args.root).resolve()
    env_file=root/args.env_file
    environment={**os.environ,'FORUM_ENV_FILE':str(env_file)}
    compose=['docker','compose','--env-file',str(env_file),'-f',str(root/'compose.yaml')]
    database=json.loads(run([*compose,'exec','-T','-u','www-data','app','php'],root,data=PHP.encode(),env=environment))
    identity={}
    allowed={'APP_ENV','APP_URL','APP_DEBUG','FORUM_TITLE','COMPOSE_PROJECT_NAME','DB_NAME','DB_PREFIX','HTTP_BIND','HTTP_PORT','TZ','MP_RSS_URL','MP_RSS_PUBLIC_URL'}
    for line in env_file.read_text(encoding='utf-8-sig').splitlines():
        key,separator,value=line.partition('=')
        if separator and key.strip() in allowed:
            value=value.strip().strip('\'"')
            if key.strip().endswith('URL'):
                parsed=urlsplit(value)
                value=(parsed.scheme+'://'+parsed.netloc.rsplit('@',1)[-1]) if parsed.scheme and parsed.netloc else '(invalid URL)'
            identity[key.strip()]=value
    containers=run(['docker','ps','--format','{{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}'],root).decode().splitlines()
    schedules={}
    for unit in ['ucasser-aggregation.timer','ucasser-collection-worker.timer']:
        try:
            schedules[unit]=run(['systemctl','show',unit,'--property=LoadState,ActiveState,UnitFileState,NextElapseUSecRealtime'],root,timeout=8).decode().splitlines()
        except (FileNotFoundError,RuntimeError,subprocess.TimeoutExpired):
            schedules[unit]=['not available from this host']
    try:
        collector=json.loads(run(['docker','exec','-i','ucasser-we-mp-rss','python3','-'],root,data=COLLECTOR.encode(),timeout=25))
    except (RuntimeError,ValueError,subprocess.SubprocessError):
        collector={'requires_review':True,'reason':'Collector missing, unavailable or using a non-SQLite backend'}
    report={
        'format':1,'collected_utc':dt.datetime.now(dt.timezone.utc).isoformat(),
        'scope':'All tracked code, all DB tables/schema, allowlisted public content/settings, upload fingerprints, containers and timers. No passwords, sessions or private message bodies.',
        'identity':identity,'code':code_inventory(root),'database':database,
        'containers':containers,'schedules':schedules,'collector':collector,
    }
    payload=json.dumps(report,ensure_ascii=False,indent=2)
    if args.output:
        target=Path(args.output)
        target.parent.mkdir(parents=True,exist_ok=True)
        fd=os.open(target,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600)
        with os.fdopen(fd,'w',encoding='utf-8',newline='\n') as stream:
            stream.write(payload+'\n')
        target.chmod(0o600)
        print(f'Inventory saved: {target.resolve()}')
        print(f"Database tables: {len(database['tables'])}; tracked source files: {report['code']['tracked_files']}")
    else:
        print(payload)

def compare(args):
    a=json.loads(Path(args.baseline).read_text(encoding='utf-8-sig'))
    b=json.loads(Path(args.target).read_text(encoding='utf-8-sig'))
    differences={}
    for section in ['tables','settings','other_setting_keys','content','states','operator_account','collector_credentials_readable','uploads']:
        left,right=a['database'].get(section),b['database'].get(section)
        if left==right:
            continue
        if isinstance(left,dict) and isinstance(right,dict):
            differences[section]={key:{'baseline':left.get(key),'server':right.get(key)} for key in sorted(left.keys()|right.keys()) if left.get(key)!=right.get(key)}
        else:
            differences[section]={'baseline':left,'server':right}
    differences['code_groups']={key:{'baseline':a['code']['groups'].get(key),'server':b['code']['groups'].get(key)} for key in sorted(a['code']['groups'].keys()|b['code']['groups'].keys()) if a['code']['groups'].get(key)!=b['code']['groups'].get(key)}
    def extensions(report):
        try:
            return set(json.loads(report['database']['settings'].get('extensions_enabled','[]')))
        except (ValueError,TypeError):
            return set()
    differences['enabled_extensions']={'only_baseline':sorted(extensions(a)-extensions(b)),'only_server':sorted(extensions(b)-extensions(a))}
    if a.get('collector')!=b.get('collector'):
        differences['collector']={'baseline':a.get('collector'),'server':b.get('collector')}
    print(json.dumps({'baseline_commit':a['code']['commit'],'server_commit':b['code']['commit'],'differences':differences,'requires_review':'Different user/test/runtime data is not automatically a missing feature. Unmeasured fields remain review items.'},ensure_ascii=False,indent=2))

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    commands=parser.add_subparsers(dest='command',required=True)
    reader=commands.add_parser('collect')
    reader.add_argument('--root',default='.')
    reader.add_argument('--env-file',default='.env')
    reader.add_argument('--output')
    comparer=commands.add_parser('compare')
    comparer.add_argument('baseline')
    comparer.add_argument('target')
    args=parser.parse_args()
    try:
        (collect if args.command=='collect' else compare)(args)
    except (RuntimeError,OSError,ValueError,subprocess.SubprocessError):
        print('Inventory incomplete: check repository path, Docker access and env file; no update was performed.',file=sys.stderr)
        return 1
    return 0

if __name__=='__main__':
    raise SystemExit(main())
