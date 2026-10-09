const {
  test,
  expect
} = require('@playwright/test');
const fs = require('fs');
test('course, normal-account review, resource, Q&A, search, personal center and governance work in the browser', async ({
  page
}) => {
  test.setTimeout(120000);
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto('/campus');
  await page.getByRole('button', {
    name: 'Log In',
    exact: true
  }).first().click();
  await page.locator('input[name="identification"]').fill('phase0_admin');
  await page.locator('input[name="password"]').fill(fs.readFileSync('.runtime/admin-initial-password.txt', 'utf8').trim());
  await page.locator('.LogInModal').getByRole('button', {
    name: 'Log In',
    exact: true
  }).click();
  await expect(page.locator('.LogInModal')).toHaveCount(0);
  const marker = Date.now();
  await page.goto('/campus/courses');
  await page.getByRole('button', {
    name: '添加课程',
    exact: true
  }).click();
  await page.getByLabel('课程名称', {
    exact: true
  }).fill('【示例】浏览器课程 ' + marker);
  await page.getByLabel('课程类型', {
    exact: true
  }).last().fill('示例通识');
  await page.getByLabel('开课学期', {
    exact: true
  }).last().fill('2026 秋 示例');
  await page.getByLabel('维护理由').fill('【示例】浏览器维护验收');
  await page.getByRole('button', {
    name: '保存课程',
    exact: true
  }).click();
  await expect(page.getByRole('heading', {
    name: '【示例】浏览器课程 ' + marker,
    exact: true
  })).toBeVisible();
  const course = page.locator('article').filter({
    has: page.getByRole('heading', {
      name: '【示例】浏览器课程 ' + marker,
      exact: true
    })
  });
  await course.getByRole('link', {
    name: '发布评价',
    exact: true
  }).click();
  await expect(page.getByLabel('关联课程')).toContainText('【示例】浏览器课程 ' + marker);
  await page.getByLabel('标题', {
    exact: true
  }).fill('【示例】浏览器评价 ' + marker);
  await page.getByLabel('详细内容').fill('【示例】正常账号发布的学习体验，评分不得冒充真实评价。');
  await page.getByRole('button', {
    name: '发布内容',
    exact: true
  }).click();
  await expect(page).toHaveURL(/\/campus\/record\/\d+$/);
  await expect(page.getByRole('heading', {
    name: '【示例】浏览器评价 ' + marker,
    exact: true
  })).toBeVisible();
  for (const type of ['resource', 'question']) {
    await page.goto('/campus/publish?type=' + type);
    await page.getByLabel('标题', {
      exact: true
    }).fill('【示例】浏览器 ' + type + ' ' + marker);
    await page.getByLabel('详细内容').fill('<script>window.campusUnsafe=true</script>【示例】纯文本显示，持久化发布验收。');
    if (type === 'resource') {
      await page.getByLabel('来源', {
        exact: true
      }).fill('【示例】作者公开示例');
      await page.getByLabel('原始链接', {
        exact: true
      }).fill('https://example.invalid/resource');
      await page.getByLabel('版权或使用说明', {
        exact: true
      }).fill('【示例】仅为开发验收的授权占位说明');
    }
    await page.getByRole('button', {
      name: '发布内容',
      exact: true
    }).click();
    await expect(page).toHaveURL(/\/campus\/record\/\d+$/);
    expect(await page.evaluate(() => window.campusUnsafe)).toBeUndefined();
    if (type === 'question') {
      await page.getByLabel('评论', {
        exact: true
      }).fill('【示例】浏览器答案，与正文保持关联。');
      await page.getByRole('button', {
        name: '发表评论',
        exact: true
      }).click();
      await page.getByRole('button', {
        name: '采纳此答案',
        exact: true
      }).click();
      await expect(page.getByText('已采纳答案', {
        exact: true
      })).toBeVisible();
    }
  }
  await page.goto('/campus/search?q=' + marker);
  await expect(page.locator('article')).toHaveCount(3);
  await page.goto('/campus/me');
  await expect(page.getByRole('heading', {
    name: '我的校园空间',
    exact: true
  })).toBeVisible();
  await page.goto('/campus/governance');
  await expect(page.getByRole('heading', {
    name: '公开治理与志愿维护',
    exact: true
  })).toBeVisible();
  await page.setViewportSize({
    width: 390,
    height: 844
  });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
  await page.goto('/campus/cases');
  await expect(page.locator('.Campus')).toContainText('举报');
  await page.goto('/admin#/extension/ucasser-campus');
  await expect(page.locator('body')).toContainText('校园分区设置');
  expect(errors).toEqual([]);
});
