const {
  test,
  expect
} = require('@playwright/test');
const fs = require('fs');
test('publish, preview, edit and comment on a real persisted information record', async ({
  page
}) => {
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
  await page.goto('/campus/publish?type=information');
  const title = '【示例】浏览器活动验收 ' + Date.now();
  await page.getByLabel('标题', {
    exact: true
  }).fill(title);
  await page.getByLabel('详细内容').fill('【示例】由浏览器发布的活动，真实保存到本地开发数据库。');
  await page.getByLabel('来源', {
    exact: true
  }).fill('【示例】开发维护者');
  await page.getByRole('button', {
    name: '预览正文',
    exact: true
  }).click();
  await expect(page.getByRole('heading', {
    name: title,
    exact: true
  })).toBeVisible();
  await page.getByRole('button', {
    name: '发布内容',
    exact: true
  }).click();
  await expect(page).toHaveURL(/\/campus\/record\/\d+$/);
  await expect(page.getByRole('heading', {
    name: title,
    exact: true
  })).toBeVisible();
  await page.getByRole('button', {
    name: '收藏内容',
    exact: true
  }).click();
  await expect(page.getByRole('button', {
    name: '取消收藏',
    exact: true
  })).toBeVisible();
  await page.getByLabel('评论', {
    exact: true
  }).fill('【示例】浏览器评论，验证实际发布。');
  await page.getByRole('button', {
    name: '发表评论',
    exact: true
  }).click();
  await expect(page.locator('article').filter({
    hasText: '【示例】浏览器评论，验证实际发布。'
  })).toHaveCount(1);
  await page.getByRole('link', {
    name: '编辑内容或状态'
  }).click();
  await page.getByLabel('详细内容').fill('【示例】浏览器编辑后的活动说明，保留历史版本。');
  await page.getByLabel('修改理由').fill('【示例】更新浏览器验收说明');
  await page.getByRole('button', {
    name: '保存修改',
    exact: true
  }).click();
  await expect(page).toHaveURL(/\/campus\/record\/\d+$/);
  await expect(page.locator('section').filter({
    hasText: '浏览器编辑后的活动说明'
  })).toHaveCount(1);
  await page.setViewportSize({
    width: 390,
    height: 844
  });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
  expect(errors).toEqual([]);
});
