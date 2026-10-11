const {test,expect}=require('@playwright/test');

test('numbered native pages remain distinct through reload and preserve filters',async({page})=>{
  const errors=[]; page.on('pageerror',e=>errors.push(e.message));
  await page.goto('/?sort=newest');
  const titles=page.locator('.DiscussionListItem-title');
  await expect(titles.first()).toBeVisible();
  const row=page.locator('.DiscussionListItem').first();
  await expect(row.locator('.Campus-feedHeading .Campus-feedTags')).toBeVisible();
  await expect(row.locator('.DiscussionListItem-info .item-tags')).toHaveCount(0);
  const heading=await row.locator('.Campus-feedHeading').evaluate(node=>{
    const title=node.querySelector('h2').getBoundingClientRect(),tags=node.querySelector('.Campus-feedTags').getBoundingClientRect();
    return {titleTop:title.top,titleRight:title.right,tagsTop:tags.top,tagsLeft:tags.left};
  });
  expect(Math.abs(heading.tagsTop-heading.titleTop)).toBeLessThan(8);
  expect(heading.tagsLeft).toBeGreaterThanOrEqual(heading.titleRight);
  const first=await titles.allTextContents();
  expect(first.length).toBeGreaterThan(0);
  await page.getByRole('navigation',{name:'信息流分页'}).getByRole('link',{name:'下一页',exact:true}).click();
  await expect(page).toHaveURL(/page=2/);
  await expect(page).toHaveURL(/sort=newest/);
  await expect.poll(async()=>{
    const values=await titles.allTextContents();
    return values.length>0 && !values.some(title=>first.includes(title));
  }).toBeTruthy();
  const second=await titles.allTextContents();
  expect(second.some(title=>first.includes(title))).toBeFalsy();
  await page.reload();
  await expect(titles.first()).toBeVisible();
  await expect.poll(()=>titles.allTextContents()).toEqual(second);
  await expect(page.getByRole('navigation',{name:'信息流分页'}).locator('[aria-current="page"]')).toHaveText('2');
  await page.getByRole('navigation',{name:'信息流分页'}).getByRole('link',{name:'上一页',exact:true}).click();
  await expect(titles.first()).toBeVisible();
  await expect.poll(()=>titles.allTextContents()).toEqual(first);
  await expect(page.locator('.Navigation-back')).toHaveCount(0);
  expect(await page.locator('.Campus-nativeExplore').evaluate(node=>getComputedStyle(node).backgroundColor)).toBe('rgba(0, 0, 0, 0)');
  expect(errors).toEqual([]);
});

test('a delayed previous board cannot flash or replace the next board',async({page})=>{
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('/t/campus-resources');
  await expect(page.locator('.DiscussionListItem').first()).toBeVisible();
  let unblock, arrived;
  const gate=new Promise(resolve=>unblock=resolve), ready=new Promise(resolve=>arrived=resolve);
  let held=false;
  await page.route('**/api/discussions?*',async route=>{
    if(held) return route.continue();
    held=true;
    const response=await route.fetch();
    arrived();
    await gate;
    await route.fulfill({response});
  });
  try {
    await page.locator('.Header-primary').getByRole('link',{name:'课程经验',exact:true}).click();
    await ready;
    await expect(page.locator('.Campus-feedLoading')).toBeVisible();
    await expect(page.locator('.DiscussionListItem')).toHaveCount(0);
    await page.locator('.Header-primary').getByRole('link',{name:'问答',exact:true}).click();
    await expect(page.locator('.CampusBoardHero h1')).toContainText('问答');
    await expect(page.locator('.DiscussionListItem').first()).toBeVisible();
    const before=await page.locator('.DiscussionListItem-title').allTextContents();
    unblock();
    await page.unrouteAll({behavior:'wait'});
    await expect.poll(()=>page.locator('.DiscussionListItem-title').allTextContents()).toEqual(before);
    expect(errors).toEqual([]);
  } finally {unblock();await page.unrouteAll({behavior:'wait'});}
});

