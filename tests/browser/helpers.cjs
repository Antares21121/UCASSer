const {expect}=require('@playwright/test');
async function login(page,name,password){
  await page.goto('/campus');
  await page.getByRole('button',{name:'登录',exact:true}).first().click();
  await page.locator('input[name="identification"]').fill(name);
  await page.locator('input[name="password"]').fill(password);
  await Promise.all([page.waitForNavigation(),page.locator('.LogInModal').getByRole('button',{name:'登录',exact:true}).click()]);
  await expect(page.locator('.LogInModal')).toHaveCount(0);
}
async function reply(page,body){
  await page.locator('.DiscussionPage-nav').getByRole('button',{name:'回复',exact:true}).first().click();
  await page.locator('#composer .TextEditor-editorContainer textarea').fill(body);
  await page.locator('#composer').getByRole('button',{name:'发布回复',exact:true}).click();
  await expect(page.locator('.Post-body').filter({hasText:body})).toHaveCount(1);
}
module.exports={login,reply};
