"""Real HTTP security regression; no secrets enter the report."""
import json
import secrets
import urllib.request
import urllib.error
from datetime import datetime, timedelta, timezone
from campus_support import account, fixture
from mail_sink import local_mail, mail_host
from campus_records import information

def phase10(f, guest, admin):
    member=account(guest,admin)
    payload={'type':'maintenance','title':'【示例】治理验收 '+secrets.token_hex(4),'body':'【示例】公开维护说明：公益、无广告、无付费墙；分区授权交接必须撤销原权限。','effective_at':'2026-01-01T00:00'}
    member['api'].call('/api/campus/governance','POST',payload,expected=(401,403))
    created=admin.call('/api/campus/governance','POST',payload,expected=201)['data']['id']
    public=guest.call('/api/campus/governance')['data']
    assert any(e['id']==created for e in public['entries'])
    assert all('actor_id' not in e for e in public['entries'])
    assert not public['can_publish'] and public['statistics']['information']>0
    future=admin.call('/api/campus/governance','POST',{**payload,'effective_at':'2099-01-01T00:00'},expected=201)['data']['id']
    assert not any(e['id']==future for e in guest.call('/api/campus/governance')['data']['entries'])
    guest.call('/api/campus/governance/'+str(created),'PATCH',payload,expected=(401,403,404,405))
    assert all(set(t)=={'section','volunteers'} for t in public['team'])
    print('PASS phase 10: public immutable governance, effective-date versions, admin publication, aggregate privacy and actual content statistics')

def phase11(f, guest, admin):
    with local_mail(f) as mail:
        old=fixture(f,{'action':'mail','values':{'mail_driver':'smtp','mail_host':mail_host(f),'mail_port':str(mail.server_address[1]),'mail_encryption':'','mail_username':'','mail_password':'','mail_from':'forum@example.invalid'}})
        try: security_checks(f,guest,admin)
        finally: fixture(f,{'action':'mail','values':old})

def security_checks(f, guest, admin):
    guest.call('/api/campus/search?q[]=unexpected','GET',expected=422)
    guest.call('/api/campus/records?course_id[]=unexpected','GET',expected=422)
    first=account(guest,admin); member=first['api']
    section=next(s for s in admin.call('/api/campus/catalog')['data']['sections'] if s['key']=='life')
    payload=information(admin,section['id'])
    record=member.call('/api/campus/records','POST',payload,expected=201)['data']
    member.call('/api/campus/records','POST',payload,expected=422)
    native=member.call('/api/discussions/'+str(record['discussion_id']))
    post=next(p for p in native['included'] if p['type']=='posts' and p['attributes'].get('number')==1)
    member.call('/api/posts/'+post['id'],'PATCH',{'data':{'type':'posts','id':post['id'],'attributes':{'content':'【示例】使用原生编辑控件修改，历史版本仍须保留。'}}})
    changed=member.call('/api/campus/records/'+str(record['id']))['data']
    assert changed['version']==record['version']+1 and changed['history']
    assert member.call('/api/campus/records/'+str(record['id'])+'/revisions/1')['data']['body']==payload['body']
    # A real logged-in cookie without CSRF cannot mutate server state.
    browser=guest.browser_login(first['name'],first['password'])
    request=urllib.request.Request(guest.url+'/api/campus/records',data=json.dumps(payload).encode(),headers={'Content-Type':'application/json'},method='POST')
    try: browser.opener.open(request)
    except urllib.error.HTTPError as error: assert error.code in (400,403,419)
    else: raise AssertionError('Cookie write without CSRF was accepted')
    until=(datetime.now(timezone.utc)+timedelta(days=1)).isoformat()
    user='/api/users/'+str(first['id'])
    try:
        admin.call(user,'PATCH',{'data':{'type':'users','id':str(first['id']),'attributes':{'suspendedUntil':until,'suspendReason':'【示例】封禁接口验收','suspendMessage':'【示例】临时封禁'}}})
        member.call('/api/campus/records','POST',information(admin,section['id']),expected=(401,403))
        member.call('/api/campus/records/'+str(record['id'])+'/bookmark','POST',{'enabled':True},expected=(401,403))
        member.call('/api/discussions','POST',{'data':{'type':'discussions','attributes':{'title':'【示例】封禁拒绝','content':'【示例】原生讨论接口也必须拒绝'}}},expected=(401,403,422))
    finally:
        admin.call(user,'PATCH',{'data':{'type':'users','id':str(first['id']),'attributes':{'suspendedUntil':None}}})
    member.call('/api/campus/records/'+str(record['id'])+'/bookmark','POST',{'enabled':True})
    # Identity bucket cannot be bypassed by rotating client addresses or resource IDs.
    identification='missing_'+secrets.token_hex(8)
    for _ in range(20): guest.call('/api/token','POST',{'identification':identification,'password':'incorrect-development-password'},expected=401)
    guest.call('/api/token','POST',{'identification':identification,'password':'incorrect-development-password'},expected=429)
    member.call('/api/campus/records','POST',{**information(admin,section['id']),'source_url':'javascript:alert(1)'},expected=422)
    assert guest.call('/api/campus/catalog')['data']['anonymousReviews'] is False
    print('PASS phase 11: CSRF, suspended native/custom writers, unban, duplicate submission, per-identity rate limiting, unsafe URLs and anonymous-off policy')
