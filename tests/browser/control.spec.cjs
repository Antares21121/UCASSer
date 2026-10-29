const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const { login } = require('./helpers.cjs');

function operatorRole(revoke = false) {
  execFileSync('docker', ['compose', 'exec', '-T', '-u', 'www-data', 'app',
    'php', 'flarum', 'campus:operator', 'phase0_admin', ...(revoke ? ['--revoke'] : [])],
  { stdio: 'pipe' });
}

test.beforeAll(() => operatorRole());
test.afterAll(() => operatorRole(true));

test('operations backend keeps daily work in one responsive workspace', async ({ page, request }) => {
  const guest = await request.get('/api/campus/control');
  expect([401, 403]).toContain(guest.status());
  const password = fs.readFileSync('.runtime/admin-initial-password.txt', 'utf8').trim();
  await login(page, 'phase0_admin', password);
  await page.locator('a[href="/campus/control"]').first().click();
  await expect(page.getByRole('heading', { name: '运营后台' })).toBeVisible();
  await expect(page.getByRole('button', { name: /一键采集/ })).toBeVisible();
  await page.locator('.Campus-controlTabs a[href*="tab=review"]').click();
  await expect(page.getByRole('heading', { name: '信息审核' })).toBeVisible();
  await page.locator('.Campus-controlTabs a[href*="tab=sources"]').click();
  await expect(page.getByRole('heading', { name: '来源管理' })).toBeVisible();
  await expect(page.getByRole('button', { name: '登记来源' })).toBeVisible();
  await page.locator('.Campus-controlTabs a[href*="tab=identity"]').click();
  await expect(page.getByRole('heading', { name: '校园身份审核' })).toBeVisible();
  await page.locator('.Campus-controlTabs a[href*="tab=cases"]').click();
  await expect(page.getByRole('heading', { name: '举报与申诉' })).toBeVisible();
  for (const width of [390, 1280]) {
    await page.setViewportSize({ width, height: 800 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
  }
  expect(await page.locator('.Campus-control').evaluate(element => element.getBoundingClientRect().width)).toBeGreaterThan(1000);
});
