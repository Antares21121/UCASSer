const {test,expect}=require('@playwright/test');
const fs=require('fs');
const {login}=require('./helpers.cjs');

test('board details show relevant persisted fields and keep conversation posts native',async({page,request})=>{
  test.setTimeout(180000);
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const marker=Date.now();
  const tokenResponse=await request.post('/api/token',{data:{identification:'phase0_admin',password:fs.readFileSync('.runtime/admin-initial-password.txt','utf8').trim()}});
  expect(tokenResponse.ok()).toBeTruthy();
  const token=(await tokenResponse.json()).token;
  const create=async(path,data)=>{
    const response=await request.post('/api/campus/'+path,{headers:{Authorization:'Token '+token},data});
    expect(response.status(),await response.text()).toBe(201);
    return (await response.json()).data;
  };
  const sections=(await (await request.get('/api/campus/catalog')).json()).data.sections;
  const course=await create('courses',{name:'【示例】详情验收课程 '+marker,type:'基础课程',semester:'2026 秋',reason:'【示例】分区详情浏览器验收'});
  const fixtures=[];
  const scenarios=[['news','information','activity','活动日程'],['careers','information','internship','申请截止'],['academic','information','lecture','参与准备'],['life','information','help','需要什么帮助'],['market','information','market','交接安排'],['market','information','travel','行程时间'],['resources','resource','notes','版权或使用说明'],['courses','review','experience','学习收获与建议'],['questions','question','study','等待回答'],['chat','information','other',null],['governance','information','other','发布与依据']];
  for(const [key,type,category,caption] of scenarios){
    const title='【示例】详情布局验收 '+key+'-'+category+' '+marker;
    const record=await create('records',{type,category,section_id:sections.find(s=>s.key===key).id,title,body:'【示例】'+key+' 板块的原生正文，应当可以直接阅读和回复。',source:'【示例】本人原创的公开说明',source_url:'https://example.invalid/source',license:'【示例】作者允许分享，仅用于布局验收',course_id:course.id,semester:'2026 秋',overall:4,difficulty:3,workload:2,advice:'【示例】根据自己的兴趣安排学习',background:'【示例】已经阅读公开说明，仍希望获得同学建议',starts_at:'2030-10-14T10:00',ends_at:'2030-10-14T12:00',deadline:'2030-10-13T18:00',location:category==='travel'?'【示例】玉泉路 → 雁栖湖':'【示例】校内公共空间',organizer:'【示例】同学自发交流',conditions:'【示例】具体安排可在回复区沟通',offer_type:key==='market'&&category==='market'?'【示例】转让一盏台灯':'【示例】寻找同路的伙伴',contact:'【示例】请在帖子中回复',apply_url:'https://example.invalid/apply',related:[]});
    fixtures.push({key,category,id:record.discussion_id});
    await page.goto('/d/'+record.discussion_id);
    await expect(page.locator('.DiscussionHero h1')).toHaveText(title);
    await expect(page.locator('.PostStream .Post-body').first()).toContainText(key+' 板块的原生正文');
    if(key==='chat')await expect(page.locator('.Campus-nativeRecord')).toHaveCount(0);
    else{
      const details=page.getByRole('region',{name:'内容详情',exact:true});
      await expect(details).toContainText(caption);
      if(['market','life','governance','courses','questions','resources','careers'].includes(key))await expect(details).not.toContainText('活动与参与信息');
      if(key==='careers')await expect(details.getByRole('link',{name:'前往申请'})).toHaveAttribute('href','https://example.invalid/apply');
      if(key==='resources')await expect(details.getByRole('link',{name:'打开资料'})).toHaveAttribute('rel','noopener noreferrer nofollow');
      if(await details.locator('.Campus-detailMore summary').count())await details.locator('.Campus-detailMore summary').click();
      if(key==='market'){
        await expect(details).not.toContainText('主办方或发布单位');
        await expect(details).not.toContainText('报名或申请链接');
        await expect(details).not.toContainText('申请截止');
        await expect(details).toContainText('来源说明');
        if(category==='travel'){
          await expect(details).toContainText('出发时间');
          await expect(details).toContainText('路线或集合地点');
          await expect(details).not.toContainText('物品与供需');
        }else{
          await expect(details).toContainText('物品与供需');
          await expect(details).not.toContainText('开始时间');
          await expect(details).not.toContainText('截止时间');
          await expect(details).not.toContainText('行程时间');
        }
      }
    }
    for(const width of [320,507,1440]){
      await page.setViewportSize({width,height:844});
      expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'overflow: '+key+' at '+width).toBeTruthy();
    }
  }
  // Old information-style chat records keep their native post stream and
  // still expose authenticated bookmark/feedback controls without event facts.
  await login(page,'phase0_admin',fs.readFileSync('.runtime/admin-initial-password.txt','utf8').trim());
  await page.goto('/d/'+fixtures.find(f=>f.key==='chat').id);
  await page.getByRole('button',{name:'收藏内容',exact:true}).click();
  await expect(page.getByRole('button',{name:'取消收藏',exact:true})).toBeVisible();
  await expect(page.locator('.Campus-recordOverview')).toHaveCount(0);
  await expect(page.locator('.PostStream .Post-body').first()).toBeVisible();
  fs.writeFileSync('.runtime/board-detail-fixtures.json',JSON.stringify(fixtures));
  expect(errors).toEqual([]);
});

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
