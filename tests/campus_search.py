"""Cross-content search and pagination acceptance against real database records."""
import urllib.parse
import secrets
from campus_records import information

def phase8(f, guest, admin):
    marker=secrets.token_hex(5); sections=admin.call('/api/campus/catalog')['data']['sections']; news=next(s for s in sections if s['key']=='news')
    # One bulk labelled fixture, saved via the exact publishing API used by users.
    for number in range(23):
        admin.call('/api/campus/records','POST',information(admin,news['id'],title=f'【示例】分页检索 {marker} {number:02d}',body=f'【示例】分页真实数据 {marker}，不得用前端假数据代替。'),expected=201)
    query=urllib.parse.urlencode({'q':marker,'type':'information','category':'activity','sort':'relevance'})
    page=guest.call('/api/campus/search?'+query); assert page['meta']['total']==23 and len(page['data'])==20
    second=guest.call('/api/campus/search?'+query+'&page=2'); assert len(second['data'])==3
    assert not set(r['id'] for r in page['data']) & set(r['id'] for r in second['data'])
    assert all(r['summary'] and r['record_id'] and r['updated_at'] for r in page['data'])
    assert not guest.call('/api/campus/search?'+urllib.parse.urlencode({'q':"' OR 1=1 --"}))['data']
    assert not guest.call('/api/campus/search?'+urllib.parse.urlencode({'q':'%_not_a_wildcard_'+marker}))['data']
    guest.call('/api/campus/search?from=2026-02-30',expected=422)
    assert guest.call('/api/campus/search?'+query+'&deadline_from=2020-01-01&starts_from=2020-01-01&sort=deadline')['data']
    assert guest.call('/api/campus/search?type=discussion')['data']
    assert guest.call('/api/campus/search?type=resource')['data']
    original={k:news[k] for k in ['name','description','position','visibility']};original['is_open']=bool(news['is_open']);original['position']=int(news['position'])
    try:
        admin.call('/api/campus/sections/'+str(news['id']),'PATCH',{**original,'visibility':'members'})
        assert not guest.call('/api/campus/search?'+query)['data']
        assert admin.call('/api/campus/search?'+query)['meta']['total']==23
    finally:admin.call('/api/campus/sections/'+str(news['id']),'PATCH',original)
    print('PASS phase 8: actual ordinary/structured content search, literal special characters, validated dates, activity/deadline filters, exact pagination totals and backend visibility')
