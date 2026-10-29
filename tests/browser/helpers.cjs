const {expect}=require('@playwright/test');
// Functional workflows share an in-memory authenticated session. Repeatedly
// logging the same fixture account in would exhaust its real login limit.
const sessions=new Map();
async function login(page,name,password){
  await page.goto('/campus');
  const origin=new URL(page.url()).origin;
  const key=origin+'|'+name;
  if(sessions.has(key)){
    await page.context().addCookies(sessions.get(key));
    await page.goto('/campus');
    try {
      await expect(page.locator('.SessionDropdown')).toBeVisible({timeout:5000});
      return;
    } catch {sessions.delete(key);}
  }
  await page.getByRole('button',{name:'登录',exact:true}).first().click();
  await page.locator('input[name="identification"]').fill(name);
  await page.locator('input[name="password"]').fill(password);
  await Promise.all([page.waitForNavigation(),page.locator('.LogInModal').getByRole('button',{name:'登录',exact:true}).click()]);
  await expect(page.locator('.LogInModal')).toHaveCount(0);
  sessions.set(key,await page.context().cookies(origin));
}
async function reply(page,body){
  await page.locator('.DiscussionPage-nav').getByRole('button',{name:'回复',exact:true}).first().click();
  await page.locator('#composer .TextEditor-editorContainer textarea').fill(body);
  await page.locator('#composer').getByRole('button',{name:'发布回复',exact:true}).click();
  await expect(page.locator('.Post-body').filter({hasText:body})).toHaveCount(1);
}
module.exports={login,reply};
