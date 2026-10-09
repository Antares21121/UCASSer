const {
  test,
  expect
} = require('@playwright/test');
test('campus homepage and mobile navigation read real content', async ({
  page
}) => {
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto('/campus');
  await expect(page.locator('h2', {
    hasText: '探索分区'
  })).toBeVisible();
  await expect(page.locator('.Campus-section')).toHaveCount(10);
  await expect(page.locator('.Campus-list').first()).toContainText('【示例】');
  await page.setViewportSize({
    width: 390,
    height: 844
  });
  await page.locator('.Campus-nav').getByRole('link', {
    name: '社区公约',
    exact: true
  }).click();
  await expect(page.getByRole('heading', {
    name: '社区公约与治理'
  })).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
  expect(errors).toEqual([]);
});
test('load failure provides a retry and empty data provides guidance', async ({
  page
}) => {
  await page.route('**/api/campus/home', route => route.fulfill({
    status: 503,
    contentType: 'application/json',
    body: '{}'
  }));
  await page.goto('/campus');
  await expect(page.locator('.Campus [role="alert"]')).toContainText('加载失败');
  await expect(page.getByRole('button', {
    name: '重新加载'
  })).toBeVisible();
  await page.unroute('**/api/campus/home');
  await page.route('**/api/campus/home', route => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({
      data: {
        title: '空数据测试',
        description: '',
        sections: [],
        latest: [],
        popular: [],
        questions: []
      }
    })
  }));
  await page.getByRole('button', {
    name: '重新加载'
  }).click();
  await expect(page.locator('.Campus-empty').first()).toContainText('暂无内容');
});
test('search remains usable when the course section is restricted', async ({
  page
}) => {
  let courseRequests = 0;
  await page.route('**/api/campus/catalog', async route => {
    const response = await route.fetch();
    const body = await response.json();
    body.data.sections = body.data.sections.filter(s => s.key !== 'courses');
    await route.fulfill({
      response,
      json: body
    });
  });
  await page.route('**/api/campus/courses', route => {
    courseRequests++;
    return route.fulfill({
      status: 403,
      json: {
        errors: []
      }
    });
  });
  await page.goto('/campus/search');
  await expect(page.getByRole('heading', {
    name: '全站搜索与发现',
    exact: true
  })).toBeVisible();
  await expect(page.locator('article').first()).toBeVisible();
  expect(courseRequests).toBe(0);
});
