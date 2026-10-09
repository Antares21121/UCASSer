const {
  test,
  expect
} = require('@playwright/test');
const fs = require('fs'),
  crypto = require('crypto');
async function login(page, name, password) {
  await page.goto('/campus');
  await page.getByRole('button', {
    name: 'Log In',
    exact: true
  }).first().click();
  await page.locator('input[name="identification"]').fill(name);
  await page.locator('input[name="password"]').fill(password);
  await page.locator('.LogInModal').getByRole('button', {
    name: 'Log In',
    exact: true
  }).click();
  await expect(page.locator('.LogInModal')).toHaveCount(0);
}
test('identity approval, private report, appeal and independent review persist through actual browser forms', async ({
  page,
  browser,
  request
}) => {
  test.setTimeout(150000);
  const rootPassword = fs.readFileSync('.runtime/admin-initial-password.txt', 'utf8').trim();
  const tokenResponse = await request.post('/api/token', {
    data: {
      identification: 'phase0_admin',
      password: rootPassword
    }
  });
  expect(tokenResponse.status()).toBe(200);
  const rootToken = (await tokenResponse.json()).token;
  const api = async (path, method, data, token = rootToken) => {
    const r = await request.fetch('/api' + path, {
      method,
      data,
      headers: {
        Authorization: 'Token ' + token
      }
    });
    expect(r.status()).toBeLessThan(400);
    return r.json();
  };
  const make = async (admin = false) => {
    const name = 'browser_' + crypto.randomBytes(5).toString('hex'),
      password = crypto.randomBytes(24).toString('hex');
    const data = {
      type: 'users',
      attributes: {
        username: name,
        email: name + '@example.invalid',
        password,
        isEmailConfirmed: true
      }
    };
    const u = (await api('/users', 'POST', {
      data
    })).data;
    if (admin) await api('/users/' + u.id, 'PATCH', {
      data: {
        type: 'users',
        id: u.id,
        relationships: {
          groups: {
            data: [{
              type: 'groups',
              id: '1'
            }]
          }
        }
      }
    });
    const r = await request.post('/api/token', {
      data: {
        identification: name,
        password
      }
    });
    expect(r.status()).toBe(200);
    return {
      id: u.id,
      name,
      password,
      token: (await r.json()).token
    };
  };
  const owner = await make(),
    reporter = await make(),
    reviewer = await make(true);
  const marker = Date.now(),
    note = '【示例】浏览器私有举报 ' + marker;
  const section = (await api('/campus/catalog', 'GET')).data.sections.find(s => s.key === 'life');
  const discussion = (await api('/discussions', 'POST', {
    data: {
      type: 'discussions',
      attributes: {
        title: '【示例】浏览器举报上下文 ' + marker,
        content: '【示例】只用于实际举报、处理和独立复核的开发数据。'
      },
      relationships: {
        tags: {
          data: [{
            type: 'tags',
            id: String(section.tag_id)
          }]
        }
      }
    }
  }, owner.token)).data.id;
  const baseURL = process.env.FORUM_BROWSER_URL || 'http://127.0.0.1:8080';
  const reporterContext = await browser.newContext({
      baseURL
    }),
    reviewerContext = await browser.newContext({
      baseURL
    });
  const reporterPage = await reporterContext.newPage(),
    reviewerPage = await reviewerContext.newPage();
  const errors = [];
  for (const p of [page, reporterPage, reviewerPage]) {
    p.on('pageerror', e => errors.push(e.message));
    p.on('console', e => {
      if (e.type() === 'error' && /TypeError|ReferenceError/.test(e.text())) errors.push(e.text());
    });
  }
  try {
    await login(page, 'phase0_admin', rootPassword);
    await login(reporterPage, reporter.name, reporter.password);
    await reporterPage.goto('/campus/identity');
    await reporterPage.getByLabel('验证说明').fill('【示例】浏览器自愿验证 ' + marker);
    await reporterPage.getByRole('checkbox').check();
    await reporterPage.getByRole('button', {
      name: '提交人工验证',
      exact: true
    }).click();
    await expect(reporterPage.locator('.Campus')).toContainText('pending');
    await page.goto('/admin#/extension/ucasser-campus');
    const approval = page.locator('form').filter({
      hasText: '【示例】浏览器自愿验证 ' + marker
    });
    await approval.getByPlaceholder('审核理由（5–500 字）').fill('【示例】人工核验通过并清除原始材料');
    await approval.getByRole('button', {
      name: '处理申请',
      exact: true
    }).click();
    await expect(approval).toHaveCount(0);
    await reporterPage.reload();
    await expect(reporterPage.locator('.Campus')).toContainText('已通过人工审核');
    await reporterPage.goto('/campus/cases?discussion_id=' + discussion);
    await reporterPage.getByLabel('举报原因与必要证据').fill(note);
    await reporterPage.getByRole('button', {
      name: '提交举报',
      exact: true
    }).click();
    await expect(reporterPage.locator('article').filter({
      hasText: note
    })).toHaveCount(1);
    await page.goto('/campus/cases');
    let target = page.locator('article').filter({
      hasText: note
    });
    await target.getByLabel('公开给当事人的处理理由').fill('【示例】浏览器核验后临时隐藏');
    await target.locator('select').nth(1).selectOption('hide');
    await target.getByRole('button', {
      name: '记录并执行处置',
      exact: true
    }).click();
    await expect(target).toContainText('resolved');
    await reporterPage.reload();
    const own = reporterPage.locator('article').filter({
      hasText: note
    });
    await own.getByLabel('申诉说明').fill('【示例】请独立复核浏览器上下文');
    await own.getByRole('button', {
      name: '提交申诉',
      exact: true
    }).click();
    await expect(own).toContainText('appealed');
    await page.reload();
    target = page.locator('article').filter({
      hasText: note
    });
    await expect(target.getByRole('button', {
      name: '记录并执行处置',
      exact: true
    })).toHaveCount(0);
    await login(reviewerPage, reviewer.name, reviewer.password);
    await reviewerPage.goto('/campus/cases');
    const review = reviewerPage.locator('article').filter({
      hasText: note
    });
    await review.getByLabel('公开给当事人的处理理由').fill('【示例】独立复核后恢复浏览器内容');
    await review.locator('select').nth(1).selectOption('restore');
    await review.getByRole('button', {
      name: '记录并执行处置',
      exact: true
    }).click();
    await expect(review).toContainText('reviewed');
    await page.goto('/campus/governance');
    await page.getByLabel('公开标题').fill('【示例】浏览器维护说明 ' + marker);
    await page.getByLabel('公开正文').fill('【示例】公益运行，分区交接需撤销旧权限，不包含任何个人材料。');
    await page.getByLabel('生效时间（UTC）').fill('2026-01-01T00:00');
    await page.getByRole('button', {
      name: '发布并保留版本',
      exact: true
    }).click();
    await expect(page.getByRole('heading', {
      name: '【示例】浏览器维护说明 ' + marker,
      exact: true
    })).toBeVisible();
    await reporterPage.goto('/settings');
    await expect(reporterPage.locator('body')).toContainText('校园问答采纳与反馈结果');
    expect(errors).toEqual([]);
  } finally {
    await api('/users/' + reviewer.id, 'PATCH', {
      data: {
        type: 'users',
        id: reviewer.id,
        relationships: {
          groups: {
            data: []
          }
        }
      }
    });
    await reporterContext.close();
    await reviewerContext.close();
  }
});
