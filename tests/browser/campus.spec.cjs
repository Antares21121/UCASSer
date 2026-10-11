const {test,expect}=require('@playwright/test');

test('homepage, header, tag list and discussion use original Flarum components on desktop and mobile',async({page})=>{
  const errors=[];page.on('pageerror',e=>errors.push(e.message));page.on('console',e=>{if(e.type()==='error'&&/TypeError|ReferenceError|NotFoundError/.test(e.text()))errors.push(e.text());});
  await page.goto('/');
  await expect(page.locator('.IndexPage')).toHaveCount(1);
  await expect(page.locator('.WelcomeHero')).toContainText('校园公共论坛');
  await expect(page.locator('.DiscussionListItem').first()).toBeVisible();
  await expect(page.locator('.Campus-section')).toHaveCount(10);
  await expect(page.locator('.Campus-topbar')).toHaveCount(0);
  await expect(page.locator('.Header-primary').getByRole('link',{name:'首页',exact:true})).toBeVisible();
  await page.locator('.Campus-section').filter({hasText:'共享资料库'}).click();
  await expect(page).toHaveURL(/\/t\/campus-resources/);
  await expect(page.locator('.IndexPage .DiscussionList')).toHaveCount(1);
  await page.locator('.DiscussionListItem-main').first().click();
  await expect(page.locator('.DiscussionPage')).toHaveCount(1);
  await expect(page.locator('.PostStream')).toHaveCount(1);
  await expect(page.locator('.Campus-nativeRecord')).toContainText('版权或使用说明');
  for(const width of [320,390,768]){await page.setViewportSize({width,height:844});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBeTruthy();}
  await page.setViewportSize({width:390,height:844});
  await page.getByRole('button',{name:'打开导航菜单'}).click();
  await page.locator('.App-drawer').getByRole('link',{name:'首页',exact:true}).click();
  await expect(page.locator('.IndexPage .DiscussionListItem').first()).toBeVisible();
  expect(errors).toEqual([]);
});

test('native campus search stays usable without access to the course section',async({page})=>{
  let courseRequests=0;
  await page.route('**/api/campus/catalog',async route=>{const response=await route.fetch(),body=await response.json();body.data.sections=body.data.sections.filter(s=>s.key!=='courses');await route.fulfill({response,json:body});});
  await page.route('**/api/campus/courses*',route=>{courseRequests++;return route.fulfill({status:403,json:{errors:[]}});});
  await page.goto('/campus/search');
  await expect(page.getByRole('heading',{name:'详细检索',exact:true})).toBeVisible();
  await expect(page.locator('.DiscussionListItem').first()).toBeVisible();
  await page.getByLabel('内容类型',{exact:true}).selectOption('resource');
  await page.getByRole('button',{name:'查询',exact:true}).click();
  await expect(page.locator('.DiscussionListItem').first()).toHaveClass(/Campus-row--resources/);
  await page.getByRole('button',{name:'更改信息流排序',exact:true}).click();
  await page.locator('.IndexPage-toolbar').getByRole('button',{name:'最新发布',exact:true}).click();
  await expect(page.getByLabel('内容类型',{exact:true})).toHaveValue('resource');
  await expect(page.locator('.DiscussionListItem').first()).toHaveClass(/Campus-row--resources/);
  expect(courseRequests).toBe(0);
  await page.unrouteAll({behavior:'wait'});
});
