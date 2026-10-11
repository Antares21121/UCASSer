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
  await require('./helpers.cjs').login(page,'phase0_admin',fs.readFileSync('.runtime/admin-initial-password.txt','utf8').trim());
  await page.goto('/campus/publish?type=information');
  const title = '【示例】浏览器活动验收 ' + Date.now();
  await page.getByLabel('标题', {
    exact: true
  }).fill(title);
  await page.getByLabel('详细内容').fill('【示例】由浏览器发布的活动，真实保存到本地开发数据库。');
  await page.getByLabel('来源', {
    exact: true
  }).fill('【示例】开发维护者');
  await page.locator('#composer .item-preview button').click();
  await expect(page.locator('.Campus-nativePreview')).toContainText('由浏览器发布的活动');
  await page.getByRole('button', {
    name: '发布内容',
    exact: true
  }).click();
  await expect(page).toHaveURL(/\/d\//);
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
  await require('./helpers.cjs').reply(page,'【示例】浏览器评论，验证实际发布。');
  await page.getByRole('button', {
    name: '编辑内容或状态'
  }).click();
  await page.getByLabel('详细内容').fill('【示例】浏览器编辑后的活动说明，保留历史版本。');
  await page.getByLabel('修改理由').fill('【示例】更新浏览器验收说明');
  await page.getByRole('button', {
    name: '保存修改',
    exact: true
  }).click();
  await expect(page).toHaveURL(/\/d\//);
  await expect(page.locator('.Post-body').filter({hasText:'浏览器编辑后的活动说明'})).toHaveCount(1);
  await page.getByRole('button',{name:'纠错与反馈',exact:true}).click();
  await page.getByLabel('具体说明（仅本人和获授权人员可见）').fill('【示例】请补充公开活动来源。');
  await page.getByRole('button',{name:'提交反馈',exact:true}).click();
  await expect(page.locator('.Campus-nativeRecord')).toContainText('请补充公开活动来源');
  await page.locator('.Campus-nativeRecord summary').click();
  await page.getByLabel('处理说明',{exact:true}).fill('【示例】已核验并补充公开来源。');
  await page.getByRole('button',{name:'保存反馈处理',exact:true}).click();
  await expect(page.locator('.Campus-nativeRecord')).toContainText('已核验并补充公开来源');
  await page.getByRole('button',{name:'修改记录',exact:true}).click();
  await page.getByRole('button',{name:'加载修改记录',exact:true}).click();
  await expect(page.locator('.CampusRecordActionModal')).toContainText('更新浏览器验收说明');
  await page.locator('.CampusRecordActionModal').getByRole('button',{name:'关闭',exact:true}).click();
  await page.setViewportSize({
    width: 390,
    height: 844
  });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
  expect(errors).toEqual([]);
});
