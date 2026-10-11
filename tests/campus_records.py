"""Acceptance checks for persisted information, resources, Q&A and reviews."""
import datetime as dt
import secrets
import urllib.parse
from campus_support import account

def information(admin, section, **changes):
    stamp=secrets.token_hex(4)
    now=dt.datetime.now(dt.timezone.utc)
    return {'type':'information','title':'【示例】校园分享活动 '+stamp,'body':'这是一条开发验收示例，来源和内容不代表真实公告。','category':'activity','section_id':section,'status':'active','source':'【示例】开发验收发布者','source_url':'https://example.invalid/event','organizer':'示例组织','location':'示例场地','starts_at':(now+dt.timedelta(days=3)).strftime('%Y-%m-%dT%H:%M'),'ends_at':(now+dt.timedelta(days=3,hours=2)).strftime('%Y-%m-%dT%H:%M'),'deadline':(now+dt.timedelta(days=2)).strftime('%Y-%m-%dT%H:%M'),'tags':'开发示例,分享活动','featured':False,'related':[],**changes}

def editable(record, **changes):
    return {**record,**record['fields'],'status':record['stored_status'],'related':[r['id'] for r in record['fields']['related']],'reason':'【示例】开发验收更新内容',**changes}

def phase3(f, guest, admin):
    sections=admin.call('/api/campus/catalog')['data']['sections']; news=next(s for s in sections if s['key']=='news')
    owner, outsider=account(guest,admin),account(guest,admin)
    member, other=owner['api'],outsider['api']; body=information(admin,news['id'],deadline=(dt.datetime.now(dt.timezone.utc)+dt.timedelta(hours=1)).strftime('%Y-%m-%dT%H:%M'))
    guest.call('/api/campus/records','POST',body,expected=(401,403))
    member.call('/api/campus/records','POST',{**body,'source_url':'javascript:alert(1)'},expected=422)
    member.call('/api/campus/records','POST',{**body,'deadline':'2026-02-30T12:00'},expected=422)
    member.call('/api/campus/records','POST',{**body,'featured':True},expected=(401,403))
    record=member.call('/api/campus/records','POST',body,expected=201)['data']; rid=record['id']; path='/api/campus/records/'+str(rid)
    assert record['fields']['location']==body['location'] and record['body']==body['body']
    assert any(r['id']==rid for r in guest.call('/api/campus/home')['data']['upcoming'])
    query=urllib.parse.urlencode({'type':'information','category':'activity','search':body['title'][-8:]})
    assert guest.call('/api/campus/records?'+query)['data'][0]['id']==rid
    other.call(path,'PATCH',editable(record,title='【示例】越权修改不应该保存'),expected=(401,403))
    updated=member.call(path,'PATCH',editable(record,body='【示例】更新说明与最新原始来源',deadline='2020-01-01T00:00'))['data']
    assert updated['status']=='expired' and updated['body']=='【示例】更新说明与最新原始来源' and updated['version']==2 and updated['history'][0]['version']==1
    member.call(path,'PATCH',editable(record),expected=422)
    comments=other.call(path+'/comments','POST',{'body':'【示例】其他成员提出补充建议'})['data']['answers']; assert comments
    other.call(path+'/feedback','POST',{'kind':'source','message':'【示例】请再次确认活动原始来源'})
    assert not guest.call(path)['data']['feedback']
    assert not member.call(path)['data']['feedback']
    feedback=other.call(path)['data']['feedback'][0]
    other.call(path+'/feedback/resolve','POST',{'feedback_id':feedback['id'],'status':'resolved','reason':'【示例】无权处理他人反馈'},expected=(401,403))
    admin.call(path+'/feedback/resolve','POST',{'feedback_id':feedback['id'],'status':'resolved','reason':'【示例】管理员核验来源并回复'})
    assert other.call(path)['data']['feedback'][0]['status']=='resolved'
    original={k:news[k] for k in ['name','description','position','visibility']}; original['is_open']=bool(news['is_open']);original['position']=int(news['position'])
    try:
        admin.call('/api/campus/sections/'+str(news['id']),'PATCH',{**original,'visibility':'verified'})
        guest.call(path,expected=(403,404)); member.call(path,expected=(403,404))
        assert not guest.call('/api/campus/records?'+query)['data']
        member.call('/api/campus/records','POST',body,expected=(401,403))
    finally: admin.call('/api/campus/sections/'+str(news['id']),'PATCH',original)
    print('PASS phase 3: structured fields, real upcoming feed, source/search, owner editing, optimistic revision checks, expiry preservation, comments, private feedback and restricted APIs')

