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
  await page.goto('/campus/control?tab=review&page=2');
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

test('slow collector cannot block the workspace and queued results update automatically', async ({page}) => {
  await login(page,'phase0_admin',fs.readFileSync('.runtime/admin-initial-password.txt','utf8').trim());
  let dashboard, status='queued', submitted=0;
  await page.route('**/api/campus/control/collector',async route=>{
    await new Promise(resolve=>setTimeout(resolve,1800));
    await route.fulfill({json:{data:{state:'unavailable',message:'验收：服务暂不可用',accounts:[],subscribed_total:0}}});
  });
  await page.route('**/api/campus/control',async route=>{
    if(!dashboard) dashboard=await (await route.fetch()).json();
    const data={...dashboard.data,collection_job:submitted?{id:987654,status,created_at:new Date().toISOString().slice(0,19).replace('T',' '),result:status==='partial'?'官网连接失败；公众号已处理，文章进入待审。':null}:null};
    await route.fulfill({json:{data}});
  });
  await page.route('**/api/campus/control/collect',async route=>{
    submitted++;
    await route.fulfill({status:202,json:{data:{id:987654,status:'queued'}}});
  });
  await page.goto('/campus/control');
  await expect(page.getByRole('button',{name:'一键采集',exact:true})).toBeVisible({timeout:1500});
  await expect(page.locator('.Campus-controlCollector')).toContainText('服务暂不可用');
  await page.getByRole('button',{name:'一键采集',exact:true}).click();
  await expect(page.locator('.Campus-controlJob')).toContainText('等待执行');
  await expect(page.getByRole('button',{name:'一键采集',exact:true})).toBeDisabled();
  status='partial';
  await expect(page.locator('.Campus-controlJob')).toContainText('部分来源失败');
  await expect(page.locator('.Campus-controlJob')).toContainText('公众号已处理');
  await expect(page.getByRole('button',{name:'一键采集',exact:true})).toBeEnabled();
  expect(submitted).toBe(1);
});
