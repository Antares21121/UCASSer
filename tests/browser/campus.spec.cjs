const {test,expect}=require('@playwright/test');

test('homepage, header, tag list and discussion use original Flarum components on desktop and mobile',async({page})=>{
  const errors=[];page.on('pageerror',e=>errors.push(e.message));page.on('console',e=>{if(e.type()==='error'&&/TypeError|ReferenceError|NotFoundError/.test(e.text()))errors.push(e.text());});
  await page.context().addCookies([{name:'locale',value:'en',url:test.info().project.use.baseURL}]);
  await page.goto('/');
  await expect(page.locator('html')).toHaveAttribute('lang','zh-Hans');
  await expect(page.locator('.LocaleDropdown')).toHaveCount(0);
  await expect(page.locator('.IndexPage')).toHaveCount(1);
  await expect(page.locator('.WelcomeHero')).toContainText('校园公共论坛');
  await expect(page.locator('.DiscussionListItem').first()).toBeVisible();
  await expect(page.locator('.Navigation-back')).toHaveCount(0);
  await expect(page.locator('.Campus-section')).toHaveCount(10);
  await expect(page.locator('.Campus-topbar')).toHaveCount(0);
  await expect(page.locator('.Header-primary').getByRole('link',{name:'首页',exact:true})).toBeVisible();
  await page.locator('.Campus-section').filter({hasText:'共享资料库'}).click();
  await expect(page).toHaveURL(/\/t\/campus-resources/);
  await expect(page.locator('.IndexPage .DiscussionList')).toHaveCount(1);
  await page.locator('.DiscussionListItem-main').first().click();
  await expect(page.locator('.DiscussionPage')).toHaveCount(1);
  await expect(page.locator('.PostStream')).toHaveCount(1);
  await expect(page.locator('.Navigation-back').first()).toHaveAttribute('href',/\/t\/campus-resources/);
  await expect(page.locator('.Campus-nativeRecord')).toContainText('版权或使用说明');
  for(const width of [320,390,768]){await page.setViewportSize({width,height:844});expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBeTruthy();}
  await page.setViewportSize({width:390,height:844});
  await page.getByRole('button',{name:'打开导航菜单'}).click();
  await expect(page.locator('.App-drawer .LocaleDropdown')).toHaveCount(0);
  const controls=await page.locator('.App-header .Header-controls .Button').evaluateAll(nodes=>nodes.filter(n=>n.offsetWidth).map(n=>({font:getComputedStyle(n).fontSize,height:n.getBoundingClientRect().height,icon:!!n.querySelector('.Button-icon')})));
  expect(controls.every(control=>control.font==='14px'&&control.height>=44&&control.icon)).toBeTruthy();
  await page.locator('.App-drawer').getByRole('link',{name:'首页',exact:true}).click();
  await expect(page.locator('.IndexPage .DiscussionListItem').first()).toBeVisible();
  await expect(page.locator('.Navigation-back')).toHaveCount(0);
  await expect(page.getByRole('button',{name:'打开导航菜单'})).toBeVisible();
  await page.goto('/campus');
  await expect(page.locator('.Navigation-back')).toHaveCount(0);
  expect(errors).toEqual([]);
});

test('native campus search stays usable without access to the course section',async({page})=>{
  let courseRequests=0,catalogRequests=0;
  await page.route('**/api/campus/catalog',async route=>{catalogRequests++;const response=await route.fetch(),body=await response.json();body.data.sections=body.data.sections.filter(s=>s.key!=='courses');await route.fulfill({response,json:body});});
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
  expect(catalogRequests).toBe(1);
  await page.goto('/campus/records?type=information');
  await expect(page.locator('.CampusBoardHero')).toContainText('校园信息');
  await expect(page.locator('.Campus-pageHeader .Campus-eyebrow,.CampusBoardHero .Campus-boardEyebrow')).toHaveCount(0);
  await expect(page.locator('.Campus-nativeFilters .Campus-pageHeader')).toHaveCount(0);
  await expect(page.locator('.DiscussionListItem').first()).toBeVisible();
  for(const width of [320,475,768,1440]){
    await page.setViewportSize({width,height:651});
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),width+' reflow').toBeTruthy();
    if(width<768){
      const positions=await page.locator('.Campus-searchOptions .Campus-field').evaluateAll(nodes=>nodes.map(n=>n.getBoundingClientRect().top));
      expect(Math.abs(positions[0]-positions[1])).toBeLessThan(2);
      const bar=await page.locator('.Campus-searchBar').boundingBox(),options=await page.locator('.Campus-searchOptions').boundingBox();
      expect(bar.width).toBeGreaterThan(width-80);
      expect(options.y).toBeGreaterThanOrEqual(bar.y+bar.height);
      expect((await page.locator('.DiscussionListItem').first().boundingBox()).y).toBeLessThan(651);
    }
  }
  const title=await page.locator('.DiscussionListItem-title').first().innerText();
  await page.getByLabel('搜索标题、正文与标签',{exact:true}).fill(title);
  await page.getByRole('button',{name:'查询',exact:true}).click();
  await expect.poll(()=>page.locator('.DiscussionListItem-title').allTextContents()).toEqual([title]);
  await page.getByRole('link',{name:'清空筛选',exact:true}).click();
  await expect(page.getByLabel('搜索标题、正文与标签',{exact:true})).toHaveValue('');
  await page.locator('.Campus-searchAdvanced summary').click();
  await page.getByLabel('发布起始日期',{exact:true}).fill('2030-01-01');
  await page.getByRole('button',{name:'应用更多筛选',exact:true}).click();
  await expect(page).toHaveURL(/from=2030-01-01/);
  await expect(page.locator('.DiscussionListItem')).toHaveCount(0);
  await expect(page.locator('.Campus-searchAdvanced summary')).toContainText('1');
  await page.getByRole('link',{name:'清空筛选',exact:true}).click();
  await expect(page.locator('.DiscussionListItem').first()).toBeVisible();
  expect(courseRequests).toBe(0);
  await page.unrouteAll({behavior:'wait'});
});
