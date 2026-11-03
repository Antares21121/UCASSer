#!/usr/bin/env python3
"""Deploy the audited workflow fixes after a fresh isolated rehearsal."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys

ALLOWED = {'compose.yaml', 'docker/php/Dockerfile', 'docker/php/Dockerfile.production', 'docker/web/Dockerfile.production'}
BASE = '839edd6065aed85fc6616ecd36b4267b784cee39'
TABLES = ['users', 'campus_operators', 'campus_colleges', 'campus_courses', 'campus_course_offerings',
          'ucasser_resource_categories', 'ucasser_resources', 'campus_aggregation_candidates', 'campus_aggregation_items']


def validate_report(report, head, target, project):
    if not (report.get('ready_for_review') and report.get('source_commit') == head
            and report.get('stage_commit') == target and report.get('source_project') == project
            and report.get('stage_project', '').startswith('ucasser-stage-')):
        raise ValueError('Fresh rehearsal does not match this source and target')


def deploy(args):
    from stage_instance import read_env, private_write
    root = Path(args.root).resolve()
    env_file = (root / args.env_file).resolve()
    if root == Path('/') or root.is_symlink() or env_file.parent != root:
        raise ValueError('Invalid source directory or environment file')
    if not re.fullmatch('[a-f0-9]{40}', args.target): raise ValueError('Target must be a full commit SHA')
    values = read_env(env_file)
    project = values['COMPOSE_PROJECT_NAME']
    if project != 'ucasser-test': raise ValueError('This repair release is for ucasser-test only')
    secrets = [v for k, v in values.items() if any(word in k for word in ('PASSWORD', 'SECRET', 'TOKEN')) and v]

    def run(command, *, data=None):
        r = subprocess.run(command, cwd=root, input=data, stdin=subprocess.DEVNULL if data is None else None,
                           stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if r.returncode:
            # Logs can contain credentials. Keep details private; print only operation names.
            text = (r.stdout + r.stderr).decode(errors='replace')
            for secret in secrets: text = text.replace(secret, '[REDACTED]')
            private_write(root / '.runtime/workflow-deploy-error.log', text)
            raise RuntimeError('Release step failed; private diagnostic saved to .runtime/workflow-deploy-error.log')
        return r.stdout

    git = ['git', '-c', 'safe.directory='+str(root)]
    dc = ['docker', 'compose', '--env-file', str(env_file), '-f', 'compose.yaml', '-p', project]
    os.environ['FORUM_ENV_FILE'] = str(env_file)
    head = run([*git, 'rev-parse', 'HEAD']).decode().strip()
    if run([*git, 'branch', '--show-current']).decode().strip() != '2.x' or head != BASE:
        raise ValueError('Live version changed; stop and reconcile before deploying')
    run([*git, 'merge-base', '--is-ancestor', head, args.target])
    run([*git, 'merge-base', '--is-ancestor', args.target, 'origin/2.x'])
    run([*git, 'diff', '--cached', '--quiet'])
    changed = set(run([*git, 'diff', 'HEAD', '--name-only']).decode().splitlines())
    if changed - ALLOWED: raise ValueError('Unexpected live source edits')
    if run([*git, 'diff', head, args.target, '--', 'compose.yaml', 'docker', 'composer.json', 'composer.lock', 'config', 'site.php']):
        raise ValueError('Infrastructure changed; this release procedure cannot continue')
    report_path = Path(args.report).resolve()
    report = json.loads(report_path.read_text())
    validate_report(report, head, args.target, project)
    stage = report_path.parent.parent
    if stage.parent != root.parent or stage.name != report['stage_project'] or stage.is_symlink():
        raise ValueError('Rehearsal directory identity mismatch')
    print('Version, server edits and fresh rehearsal verified', flush=True)
    if not args.apply:
        print('Preview OK; no source switch or live database change')
        return
    php = ['php', '-r', 'require "vendor/autoload.php"; $c=(require "site.php")->bootApp()->getContainer(); '
           '$db=$c->make(Illuminate\\Database\\ConnectionInterface::class); $v=[]; '
           'foreach ('+json.dumps(TABLES)+ ' as $t) $v[$t]=$db->table($t)->count(); echo json_encode($v);']
    credential = root / '.runtime/we-mp-rss.env'
    fingerprint = lambda: hashlib.sha256(credential.read_bytes()).hexdigest() if credential.exists() else None
    credentials_before = fingerprint()
    active = subprocess.run(['systemctl', 'is-active', '--quiet', 'ucasser-aggregation.timer'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0
    stopped = False
    try:
        run(['docker', 'compose', '--env-file', str(stage/'.env.stage'), '-f', str(stage/'compose.stage.json'), '-p', report['stage_project'], 'stop'])
        stopped = True
        run(['systemctl', 'stop', 'ucasser-aggregation.timer', 'ucasser-aggregation.service'])
        run([*dc, 'stop', 'runner', 'web'])
        before = json.loads(run([*dc, 'exec', '-T', '-u', 'www-data', 'app', *php]))
        backup = run([sys.executable, 'scripts/forum.py', '--env-file', str(env_file), 'backup']).decode()
        for line in backup.splitlines():
            if line.startswith('Backup: '): print(line, flush=True)
        stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')
        private_write(root/'.runtime'/('before-workflow-'+stamp+'.patch'), run([*git, 'diff', 'HEAD', '--binary']).decode())
        run([*git, 'merge', '--ff-only', args.target])
        run([*dc, 'restart', 'app'])
        run([*dc, 'exec', '-T', '-u', 'www-data', 'app', 'php', 'flarum', 'migrate'])
        run([*dc, 'exec', '-T', '-u', 'www-data', 'app', 'php', 'flarum', 'cache:clear'])
        after = json.loads(run([*dc, 'exec', '-T', '-u', 'www-data', 'app', *php]))
        if before != after or fingerprint() != credentials_before:
            raise RuntimeError('Post-deployment preservation check failed; backup retained')
        run([*dc, 'start', 'web', 'runner'])
        run([sys.executable, 'scripts/forum.py', '--env-file', str(env_file), 'healthcheck'])
        print(json.dumps({'deployed_commit': args.target, 'business_counts_preserved': True,
                          'collector_credentials_unchanged': True, 'counts': after,
                          'school_network_repaired': False, 'collector_authorization_changed': False}, ensure_ascii=False), flush=True)
        print('WORKFLOW DEPLOY OK; school network and HTTPS require separate verification', flush=True)
    finally:
        if stopped:
            resumed = subprocess.run([*dc, 'start', 'app', 'web', 'runner'], cwd=root, stdin=subprocess.DEVNULL).returncode == 0
            if active: resumed = subprocess.run(['systemctl', 'start', 'ucasser-aggregation.timer']).returncode == 0 and resumed
            if not resumed: raise RuntimeError('Service restart failed; inspect service state before continuing')


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--root', default='/opt/ucasser-test')
    p.add_argument('--env-file', default='.env.test')
    p.add_argument('--target', required=True)
    p.add_argument('--report', required=True)
    p.add_argument('--apply', action='store_true')
    args = p.parse_args()
    if os.name == 'nt' or os.geteuid() != 0: p.error('Run in the server owner root terminal')
    os.umask(0o022)
    try: deploy(args)
    except (OSError, ValueError, RuntimeError, subprocess.SubprocessError) as error:
        print(str(error), file=sys.stderr)
        print('DEPLOY STOPPED; backups retained. Do not repeat the update block.', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__': raise SystemExit(main())