def phase4(f, guest, admin):
    section=next(s for s in admin.call('/api/campus/catalog')['data']['sections'] if s['key']=='resources')
    owner, reader=account(guest,admin),account(guest,admin); member, other=owner['api'],reader['api']
    body={'type':'resource','title':'【示例】公开学习指南 '+secrets.token_hex(4),'body':'【示例】合法公开资料索引，正文包含指南简介。','category':'guide','section_id':section['id'],'source':'【示例】作者授权资料','source_url':'https://example.invalid/guide','license':'【示例】仅用于开发验收，不托管或转载真实资料','course':'示例数学','topic':'校园办事','grade':'示例一年级','edition':'v1','last_verified':'2026-10-10T00:00','tags':'示例,指南','related':[]}
    member.call('/api/campus/records','POST',{**body,'license':''},expected=422)
    r=member.call('/api/campus/records','POST',body,expected=201)['data']; path='/api/campus/records/'+str(r['id'])
    guest.call(path+'/bookmark','POST',{'enabled':True},expected=(401,403))
    other.call(path+'/bookmark','POST',{'enabled':True,'following':False})
    assert other.call('/api/campus/records?type=resource&bookmarked=1')['data'][0]['id']==r['id']
    assert not member.call('/api/campus/records?type=resource&bookmarked=1')['data']
    query=urllib.parse.urlencode({'type':'resource','course':'示例数学','topic':'校园办事','grade':'示例一年级','search':body['title'][-8:]})
    assert guest.call('/api/campus/records?'+query)['data'][0]['id']==r['id']
    other.call(path,'PATCH',editable(r,edition='侵权修改'),expected=(401,403))
    updated=member.call(path,'PATCH',editable(r,edition='v2',alternate_url='https://example.invalid/guide-v2',status='needs_verification'))['data']
    history=member.call(path+'/revisions/1')['data']; assert history['fields']['edition']=='v1'
    guest.call(path+'/revisions/1',expected=(401,403))
    newer=member.call('/api/campus/records','POST',{**body,'title':body['title']+' 新版','related':[r['id']]},expected=201)['data']
    assert newer['fields']['related'][0]['id']==r['id']
    other.call(path+'/feedback','POST',{'kind':'invalid_link','message':'【示例】原始访问链接需要重新核验'})
    assert other.call(path)['data']['feedback'][0]['kind']=='invalid_link'
    other.call(path+'/bookmark','POST',{'enabled':False,'following':False})
    assert not other.call('/api/campus/records?type=resource&bookmarked=1')['data']
    categories=admin.call('/api/campus/categories')['data']; category=next(c for c in categories if c['type']=='resource' and c['key']=='guide')
    try:
        admin.call('/api/campus/categories','PATCH',{'type':'resource','key':'guide','name':category['name'],'enabled':False})
        member.call('/api/campus/records','POST',body,expected=422)
        assert guest.call(path)['data']['id']==r['id']
    finally: admin.call('/api/campus/categories','PATCH',{'type':'resource','key':'guide','name':category['name'],'enabled':True})
    print('PASS phase 4: licensed resource index, course/topic/grade/search filters, private bookmarks, owner permissions, revisions, version relations, verification state and invalid-link feedback')

def phase5(f, guest, admin):
    sections=admin.call('/api/campus/catalog')['data']['sections']; question=next(s for s in sections if s['key']=='questions'); resource=next(s for s in sections if s['key']=='resources')
    owner, answerer=account(guest,admin),account(guest,admin); member, other=owner['api'],answerer['api']
    body={'type':'question','title':'【示例】如何寻找校园指南 '+secrets.token_hex(4),'body':'【示例】希望了解公共办事指南的查询方法，请提供可靠出处。','category':'procedure','section_id':question['id'],'background':'【示例】已查阅公开信息，但希望补充建议。','tags':'示例,指南','related':[]}
    member.call('/api/campus/records','POST',{**body,'anonymous':True},expected=422)
    q=member.call('/api/campus/records','POST',body,expected=201)['data']; path='/api/campus/records/'+str(q['id']); assert q['status']=='waiting'
    answered=other.call(path+'/comments','POST',{'body':'【示例】可从公共资料索引按办事主题搜索，注意来源与核验日期。'})['data']
    assert answered['status']=='discussing'; post=answered['answers'][-1]
    other.call(path+'/accept','POST',{'post_id':post['id']},expected=(401,403))
    member.call(path+'/accept','POST',{'post_id':99999999},expected=404)
    resolved=member.call(path+'/accept','POST',{'post_id':post['id']})['data']; assert resolved['status']=='resolved' and resolved['accepted_post_id']==post['id']
    assert guest.call('/api/campus/records?'+urllib.parse.urlencode({'type':'question','status':'resolved','search':body['title'][-8:]}))['data'][0]['id']==q['id']
    assert member.call(path+'/accept','POST',{'post_id':None})['data']['status']=='discussing'
    member.call(path+'/accept','POST',{'post_id':post['id']})
    conversion={'post_id':post['id'],'section_id':resource['id'],'title':'【示例】问答整理后的指南 '+secrets.token_hex(4),'category':'faq','license':'【示例】验收文字由开发者提供并允许整理'}
    other.call(path+'/convert','POST',conversion,expected=(401,403))
    knowledge=admin.call(path+'/convert','POST',conversion,expected=201)['data']; assert knowledge['type']=='resource' and knowledge['fields']['related'][0]['id']==q['id'] and knowledge['body']==post['body']
    copy=member.call('/api/campus/records','POST',{**body,'title':body['title']+' 关联提问','related':[q['id'],knowledge['id']]},expected=201)['data']; assert len(copy['fields']['related'])==2
    print('PASS phase 5: question/background, actual answers, waiting/discussing/resolved, author-only accept/reopen, resolved search, duplicate/resource relations and permission-checked conversion')

