const { test, expect } = require('@playwright/test');

test('visitor home makes the four main tasks reachable on desktop and phone', async ({ page }) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('/campus');
  await expect(page.locator('.Campus-homeIdentity')).toContainText('非学校官方平台');
  // The hero image is meaningful content with a descriptive alt, not a
  // decorative aria-hidden ornament.
  await expect(page.locator('.Campus-homeArtwork img')).toHaveAttribute('alt', /书|笔记/);
  const paths = page.getByRole('navigation', { name: '校园常用入口' });
  await expect(paths.getByRole('link')).toHaveCount(5);
  for (const [name, href] of [
    ['查看校园信息', '/campus/aggregation'],
    ['寻找学习资料', '/resources'],
    ['浏览课程目录', '/campus/courses'],
    ['提出一个问题', '/t/campus-questions'],
    ['打开校园工具', '/campus/tools']
  ]) await expect(paths.getByRole('link', { name: new RegExp(name) })).toHaveAttribute('href', href);

  await expect(page.locator('.DiscussionListItem').first()).toBeVisible();
  for (const width of [1440, 390, 320]) {
    await page.setViewportSize({ width, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `home overflow at ${width}px`).toBeTruthy();
    await expect(paths.getByRole('link', { name: /打开校园工具/ })).toBeVisible();
  }

  await paths.getByRole('link', { name: /打开校园工具/ }).click();
  await expect(page).toHaveURL(/\/campus\/tools$/);
  await expect(page.getByRole('heading', { name: '校园工具箱' })).toBeVisible();
  await page.goto('/campus');
  await paths.getByRole('link', { name: /查看校园信息/ }).click();
  await expect(page).toHaveURL(/\/campus\/aggregation$/);
  await page.getByRole('button', { name: '登录后提交线索' }).click();
  await expect(page.locator('.LogInModal')).toBeVisible();
  expect(errors).toEqual([]);
});
