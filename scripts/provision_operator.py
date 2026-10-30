#!/usr/bin/env python3
"""Owner-run operator setup; password enters only through a private terminal prompt."""
import argparse
import getpass
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root',default=str(Path(__file__).resolve().parents[1]))
    parser.add_argument('--env-file',default='.env.test')
    parser.add_argument('--username',required=True)
    parser.add_argument('--email',required=True)
    parser.add_argument('--confirm-owner-email',action='store_true',help='Explicitly attest this is an email controlled by the server owner; recorded as manual owner confirmation')
    parser.add_argument('--apply',action='store_true')
    args=parser.parse_args()
    if not args.confirm_owner_email:
        parser.error('--confirm-owner-email is required; ordinary web registration still requires email verification')
    root=Path(args.root).resolve();env=root/args.env_file
    if not env.is_file() or not (root/'scripts/provision_operator.php').is_file():
        print('Deployment files or environment file missing; account was not changed.',file=sys.stderr);return 1
    command=['docker','compose','--env-file',str(env),'-f',str(root/'compose.yaml'),'exec','-T','-u','www-data','app','php','scripts/provision_operator.php']
    environment={**os.environ,'FORUM_ENV_FILE':str(env)}
    payload={'username':args.username,'email':args.email,'owner_email_confirmed':True,'password':secrets.token_urlsafe(32)}
    def execute(apply):
        result=subprocess.run([*command,*(['--apply'] if apply else [])],input=json.dumps(payload).encode(),
            cwd=root,env=environment,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if result.returncode:
            raise RuntimeError('Account identity, email ownership or deployment validation failed; no provisioning transaction committed. Password is not logged.')
        return json.loads(result.stdout)
    try:
        preview=execute(False)
        if not args.apply:print(json.dumps(preview));return 0
        payload['expected_create']=preview['created']
        if preview['created']:
            first=getpass.getpass('New operator password (at least 16 characters): ')
            second=getpass.getpass('Repeat password: ')
            if first!=second or len(first)<16:raise ValueError('Passwords must match and contain at least 16 characters')
            payload['password']=first
        result=execute(True)
        print(json.dumps(result))
        print('Operator account configured; administrator role not granted. Use HTTPS for browser login.')
    except (OSError,RuntimeError,ValueError,EOFError):
        print('Operator setup did not complete. Check identity conflicts and password entry. No password was printed.',file=sys.stderr);return 1
    return 0


if __name__=='__main__':raise SystemExit(main())
