const {test,expect}=require('@playwright/test');

function renderedContrast(node) {
  // Chromium can return CSS system color names (CanvasText, Canvas) in high contrast mode.
  // Paint a pixel to resolve those colors exactly as the browser renders them.
  const canvas=document.createElement('canvas');canvas.width=canvas.height=1;
  const context=canvas.getContext('2d');
  const color=value=>{
    context.clearRect(0,0,1,1);
    context.fillStyle=value;
    context.fillRect(0,0,1,1);
    return Array.from(context.getImageData(0,0,1,1).data);
  };
  const background=element=>{
    if(!element)return [255,255,255];
    const base=background(element.parentElement),rgba=color(getComputedStyle(element).backgroundColor);
    const alpha=rgba[3]/255;
    return rgba.slice(0,3).map((channel,index)=>channel*alpha+base[index]*(1-alpha));
  };
  const luminance=rgb=>{
    const channels=rgb.map(value=>{const n=value/255;return n<=.04045?n/12.92:((n+.055)/1.055)**2.4;});
    return channels[0]*.2126+channels[1]*.7152+channels[2]*.0722;
  };
  const bg=background(node),fg=color(getComputedStyle(node).color);
  const alpha=fg[3]/255;
  const visible=fg.slice(0,3).map((channel,index)=>channel*alpha+bg[index]*(1-alpha));
  const a=luminance(visible),b=luminance(bg);
  return (Math.max(a,b)+.05)/(Math.min(a,b)+.05);
}

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
    await page.locator('.Header-primary').getByRole('link',{name:'校园信息',exact:true}).click();
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
    // Wait for the rendered description itself: evaluating a node from the
    // redraw gap yields a detached element with empty computed styles.
    await expect(page.locator('.Campus-directoryDescription').first()).toBeVisible();
    for(const theme of ['light','dark','light-hc','dark-hc']) {
      await page.evaluate(theme=>document.documentElement.setAttribute('data-theme',theme),theme);
      await expect.poll(()=>page.locator('.Campus-directoryDescription').first().evaluate(renderedContrast),{message:theme+' text contrast'}).toBeGreaterThanOrEqual(4.5);
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
      await expect.poll(()=>page.locator('.Campus-feedTags .TagLabel-text').first().evaluate(renderedContrast),{message:theme+' tag contrast'}).toBeGreaterThanOrEqual(4.5);
      for (const selector of ['.Header-primary .Button-label','.Header-secondary .Button-label','.Campus-listMetadata']) {
        await expect.poll(()=>page.locator(selector).first().evaluate(renderedContrast),{message:theme+' '+selector+' contrast'}).toBeGreaterThanOrEqual(4.5);
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
      const contrast=await page.locator('.DiscussionHero-title').evaluate(renderedContrast);
      expect(contrast,theme+' discussion heading contrast').toBeGreaterThanOrEqual(4.5);
    }
    await page.emulateMedia({forcedColors:'active'});
    await expect(page.locator('.DiscussionHero-title')).toBeVisible();
    expect(errors).toEqual([]);
  } finally {await context.close();}
});

