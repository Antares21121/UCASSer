"""Real interaction and notification permission acceptance."""
import secrets
import io, json, zipfile
from campus_support import account, fixture
from campus_records import editable
from mail_sink import local_mail, mail_host

def phase7(f, guest, admin):
    with local_mail(f) as mail:
        old=fixture(f,{'action':'mail','values':{'mail_driver':'smtp','mail_host':mail_host(f),'mail_port':str(mail.server_address[1]),'mail_encryption':'','mail_username':'','mail_password':'','mail_from':'forum@example.invalid'}})
        try:
            author,reader=account(guest,admin),account(guest,admin); owner,other=author['api'],reader['api']
            owner.call('/api/users/'+str(author['id']),'PATCH',{'data':{'type':'users','id':str(author['id']),'attributes':{'preferences':{'notify_newPost_email':True}}}})
            sections=admin.call('/api/campus/catalog')['data']['sections']; section=next(s for s in sections if s['key']=='questions')
            q=owner.call('/api/campus/records','POST',{'type':'question','title':'【示例】通知与个人中心 '+secrets.token_hex(4),'body':'【示例】验证真实关注、回答和通知记录，不使用虚构统计。','category':'other','section_id':section['id']},expected=201)['data']; path='/api/campus/records/'+str(q['id'])
            guest.call('/api/campus/me',expected=(401,403))
            other.call(path+'/bookmark','POST',{'enabled':True,'following':True})
            assert other.call(path)['data']['following']
            other.call('/api/campus/discussions/'+str(q['discussion_id'])+'/bookmark','POST',{'enabled':True})
            assert other.call('/api/campus/me')['data']['discussions'][0]['id']==q['discussion_id']
            assert not owner.call('/api/campus/me')['data']['discussions']
            answered=other.call(path+'/comments','POST',{'body':'【示例】这是用于检验通知的回答。'})['data']; answer=answered['answers'][-1]
            notifications=owner.call('/api/notifications')['data']; reply=next(n for n in notifications if n['attributes']['contentType']=='newPost')
            assert mail.messages, 'Actual new-post mail should reach only the local sink'
            owner.call(path+'/accept','POST',{'post_id':answer['id']})
            notices=other.call('/api/notifications')['data']; accepted=next(n for n in notices if n['attributes']['contentType']=='campusUpdate')
            assert accepted['attributes']['content']['action']=='accepted'
            other.call('/api/notifications/'+accepted['id'],'PATCH',{'data':{'type':'notifications','id':accepted['id'],'attributes':{'isRead':True}}})
            assert other.call('/api/notifications/'+accepted['id'])['data']['attributes']['isRead']
            assert other.call('/api/campus/me')['data']['answers']
            original={k:section[k] for k in ['name','description','position','visibility']};original['is_open']=bool(section['is_open']);original['position']=int(section['position'])
            try:
                admin.call('/api/campus/sections/'+str(section['id']),'PATCH',{**original,'visibility':'verified'})
                owner.call('/api/notifications/'+reply['id'],expected=(403,404));other.call('/api/notifications/'+accepted['id'],expected=(403,404))
                assert not any(n['id']==accepted['id'] for n in other.call('/api/notifications')['data'])
                assert not other.call('/api/campus/me')['data']['bookmarks'] and not other.call('/api/campus/me')['data']['discussions']
                for person,client in [(author,owner),(reader,other)]:
                    client.call('/api/gdpr-exports','POST',{'data':{'type':'gdpr-exports','attributes':{'userId':person['id']}}},expected=201)
                    export=fixture(f,{'action':'exports','user_id':person['id']})[-1]
                    raw=guest.browser_login(person['name'],person['password']).call('/gdpr/export/'+export['file'])
                    with zipfile.ZipFile(io.BytesIO(raw)) as archive:
                        data=json.loads(archive.read(next(n for n in archive.namelist() if n.endswith('campus.json'))))
                    assert not data['campus_records'] and not data['campus_bookmarks'] and not data['campus_discussion_bookmarks']
                before=len(mail.messages)
                admin.call(path+'/comments','POST',{'body':'【示例】分区改为受限之后，通知不得向无权限用户发送正文。'})
                assert len(mail.messages)==before
            finally: admin.call('/api/campus/sections/'+str(section['id']),'PATCH',original)
            other.call(path+'/bookmark','POST',{'enabled':False,'following':False})
            assert not other.call(path)['data']['following']
            print('PASS phase 7: real bookmarks and follow state, own records/answers, reply mail, acceptance alerts, persistent read state, and permission filtering in list/direct notification/email/profile')
        finally: fixture(f,{'action':'mail','values':old})
