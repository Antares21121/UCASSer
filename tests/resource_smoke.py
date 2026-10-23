#!/usr/bin/env python3
"""Exercise the resource directory against a fresh, isolated development forum."""

import argparse
import json
import http.cookiejar
from pathlib import Path
import secrets
from datetime import datetime, timezone
import urllib.error
import urllib.parse
import urllib.request


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--url', required=True)
    parser.add_argument('--admin-user', required=True)
    parser.add_argument('--password-file', type=Path, required=True)
    args = parser.parse_args()
    base = args.url.rstrip('/')
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    csrf = None

    def call(path, method='GET', body=None, token=None, expected=200):
        nonlocal csrf
        data = json.dumps(body).encode() if body is not None else None
        headers = {'Content-Type': 'application/json'} if data is not None else {}
        if token:
            headers['Authorization'] = 'Token ' + token
        elif method != 'GET' and csrf:
            headers['X-CSRF-Token'] = csrf
        request = urllib.request.Request(base + path, data=data, headers=headers, method=method)
        try:
            with opener.open(request, timeout=30) as response:
                status, payload = response.status, response.read()
                csrf = response.headers.get('X-CSRF-Token', csrf)
        except urllib.error.HTTPError as error:
            status, payload = error.code, error.read()
        assert status == expected, f'{method} {path}: expected {expected}, got {status}: {payload[:500]!r}'
        return json.loads(payload) if payload else {}

    categories = call('/api/ucasser/categories')['categories']
    assert {item['name'] for item in categories if item['kind'] == 'root'} == {'校内资料', '校外资料'}
    assert {item['name'] for item in categories if item['kind'] == 'external_topic'} == {'四六级', '计算机二级'}
    initial = call('/api/ucasser/resources')['resources']
    assert {'https://cet.neea.edu.cn/', 'https://ncre.neea.edu.cn/'} <= {item['url'] for item in initial}

    campus = next(item for item in categories if item['name'] == '校内资料')
    outside_root = next(item for item in categories if item['name'] == '校外资料')
    assert bool(campus.get('is_campus')) and not bool(outside_root.get('is_campus')), 'Roots must carry explicit is_campus flags'
    call('/api/ucasser/categories', 'POST', {'parent_id': campus['id'], 'kind': 'college', 'name': '无权限测试'}, expected=403)

    password = args.password_file.read_text(encoding='utf-8').strip()
    token = call('/api/token', 'POST', {'identification': args.admin_user, 'password': password})['token']
    label = '验收学院-' + secrets.token_hex(3)
    college = call('/api/ucasser/categories', 'POST', {'parent_id': campus['id'], 'kind': 'college', 'name': label}, token, 201)['id']
    categories = call('/api/ucasser/categories')['categories']
    groups = [item for item in categories if item['parent_id'] == college]
    assert {item['name'] for item in groups} == {'必修课', '选修课'}
    mandatory = next(item for item in groups if item['name'] == '必修课')
    course = call('/api/ucasser/categories', 'POST', {'parent_id': mandatory['id'], 'kind': 'course', 'name': '验收课程'}, token, 201)['id']
    call('/api/ucasser/categories', 'POST', {'parent_id': mandatory['id'], 'kind': 'course', 'name': '验收课程'}, token, 422)  # duplicate name in the same parent
    call('/api/ucasser/categories', 'POST', {'parent_id': outside_root['id'], 'kind': 'college', 'name': '越界学院'}, token, 422)  # colleges only under the campus root
    call('/api/ucasser/categories/' + str(campus['id']), 'PATCH', {'name': '改名测试'}, token, 400)  # roots cannot be renamed

    resource = {'category_id': course, 'title': '验收资料', 'url': 'https://example.org/', 'description': '独立测试环境中的资料链接', 'source_url': 'https://example.org/', 'status': 'active'}
    call('/api/ucasser/resources', 'POST', resource | {'url': 'javascript:alert(1)'}, token, 422)
    call('/api/ucasser/resources', 'POST', resource | {'title': '\u3000'}, token, 422)  # invisible/full-width whitespace is not a title
    call('/api/ucasser/resources', 'POST', resource, expected=403)
    identifier = call('/api/ucasser/resources', 'POST', resource, token, 201)['id']
    call('/api/ucasser/resources/' + str(identifier), 'PATCH', resource, token, 422)
    changed = resource | {'title': '验收资料更新', 'url': 'https://example.org/updated', 'reason': '验收修订记录'}
    call('/api/ucasser/resources/' + str(identifier), 'PATCH', changed, token, 200)
    results = call('/api/ucasser/resources?category_id=' + str(course) + '&q=' + urllib.parse.quote('验收资料更新'))['resources']
    assert len(results) == 1 and results[0]['url'] == changed['url']
    call('/api/ucasser/resources?q=' + urllib.parse.quote('长' * 101), expected=422)  # over-long keyword must not silently disable filtering
    call('/api/ucasser/resources?q=' + urllib.parse.quote('\u3000'), expected=422)  # whitespace-only keyword is rejected too
    updated_at = datetime.strptime(results[0]['updated_at'], '%Y-%m-%d %H:%M:%S').replace(tzinfo=timezone.utc)
    assert abs((datetime.now(timezone.utc) - updated_at).total_seconds()) < 60, 'Resource timestamps must be UTC'
    root_results = call('/api/ucasser/resources?category_id=' + str(campus['id']) + '&q=' + urllib.parse.quote('验收资料更新'))
    assert root_results['total'] >= 1 and identifier in {item['id'] for item in root_results['resources']}, 'Root categories must include descendant courses'
    assisted = call('/api/ucasser/resources/assist?q=' + urllib.parse.quote('验收课程的资料'))['resources']
    assert identifier in {item['id'] for item in assisted}, 'Question search must use the course path as well as title and description'
    call('/api/ucasser/resources/assist', 'POST', {'q': '验收课程的资料'}, expected=403)
    call('/api/ucasser/resources/assist', 'POST', {'q': '验收课程的资料', 'provider': 'gemini', 'model': 'test-model'}, token, 422)
    call('/api/ucasser/resources/' + str(identifier) + '/revisions', expected=403)
    revisions = call('/api/ucasser/resources/' + str(identifier) + '/revisions', token=token)['revisions']
    assert revisions[0]['reason'] == changed['reason']
    assert revisions[0]['changes']['url'] == {'old': resource['url'], 'new': changed['url']}

    report = call('/api/ucasser/reports', 'POST', {'resource_id': identifier, 'reason': '验收失效反馈'}, token, 201)['id']
    reports = call('/api/ucasser/reports', token=token)['reports']
    assert report in {item['id'] for item in reports}
    call('/api/ucasser/reports/' + str(report), 'PATCH', {'status': 'resolved'}, token, 200)
    assert report not in {item['id'] for item in call('/api/ucasser/reports', token=token)['reports']}
    again = call('/api/ucasser/reports', 'POST', {'resource_id': identifier, 'reason': '再次失效'}, token, 201)['id']  # re-report after resolve stays allowed
    call('/api/ucasser/reports/' + str(again), 'PATCH', {'status': 'resolved'}, token, 200)
    call('/api/ucasser/reports/' + str(again), 'PATCH', {'status': 'resolved'}, token, 409)  # double resolve is a conflict, not 404

    print('PASS: public directory, categories, link creation and revision, access checks, search, report handling, and review regression gates')


if __name__ == '__main__':
    main()