test('directory and native pages keep readable theme colors and reflow at high DPI',async({browser})=>{
  const context=await browser.newContext({deviceScaleFactor:2,viewport:{width:1440,height:1000}});
  const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  try {
    await page.goto('/resources');
    await expect(page.getByRole('heading',{name:'资料分类目录',exact:true})).toBeVisible();
    await expect(page.locator('.Campus-directoryCard').first()).toBeVisible();
    await page.getByRole('link',{name:'校外资料',exact:true}).first().click();
    await expect(page.locator('.Campus-directoryCard a[href="https://cet.neea.edu.cn/"]').first()).toBeVisible();
    await expect(page.locator('.Campus-directoryCard a[href="https://ncre.neea.edu.cn/"]').first()).toBeVisible();
    for(const theme of ['light','dark','light-hc','dark-hc']) {
      await page.evaluate(theme=>document.documentElement.setAttribute('data-theme',theme),theme);
      const contrast=await page.locator('.Campus-directoryDescription').first().evaluate(node=>{
        const color=v=>v.match(/\d+(\.\d+)?/g).slice(0,3).map(Number).map(n=>{n/=255;return n<=.04045?n/12.92:((n+.055)/1.055)**2.4;});
        const luminance=v=>{const c=color(v);return c[0]*.2126+c[1]*.7152+c[2]*.0722;};
        const a=luminance(getComputedStyle(node).color),b=luminance(getComputedStyle(node.closest('article')).backgroundColor);
        return (Math.max(a,b)+.05)/(Math.min(a,b)+.05);
      });
      expect(contrast,theme+' text contrast').toBeGreaterThanOrEqual(4.5);
    }
    for(const width of [720,390,320]) {
      await page.setViewportSize({width,height:1000});
      expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'directory overflow at '+width).toBeTruthy();
      await expect(page.getByRole('button',{name:'搜索',exact:true})).toBeVisible();
    }
    await page.emulateMedia({forcedColors:'active',reducedMotion:'reduce'});
    await page.setViewportSize({width:1440,height:1000});
    await page.goto('/?sort=newest');
    await expect(page.locator('.Campus-feedTags').first()).toBeVisible();
    await page.emulateMedia({forcedColors:'none'});
    for (const theme of ['light','dark','light-hc','dark-hc']) {
      await page.evaluate(theme=>document.documentElement.setAttribute('data-theme',theme),theme);
      const contrast=await page.locator('.Campus-feedTags .TagLabel-text').first().evaluate(node=>{
        const luminance=value=>{const c=value.match(/\d+(\.\d+)?/g).slice(0,3).map(Number).map(n=>{n/=255;return n<=.04045?n/12.92:((n+.055)/1.055)**2.4;});return c[0]*.2126+c[1]*.7152+c[2]*.0722;};
        const a=luminance(getComputedStyle(node).color),b=luminance(getComputedStyle(node.closest('.TagLabel')).backgroundColor);
        return (Math.max(a,b)+.05)/(Math.min(a,b)+.05);
      });
      expect(contrast,theme+' tag contrast').toBeGreaterThanOrEqual(4.5);
      for (const selector of ['.Header-primary .Button-label','.Header-secondary .Button-label','.Campus-listMetadata']) {
        const ratio=await page.locator(selector).first().evaluate(node=>{
          const parse=value=>value.match(/[\d.]+/g).map(Number),lum=rgb=>{const c=rgb.slice(0,3).map(n=>{n/=255;return n<=.04045?n/12.92:((n+.055)/1.055)**2.4;});return c[0]*.2126+c[1]*.7152+c[2]*.0722;};
          const bg=node=>{if(!node)return[255,255,255];const c=parse(getComputedStyle(node).backgroundColor),base=bg(node.parentElement),a=c.length>3?c[3]:1;return c.slice(0,3).map((v,i)=>v*a+base[i]*(1-a));};
          const a=lum(parse(getComputedStyle(node).color)),b=lum(bg(node));return(Math.max(a,b)+.05)/(Math.min(a,b)+.05);
        });
        expect(ratio,theme+' '+selector+' contrast').toBeGreaterThanOrEqual(4.5);
      }
    }
    for (const width of [992,768,390,320]) {
      await page.setViewportSize({width,height:1000});
      expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'feed overflow at '+width).toBeTruthy();
      await expect(page.locator('.Navigation-back')).toHaveCount(0);
    }
    await page.locator('.DiscussionListItem-main').first().click();
    await expect(page.locator('.DiscussionHero-title')).toBeVisible();
    await page.emulateMedia({forcedColors:'none'});
    for (const theme of ['light','dark','light-hc','dark-hc']) {
      await page.evaluate(theme=>document.documentElement.setAttribute('data-theme',theme),theme);
      const contrast=await page.locator('.DiscussionHero-title').evaluate(node=>{
        const luminance=value=>{const c=value.match(/\d+(\.\d+)?/g).slice(0,3).map(Number).map(n=>{n/=255;return n<=.04045?n/12.92:((n+.055)/1.055)**2.4;});return c[0]*.2126+c[1]*.7152+c[2]*.0722;};
        const a=luminance(getComputedStyle(node).color),b=luminance(getComputedStyle(node.closest('.DiscussionHero')).backgroundColor);
        return (Math.max(a,b)+.05)/(Math.min(a,b)+.05);
      });
      expect(contrast,theme+' discussion heading contrast').toBeGreaterThanOrEqual(4.5);
    }
    await page.emulateMedia({forcedColors:'active'});
    await expect(page.locator('.DiscussionHero-title')).toBeVisible();
    expect(errors).toEqual([]);
  } finally {await context.close();}
});
