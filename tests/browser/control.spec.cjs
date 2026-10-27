const { test, expect } = require('@playwright/test');
const fs = require('fs');
const { login } = require('./helpers.cjs');

test('site control shows live work and safely pauses a supported source', async ({ page, request }) => {
  const guest = await request.get('/api/campus/control');
  expect([401, 403]).toContain(guest.status());
  const password = fs.readFileSync('.runtime/admin-initial-password.txt', 'utf8').trim();
  await login(page, 'phase0_admin', password);
  await page.goto('/campus/control');
  await expect(page.getByRole('heading', { name: '站点控制台' })).toBeVisible();
  await expect(page.getByRole('link', { name: /汇集信息审核/ })).toHaveAttribute('href', '/campus/aggregation/review');
  await expect(page.getByRole('link', { name: /校内身份审核/ })).toHaveAttribute('href', /\/admin#\/extension\/ucasser-campus\?tab=identity$/);
  await expect(page.locator('.Campus-controlWork').getByRole('link', { name: /举报与申诉/ })).toHaveAttribute('href', '/campus/cases?status=open');
  await expect(page.locator('.Campus-controlWork li')).toHaveCount(3);
  const source = page.locator('.Campus-controlSources li').filter({ hasText: '中国社会科学院大学教务处通知公告' });
  await expect(source).toBeVisible();
  await page.getByRole('button', { name: '暂停抓取' }).click();
  await expect(source).toContainText('已暂停');
  await page.reload();
  await expect(page.getByRole('button', { name: '恢复抓取' })).toBeVisible();
  await page.getByRole('button', { name: '恢复抓取' }).click();
  await expect(source).toContainText('已启用');
  for (const width of [390, 1280]) {
    await page.setViewportSize({ width, height: 800 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
  }
  await page.goto('/campus/cases?status=open');
  await expect(page.getByLabel('处理状态').first()).toHaveValue('open');
  await page.goto('/admin#/extension/ucasser-campus?tab=identity');
  await expect(page.getByRole('tab', { name: /身份审核/ })).toHaveClass(/Button--primary/);
  await expect(page.getByRole('heading', { name: '人工身份审核' })).toBeVisible();
});
