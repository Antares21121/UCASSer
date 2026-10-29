const {test,expect}=require('@playwright/test');
const fs=require('fs');
const {login}=require('./helpers.cjs');

test('home shows the red editorial hero, image cards and no skin/bell in the top bar',async({page})=>{
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('/campus');
  // Deep red headline with a fine red rule, no full-block saturated hero.
  const title=page.locator('.WelcomeHero .Hero-title');
  await expect(title).toBeVisible();
  const color=await title.evaluate(node=>{
    const match=getComputedStyle(node).color.match(/\d+/g).slice(0,3).map(Number);
    return {rgb:match,red:match[0]>match[1]&&match[0]>match[2]};
  });
  expect(color.red,'hero title should be red-dominant').toBeTruthy();
  // Hero image with a descriptive alt and fixed box.
  await expect(page.locator('.Campus-homeArtwork img')).toBeVisible();
  await expect(page.locator('.Campus-homeArtwork img')).toHaveAttribute('alt',/书|笔记/);
  // Board cards carry fixed 4:3 figures with lazy loading.
  const cards=page.locator('.Campus-nativeExplore .Campus-section');
  await expect(cards.first()).toBeVisible();
  await expect(page.locator('.Campus-nativeExplore .Campus-sectionFigure img').first()).toHaveAttribute('loading','lazy');
  // No theme switch, no notification bell, no 全部板块 entry.
  await expect(page.locator('.ThemeSwitcher')).toHaveCount(0);
  await expect(page.locator('.Header-secondary .NotificationsDropdown')).toHaveCount(0);
  await expect(page.locator('.Campus-nativeExplore').getByRole('link',{name:'全部板块'})).toHaveCount(0);
  // Course directory still reachable from the top navigation.
  await page.locator('.Header-primary').getByRole('link',{name:'课程目录',exact:true}).click();
  await expect(page.getByRole('heading',{name:'课程目录',exact:true})).toBeVisible();
  for(const width of [390,768,1440]){
    await page.setViewportSize({width,height:900});
    await page.goto('/campus');
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'home overflow at '+width).toBeTruthy();
  }
  expect(errors).toEqual([]);
});

test('the public reply bar keeps only image upload, mention and submit',async({page})=>{
  await login(page,'phase0_admin',fs.readFileSync('.runtime/admin-initial-password.txt','utf8').trim());
  await page.goto('/campus/records?type=question');
  const row=page.locator('.DiscussionListItem').first();
  await expect(row).toBeVisible();
  await row.locator('.DiscussionListItem-title').click();
  await page.locator('.DiscussionPage-nav').getByRole('button',{name:'回复',exact:true}).first().click();
  const toolbar=page.locator('#composer .TextEditor-toolbar');
  await expect(toolbar).toBeVisible();
  // Exactly two tools: the campus image upload and the mention button.
  await expect(toolbar.locator('.Campus-postImageButton')).toHaveCount(1);
  await expect(toolbar.locator('.fa-at, .fa-solid.fa-at').first()).toBeVisible();
  for(const removed of ['粗体','斜体','删除线','引用','代码','链接','表情','预览']){
    await expect(page.locator('#composer').getByRole('button',{name:removed,exact:true})).toHaveCount(0);
  }
  await expect(page.locator('#composer').getByRole('button',{name:'发布回复',exact:true})).toBeVisible();
  await expect(page.locator('#composer .TextEditor-editorContainer textarea')).toBeVisible();
});

test('profile pages show the permanent suffix and the Lv.1 baseline',async({page})=>{
  await login(page,'phase0_admin',fs.readFileSync('.runtime/admin-initial-password.txt','utf8').trim());
  await page.goto('/u/phase0_admin');
  await expect(page.locator('.Campus-profileTags')).toContainText('账号编号');
  await expect(page.locator('.Campus-profileTags')).toContainText('#');
  await expect(page.locator('.Campus-profileTags')).toContainText('Lv.1');
});
