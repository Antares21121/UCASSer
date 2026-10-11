#!/usr/bin/env python3
"""Staged real database and HTTP acceptance checks for the campus extension."""
import argparse
import json
from campus_support import environment, ROOT, fixture, account, Api
from mail_sink import local_mail, mail_host
import secrets
import urllib.parse
import urllib.request
import http.cookiejar
import re
import io
import zipfile
from campus_records import phase3, phase4, phase5, phase6
from campus_participation import phase7
from campus_search import phase8
from campus_governance import phase9
from campus_security import phase10, phase11

def phase1(f, guest, admin):
    home = guest.call('/api/campus/home')['data']
    assert len(home['sections']) == 10
    assert home['latest'] and all(d['id'] and d['title'] for d in home['latest'])
    sections = admin.call('/api/campus/sections')['data']
    section = next(s for s in sections if s['key'] == 'chat')
    body = {k: section[k] for k in ('name', 'description', 'position', 'visibility')}
    body['position'] = int(body['position']); body['is_open'] = bool(section['is_open'])
    matches = admin.call('/api/discussions?filter[tag]=campus-chat')['data']
    discussion = int(matches[0]['id'])
    try:
        restricted = {**body, 'visibility': 'members'}
        admin.call('/api/campus/sections/' + str(section['id']), 'PATCH', restricted)
        assert not any(s['key'] == 'chat' for s in guest.call('/api/campus/home')['data']['sections'])
        guest.call('/api/discussions/' + str(discussion), expected=(403,404))
        results = guest.call('/api/discussions?filter[q]='+str(discussion))
        assert not any(d['id'] == str(discussion) for d in results.get('data', []))
        tag_results = guest.call('/api/tags')['data']
        assert not any(d['id'] == str(section['tag_id']) for d in tag_results)
        assert admin.call('/api/discussions/' + str(discussion))['data']['id'] == str(discussion)
    finally:
        admin.call('/api/campus/sections/' + str(section['id']), 'PATCH', body)
    guest.call('/api/campus/sections', expected=(401,403))
    assert guest.call('/api/campus/rules')['data']['rules']
    print('PASS phase 1: ten sections, real homepage, admin settings, direct URL/search/tag visibility and public rules')

