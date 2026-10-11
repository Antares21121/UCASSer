const {test,expect}=require('@playwright/test');
const fs=require('fs');
const {login}=require('./helpers.cjs');

test('all campus boards retain native navigation, readable streams and Chinese controls on phones',async({page})=>{
  test.setTimeout(120000);
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  for(const key of ['news','careers','academic','life','market','resources','courses','questions','chat','governance']) {
    await page.goto('/t/campus-'+key);
    await expect(page.locator('.CampusBoardHero h1')).toBeVisible();
    await expect(page.locator('.IndexPage .DiscussionList')).toBeVisible();
    await expect(page.getByRole('button',{name:'更改信息流排序',exact:true})).toBeVisible();
    for(const width of [320,390]) {
      await page.setViewportSize({width,height:844});
      expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'overflow: '+key+' at '+width).toBeTruthy();
    }
    await page.setViewportSize({width:1440,height:1000});
  }
  await page.goto('/t/campus-resources');
  // Hard navigation uses server-preloaded data, so first-post excerpts must
  // survive refresh rather than appearing only after a client-side visit.
  await expect(page.locator('.Campus-feedExcerpt').first()).toBeVisible();
  await page.reload();
  await expect(page.locator('.Campus-feedExcerpt').first()).toBeVisible();
  await page.locator('.Campus-boardTabs').getByRole('link',{name:'学习笔记',exact:true}).click();
  await expect(page.locator('.Campus-boardTabs .is-active')).toHaveText('学习笔记');
  await page.getByRole('button',{name:'更改信息流排序',exact:true}).click();
  await page.locator('.IndexPage-toolbar').getByRole('button',{name:'最新发布',exact:true}).click();
  await expect(page.locator('.Campus-boardTabs .is-active')).toHaveText('学习笔记');
  await page.getByRole('button',{name:'切换主题',exact:true}).click();
  await page.getByRole('button',{name:'深色',exact:true}).click();
  await expect(page.locator('.CampusBoardHero h1')).toBeVisible();
  expect(errors).toEqual([]);
});

test('publishing from an opportunity board carries its section and category into the native composer',async({page,request})=>{
  await login(page,'phase0_admin',fs.readFileSync('.runtime/admin-initial-password.txt','utf8').trim());
  await page.goto('/t/campus-careers');
  const sections=(await (await request.get('/api/campus/catalog')).json()).data.sections;
  const section=sections.find(s=>s.key==='careers');
  await page.locator('.Campus-boardActions').getByRole('button',{name:'分享新机会',exact:true}).click();
  await expect(page.locator('#composer').getByLabel('分区',{exact:true})).toHaveValue(String(section.id));
  await expect(page.locator('#composer').getByLabel('分类',{exact:true})).toHaveValue('internship');
  await expect(page.locator('#composer').getByLabel('详细内容',{exact:true})).toBeVisible();
  await expect(page.locator('#composer .item-preview button')).toHaveAttribute('aria-label','预览');
  await expect(page.locator('#composer').getByRole('button',{name:'发布内容',exact:true})).toBeVisible();
});
