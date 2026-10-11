const {test,expect}=require('@playwright/test');
const fs=require('fs');
const {login}=require('./helpers.cjs');
const adminLogin=page=>login(page,'phase0_admin',fs.readFileSync('.runtime/admin-initial-password.txt','utf8').trim());

test('guest access offers working native login, and the original mobile drawer stays within the viewport',async({page})=>{
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  const rejectedRequests=[];
  page.on('response',response=>{if(response.url().includes('/api/campus/') && [401,403].includes(response.status())) rejectedRequests.push(new URL(response.url()).pathname);});
  for(const path of ['me','identity','moderation','cases']){await page.goto('/campus/'+path);await expect(page.getByRole('button',{name:'登录账号',exact:true})).toBeVisible();await expect(page.locator('.Header-secondary')).toHaveCount(1);}
  await page.getByRole('button',{name:'登录账号',exact:true}).click();
  await expect(page.locator('.LogInModal')).toBeVisible();
  await page.locator('.LogInModal').getByRole('button',{name:'关闭',exact:true}).click();
  await expect(page.locator('.LogInModal')).toHaveCount(0);
  expect(rejectedRequests).toEqual([]);
  for(const width of [320,390]){await page.setViewportSize({width,height:844});await page.getByRole('button',{name:'打开导航菜单'}).click();await expect(page.locator('.App-drawer').getByRole('link',{name:'首页',exact:true})).toBeVisible();expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBeTruthy();await page.locator('.App-drawer').getByRole('link',{name:'首页',exact:true}).click();await expect(page.locator('.IndexPage')).toHaveCount(1);}
  expect(errors).toEqual([]);
});

test('native composer preserves input when course lookup fails and only retries on request',async({page})=>{
  await adminLogin(page);let count=0;
  await page.route('**/api/campus/courses?*',route=>{count++;return route.fulfill({status:503,json:{errors:[]}});});
  await page.goto('/campus/publish?type=review');
  await page.locator('#composer').getByLabel('标题',{exact:true}).fill('【示例】保留课程查找输入');
  await expect(page.locator('#composer [role=alert]')).toContainText('课程加载失败');
  await page.locator('#composer').getByLabel('详细内容',{exact:true}).fill('【示例】输入期间不能不断重复请求课程。');
  await page.locator('#composer .item-preview button').click();
  expect(count).toBe(1);
  await page.unroute('**/api/campus/courses?*');
  await page.getByRole('button',{name:'搜索可选课程',exact:true}).click();
  await expect(page.getByLabel('关联课程')).toContainText('示例');
  await expect(page.locator('#composer').getByLabel('标题',{exact:true})).toHaveValue('【示例】保留课程查找输入');
});

test('failed native publishing and replying keep drafts, and Beijing input persists as UTC',async({page,request})=>{
  await adminLogin(page);await page.goto('/campus/publish?type=information');
  const marker=Date.now(),title='【示例】原生界面重试 '+marker,body='【示例】接口暂时失败后应保留输入。';
  await page.locator('#composer').getByLabel('标题',{exact:true}).fill(title);
  await page.locator('#composer').getByLabel('详细内容',{exact:true}).fill(body);
  await page.getByLabel('来源',{exact:true}).fill('【示例】开发者公开说明');
  await page.locator('#composer summary').click();
  await page.getByLabel('开始时间（北京时间）',{exact:true}).fill('2026-11-01T10:30');
  await page.route('**/api/campus/records',route=>route.request().method()==='POST'?route.fulfill({status:503,json:{errors:[]}}):route.continue());
  await page.getByRole('button',{name:'发布内容',exact:true}).click();
  await expect(page.locator('#composer [role=alert]')).toContainText('保存失败');
  await expect(page.locator('#composer').getByLabel('详细内容',{exact:true})).toHaveValue(body);
  await page.unroute('**/api/campus/records');let submissions=0;
  await page.route('**/api/campus/records',async route=>{if(route.request().method()==='POST')submissions++;await route.continue();});
  await page.getByRole('button',{name:'发布内容',exact:true}).click();
  await expect(page).toHaveURL(/\/d\//);expect(submissions).toBe(1);
  const row=(await (await request.get('/api/campus/records?search='+encodeURIComponent(title))).json()).data.find(r=>r.title===title);
  expect(row.fields.starts_at.replace('T',' ')).toContain('2026-11-01 02:30');
  await page.locator('.DiscussionPage-nav').getByRole('button',{name:'回复',exact:true}).first().click();
  const comment='【示例】原生回复失败后保留 '+marker;
  await page.locator('#composer .TextEditor-editorContainer textarea').fill(comment);
  await page.route('**/api/posts',route=>route.request().method()==='POST'?route.fulfill({status:503,json:{errors:[]}}):route.continue());
  await Promise.all([page.waitForResponse(r=>r.url().endsWith('/api/posts')&&r.request().method()==='POST'&&r.status()===503),page.locator('#composer').getByRole('button',{name:'发布回复',exact:true}).click()]);
  await expect(page.locator('#composer .TextEditor-editorContainer textarea')).toHaveValue(comment);
  await expect(page.locator('#composer').getByRole('button',{name:'发布回复',exact:true})).toBeEnabled();
  await page.unroute('**/api/posts');
  const retry = await Promise.all([page.waitForResponse(r=>new URL(r.url()).pathname==='/api/posts' && r.request().method()==='POST'),page.locator('#composer').getByRole('button',{name:'发布回复',exact:true}).click()]);
  expect(retry[0].status(),JSON.stringify((await retry[0].json()).errors || [])).toBe(201);
  await expect(page.locator('.Post-body').filter({hasText:comment})).toHaveCount(1);
});