def phase2(f, guest, admin):
    with local_mail(f) as mail:
        old = fixture(f, {'action':'mail','values':{'mail_driver':'smtp','mail_host':mail_host(f),'mail_port':str(mail.server_address[1]),'mail_encryption':'','mail_username':'','mail_password':'','mail_from':'forum@example.invalid'}})
        try:
            first, other = account(guest, admin, register=True), account(guest, admin, register=True)
            member, stranger = first['api'], other['api']
            uid = first['id']
            assert fixture(f, {'action':'inspect','user_id':uid,'password':first['password']})['password_hashed']
            tokens = fixture(f, {'action':'tokens','user_id':uid})
            assert tokens['email'] and len(mail.messages)>=2
            # Exercise the real emailed confirmation route, even though fixtures were activated by admin.
            Api(guest.url).call('/confirm/'+tokens['email'])
            payload={'consent':True,'evidence':'【示例】人工验证申请，仅用于开发验收'}
            guest.call('/api/campus/identity','POST',payload,expected=(401,403))
            member.call('/api/campus/identity','POST',{'consent':False,'evidence':'不应保存的验证说明'},expected=422)
            rid=member.call('/api/campus/identity','POST',payload,expected=201)['data']['id']
            member.call('/api/campus/identity','POST',payload,expected=422)
            stranger.call('/api/campus/identity/queue',expected=(401,403))
            member.call('/api/campus/identity/'+str(rid),'PATCH',{'status':'approved','reason':'自己审批应该拒绝'},expected=(401,403))
            own = member.call('/api/campus/identity')['data']
            assert not any('evidence' in row for row in own['requests'])
            admin.call('/api/campus/identity/'+str(rid),'PATCH',{'status':'approved','reason':'开发示例审核通过'})
            assert member.call('/api/campus/identity')['data']['verified']
            state=fixture(f,{'action':'inspect','user_id':uid,'password':first['password'],'target':str(rid)})
            assert all(r['evidence'] is None for r in state['verifications']) and 1 not in state['groups'] and 4 not in state['groups']
            member.call('/api/users/'+str(uid),'PATCH',{'data':{'type':'users','id':str(uid),'relationships':{'groups':{'data':[{'type':'groups','id':'1'}]}}}},expected=(403,422))
            sections=admin.call('/api/campus/sections')['data']; chat=next(s for s in sections if s['key']=='chat'); life=next(s for s in sections if s['key']=='life')
            discussion=int(admin.call('/api/discussions?filter[tag]=campus-chat')['data'][0]['id'])
            outside=int(admin.call('/api/discussions?filter[tag]=campus-life')['data'][0]['id'])
            admin.call('/api/campus/moderators','POST',{'user_id':uid,'section_id':chat['id'],'enabled':True,'reason':'开发示例分区授权'})
            member.call('/api/campus/moderate','POST',{'discussion_id':outside,'action':'hide','reason':'跨分区操作必须拒绝'},expected=(401,403))
            member.call('/api/campus/moderate','POST',{'discussion_id':discussion,'action':'hide','reason':'短'},expected=422)
            member.call('/api/campus/moderate','POST',{'discussion_id':discussion,'action':'hide','reason':'开发示例隐藏测试'})
            guest.call('/api/discussions/'+str(discussion),expected=(403,404))
            member.call('/api/campus/moderate','POST',{'discussion_id':discussion,'action':'restore','reason':'开发示例恢复测试'})
            guest.call('/api/discussions/'+str(discussion))
            admin.call('/api/campus/moderators','POST',{'user_id':uid,'section_id':chat['id'],'enabled':False,'reason':'开发示例权限交接撤销'})
            member.call('/api/campus/moderate','POST',{'discussion_id':discussion,'action':'hide','reason':'撤权后操作必须拒绝'},expected=(401,403))
            admin.call('/api/campus/identity/'+str(rid),'PATCH',{'status':'revoked','reason':'开发示例验证撤销'})
            assert not member.call('/api/campus/identity')['data']['verified']
            new_rid=member.call('/api/campus/identity','POST',payload,expected=201)['data']['id']
            admin.call('/api/campus/identity/'+str(new_rid),'PATCH',{'status':'rejected','reason':'开发示例申请拒绝'})
            member.call('/api/campus/audit',expected=(401,403))
            assert admin.call('/api/campus/audit')['data']
            # Native password reset with cookie session and CSRF, never log tokens or password.
            guest.call('/api/forgot','POST',{'email':first['name']+'@example.invalid'},expected=204)
            reset=fixture(f,{'action':'tokens','user_id':uid})['reset']; assert reset
            browser=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            html=browser.open(guest.url+'/reset/'+reset).read().decode()
            csrf=re.search(r'name="csrfToken" value="([^"]+)"',html)
            assert csrf, 'Reset form must carry a CSRF token'
            changed=secrets.token_urlsafe(24)
            body=urllib.parse.urlencode({'csrfToken':csrf[1],'passwordToken':reset,'password':changed,'password_confirmation':changed}).encode()
            browser.open(urllib.request.Request(guest.url+'/reset',data=body)).read()
            guest.call('/api/token','POST',{'identification':first['name'],'password':first['password']},expected=401)
            member=guest.login(first['name'],changed)
            # Official GDPR lifecycle plus campus data integration.
            member.call('/api/campus/discussions/'+str(discussion)+'/bookmark','POST',{'enabled':True})
            member.call('/api/campus/cases','POST',{'discussion_id':discussion,'target_type':'discussion','target_id':discussion,'reason':'【示例】个人清除验收所用的私有举报材料'},expected=201)
            stranger.call('/api/gdpr-exports','POST',{'data':{'type':'gdpr-exports','attributes':{'userId':uid}}},expected=(401,403))
            member.call('/api/gdpr-exports','POST',{'data':{'type':'gdpr-exports','attributes':{'userId':uid}}},expected=201)
            export=fixture(f,{'action':'exports','user_id':uid})[-1]
            guest.call('/gdpr/export/'+export['file'],expected=(401,403))
            guest.browser_login(other['name'],other['password']).call('/gdpr/export/'+export['file'],expected=(401,403))
            content=guest.browser_login(first['name'],changed).call('/gdpr/export/'+export['file'])
            with zipfile.ZipFile(io.BytesIO(content)) as archive:
                assert any(name.endswith('campus.json') for name in archive.namelist())
            erasure=member.call('/api/user-erasure-requests','POST',{'data':{'type':'user-erasure-requests','attributes':{'reason':'开发示例个人数据删除申请'}},'meta':{'password':changed}},expected=201)['data']
            token=fixture(f,{'action':'tokens','user_id':uid})['erasure']; assert token
            guest.call('/gdpr/erasure/confirm/'+token)
            stranger.call('/api/user-erasure-requests/'+erasure['id'],'PATCH',{'data':{'type':'user-erasure-requests','id':erasure['id'],'attributes':{'processedMode':'anonymization','processorComment':'未授权处理'}}},expected=(401,403,404))
            admin.call('/api/user-erasure-requests/'+erasure['id'],'PATCH',{'data':{'type':'user-erasure-requests','id':erasure['id'],'attributes':{'processedMode':'anonymization','processorComment':'开发示例按申请清除个人资料，保留公共讨论'}}})
            cleared=fixture(f,{'action':'inspect','user_id':uid,'password':changed})
            assert not cleared['verifications'] and cleared['private_rows']==0
            print('PASS phase 2: role isolation, private evidence erasure, revoke/resubmit, scoped moderation, hashed passwords, actual mail/confirmation/reset and GDPR export/erasure')
        finally:
            fixture(f, {'action':'mail','values':old})

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--backend', default='native', choices=['native','docker'])
    parser.add_argument('--phase', type=int, default=1)
    parser.add_argument('--from-phase',type=int,default=1)
    args = parser.parse_args()
    f, guest, admin = environment(args.backend)
    if args.from_phase<=1: phase1(f, guest, admin)
    if args.from_phase<=2<=args.phase: phase2(f, guest, admin)
    if args.from_phase<=3<=args.phase: phase3(f, guest, admin)
    if args.from_phase<=4<=args.phase: phase4(f, guest, admin)
    if args.from_phase<=5<=args.phase: phase5(f, guest, admin)
    if args.from_phase<=6<=args.phase: phase6(f, guest, admin)
    if args.from_phase<=7<=args.phase: phase7(f, guest, admin)
    if args.from_phase<=8<=args.phase: phase8(f, guest, admin)
    if args.from_phase<=9<=args.phase: phase9(f, guest, admin)
    if args.from_phase<=10<=args.phase: phase10(f, guest, admin)
    if args.from_phase<=11<=args.phase: phase11(f, guest, admin)
    (ROOT / '.runtime/campus-verification.json').write_text(json.dumps({'phases': list(range(args.from_phase,args.phase+1)), 'result': 'passed'}, indent=2))

if __name__ == '__main__': main()
