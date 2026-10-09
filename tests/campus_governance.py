"""Real report, independent appeal and governance acceptance."""
import secrets
from campus_support import account
from campus_records import information

def phase9(f, guest, admin):
    owner,reporter,outsider,volunteer,reviewer=[account(guest,admin) for _ in range(5)]
    sections=admin.call('/api/campus/catalog')['data']['sections']; life=next(s for s in sections if s['key']=='life'); news=next(s for s in sections if s['key']=='news')
    r=owner['api'].call('/api/campus/records','POST',information(admin,life['id']),expected=201)['data']
    body={'discussion_id':r['discussion_id'],'target_type':'discussion','target_id':r['discussion_id'],'reason':'【示例】需要人工核验的信息，不能仅凭举报自动处罚'}
    guest.call('/api/campus/cases','POST',body,expected=(401,403))
    case=reporter['api'].call('/api/campus/cases','POST',body,expected=201)['data']; path='/api/campus/cases/'+str(case['id'])
    reporter['api'].call('/api/campus/cases','POST',body,expected=422)
    outsider['api'].call(path,expected=404); assert not owner['api'].call(path)['data']['reason']
    reporter['api'].call(path,'PATCH',{'version':case['version'],'status':'resolved','reason':'【示例】普通成员伪造处理'},expected=(401,403))
    admin.call('/api/campus/moderators','POST',{'user_id':volunteer['id'],'section_id':news['id'],'enabled':True,'reason':'【示例】错误分区授权测试'})
    volunteer['api'].call(path,expected=404)
    admin.call('/api/campus/moderators','POST',{'user_id':volunteer['id'],'section_id':life['id'],'enabled':True,'reason':'【示例】本分区举报处理授权'})
    processed=volunteer['api'].call(path,'PATCH',{'version':case['version'],'status':'needs_info','reason':'【示例】请补充可核验来源','action':'none'})['data']
    supplemented=reporter['api'].call(path+'/supplement','POST',{'version':processed['version'],'reason':'【示例】补充公开来源和可复核线索'})['data']
    done=volunteer['api'].call(path,'PATCH',{'version':supplemented['version'],'status':'resolved','reason':'【示例】先隐藏等待修订，保留历史讨论','action':'hide'})['data']
    guest.call('/api/campus/records/'+str(r['id']),expected=(403,404))
    appeal=owner['api'].call(path+'/appeal','POST',{'version':done['version'],'reason':'【示例】请求独立人员核对来源并恢复内容'})['data']
    volunteer['api'].call(path,'PATCH',{'version':appeal['version'],'status':'reviewed','reason':'【示例】原处理人不得最终复核','action':'restore'},expected=(401,403))
    reviewed=admin.call(path,'PATCH',{'version':appeal['version'],'status':'reviewed','reason':'【示例】独立复核后恢复内容','action':'restore'})['data']; assert reviewed['status']=='reviewed'
    assert guest.call('/api/campus/records/'+str(r['id']))['data']['id']==r['id']
    assert any(n['attributes']['contentType']=='campusAccount' for n in reporter['api'].call('/api/notifications')['data'])
    for section in [news,life]: admin.call('/api/campus/moderators','POST',{'user_id':volunteer['id'],'section_id':section['id'],'enabled':False,'reason':'【示例】验收结束撤销临时授权'})
    print('PASS phase 9: private reports and target context, duplicate prevention, scoped queue/processing, supplementary evidence, reasoned hide, independent appeal/review, restore and participant outcome notices')