def phase6(f, guest, admin):
    owner, reader=account(guest,admin),account(guest,admin); member, other=owner['api'],reader['api']
    course={'name':'【示例】数学导论 '+secrets.token_hex(4),'type':'基础课程','grade':'示例一年级','semester':'2026 秋','nature':'示例必修','assessment':'【示例】过程练习与讨论','resources':'【示例】公共资料索引','reason':'【示例】维护课程记录'}
    member.call('/api/campus/courses','POST',course,expected=(401,403)); c=admin.call('/api/campus/courses','POST',course,expected=201)['data']
    section=next(s for s in admin.call('/api/campus/catalog')['data']['sections'] if s['key']=='courses')
    body={'type':'review','title':'【示例】课程体验分享 '+secrets.token_hex(4),'body':'【示例】这是开发验收用的普通账号课程评价，并不代表真实教学评价。','category':'experience','section_id':section['id'],'course_id':c['id'],'semester':'2026 秋','overall':4,'difficulty':3,'workload':2,'assessment':'【示例】过程考核','gains':'【示例】学习方法','advice':'【示例】提前预习','related':[]}
    member.call('/api/campus/records','POST',{**body,'anonymous':True},expected=422)
    member.call('/api/campus/records','POST',{**body,'overall':6},expected=422)
    r=member.call('/api/campus/records','POST',body,expected=201)['data']; path='/api/campus/records/'+str(r['id'])
    assert r['author_id']==owner['id'] and r['fields']['overall']==4
    member.call('/api/campus/records','POST',body,expected=422)
    second=other.call('/api/campus/records','POST',{**body,'title':body['title']+' 第二位成员','overall':2,'difficulty':5,'workload':4},expected=201)['data']
    stats=guest.call('/api/campus/courses?id='+str(c['id'])+'&review_semester='+urllib.parse.quote('2026 秋'))['data'][0]
    assert stats['samples']==2 and stats['scores']=={'overall':3,'difficulty':4,'workload':3}
    empty=guest.call('/api/campus/courses?id='+str(c['id'])+'&review_semester='+urllib.parse.quote('2025 秋'))['data'][0]; assert empty['samples']==0 and all(v is None for v in empty['scores'].values())
    member.call(path+'/useful','POST',{'enabled':True},expected=(401,403)); other.call(path+'/useful','POST',{'enabled':True}); other.call(path+'/useful','POST',{'enabled':True}); assert guest.call(path)['data']['useful']==1
    other.call(path+'/useful','POST',{'enabled':False}); assert guest.call(path)['data']['useful']==0
    updated=member.call(path,'PATCH',editable(r,overall=5))['data']; assert updated['version']==2
    withdrawn=member.call(path,'PATCH',editable(updated,status='withdrawn'))['data']; assert withdrawn['status']=='withdrawn'
    guest.call(path,expected=(403,404)); guest.call('/api/discussions/'+str(r['discussion_id']),expected=(403,404))
    after=guest.call('/api/campus/courses?id='+str(c['id']))['data'][0]; assert after['samples']==1 and after['scores']['overall']==2
    restored=member.call(path,'PATCH',editable(withdrawn,status='active'))['data']; assert restored['status']=='active'
    after=guest.call('/api/campus/courses?id='+str(c['id']))['data'][0]; assert after['samples']==2 and after['scores']['overall']==3.5
    filters=urllib.parse.urlencode({'type':'review','course_id':c['id'],'semester':'2026 秋'}); assert len(guest.call('/api/campus/records?'+filters)['data'])==2
    print('PASS phase 6: course linkage, semester filters, score validation and definitions, exact sample/averages, duplicate prevention, one useful vote, edit/withdraw/restore, preserved history and server-side rejection of anonymity')
