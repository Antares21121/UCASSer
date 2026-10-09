"""Real HTTP assertions; all credentials remain in ignored local files/memory."""
import argparse
import json
from pathlib import Path
import sys
import urllib.request
import urllib.error
import secrets
import http.cookiejar
import re

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
from forum import Forum

class Api:
    def __init__(self, url, token=None):
        self.url, self.token = url.rstrip('/'), token
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf = None
    def call(self, path, method='GET', data=None, expected=200):
        headers = {'Content-Type': 'application/json'}
        if self.token: headers['Authorization'] = 'Token ' + self.token
        elif method not in ('GET','HEAD'):
            if self.csrf is None:
                html = self.opener.open(self.url+'/campus').read().decode()
                self.csrf = re.search(r'"csrfToken":"([^"]+)"',html)[1]
            headers['X-CSRF-Token'] = self.csrf
        req = urllib.request.Request(self.url + path, data=json.dumps(data).encode() if data is not None else None, headers=headers, method=method)
        try:
            with self.opener.open(req, timeout=30) as response:
                status, body = response.status, response.read()
        except urllib.error.HTTPError as error: status, body = error.code, error.read()
        acceptable = expected if isinstance(expected, (list, tuple)) else [expected]
        assert status in acceptable, f'{method} {path}: expected {acceptable}, got {status}'
        try: return json.loads(body)
        except (json.JSONDecodeError, UnicodeDecodeError): return body
    def login(self, name, password):
        result = self.call('/api/token', 'POST', {'identification': name, 'password': password})
        return Api(self.url, result['token'])

    def browser_login(self, name, password):
        client = Api(self.url)
        client.call('/login','POST',{'identification':name,'password':password})
        return client

def fixture(f, data):
    directory = ROOT / '.runtime' / 'campus-test'
    directory.mkdir(parents=True, exist_ok=True)
    source, target = directory / 'input.json', directory / 'output.json'
    source.write_text(json.dumps(data), encoding='utf-8'); source.chmod(0o600)
    f.php('tests/campus_fixture.php', source.relative_to(ROOT).as_posix(), target.relative_to(ROOT).as_posix(), root=f.args.backend=='docker')
    return json.loads(target.read_text(encoding='utf-8'))

def account(guest, admin, prefix='campus', register=False):
    name = prefix + '_' + secrets.token_hex(4)
    password = secrets.token_urlsafe(24)
    attributes = {'username':name,'email':name+'@example.invalid','password':password}
    if not register: attributes['isEmailConfirmed']=True
    result = (guest if register else admin).call('/api/users', 'POST', {'data':{'type':'users','attributes':attributes}}, expected=201)
    uid = int(result['data']['id'])
    admin.call('/api/users/'+str(uid),'PATCH',{'data':{'type':'users','id':str(uid),'attributes':{'isEmailConfirmed':True}}})
    client=guest.login(name,password)
    client.call('/api/users/'+str(uid),'PATCH',{'data':{'type':'users','id':str(uid),'attributes':{'preferences':{'notify_newPost_email':False}}}})
    return {'id':uid,'name':name,'password':password,'api':client}

def environment(backend='native'):
    f = Forum(argparse.Namespace(backend=backend, profile='development', env_file=str(ROOT / '.env')))
    if f.values.get('APP_ENV') != 'development': raise RuntimeError('Business smoke is development-only')
    guest = Api(f.values['APP_URL'])
    admin = guest.login('phase0_admin', (ROOT / '.runtime/admin-initial-password.txt').read_text().strip())
    return f, guest, admin
