const { test, expect } = require('@playwright/test');

test('visitor home makes the four main tasks reachable on desktop and phone', async ({ page }) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  // Returning visitors may have closed Flarum's welcome message before the
  // campus skin was deployed. The permanent masthead must still be visible.
  await page.addInitScript(() => localStorage.setItem('welcomeHidden', 'true'));
  await page.goto('/campus');
  await expect(page.locator('.Campus-homeIdentity')).toContainText('非学校官方平台');
  await expect(page.locator('.WelcomeHero')).toBeVisible();
  await expect(page.locator('.WelcomeHero .Hero-close')).toHaveCount(0);
  expect(await page.evaluate(() => localStorage.getItem('welcomeHidden'))).toBe('true');
  // The hero image is meaningful content with a descriptive alt, not a
  // decorative aria-hidden ornament.
  await expect(page.locator('.Campus-homeArtwork img')).toHaveAttribute('alt', /书|笔记/);
  const paths = page.getByRole('navigation', { name: '校园常用入口' });
  await expect(paths.getByRole('link')).toHaveCount(5);
  for (const [name, href] of [
    ['查看校园信息', '/campus/aggregation'],
    ['寻找学习资料', '/resources'],
    ['浏览课程目录', '/campus/courses'],
    ['提出一个问题', '/t/campus-questions'],
    ['打开校园工具', '/campus/tools']
  ]) await expect(paths.getByRole('link', { name: new RegExp(name) })).toHaveAttribute('href', href);

  await expect(page.locator('.DiscussionListItem').first()).toBeVisible();
  for (const width of [1440, 390, 320]) {
    await page.setViewportSize({ width, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `home overflow at ${width}px`).toBeTruthy();
    await expect(paths.getByRole('link', { name: /打开校园工具/ })).toBeVisible();
  }

  await paths.getByRole('link', { name: /打开校园工具/ }).click();
  await expect(page).toHaveURL(/\/campus\/tools$/);
  await expect(page.getByRole('heading', { name: '校园工具箱' })).toBeVisible();
  await page.goto('/campus');
  await paths.getByRole('link', { name: /查看校园信息/ }).click();
  await expect(page).toHaveURL(/\/campus\/aggregation$/);
  await page.getByRole('button', { name: '登录后提交线索' }).click();
  await expect(page.locator('.LogInModal')).toBeVisible();
  expect(errors).toEqual([]);
});

for (const colorScheme of ['light', 'dark']) {
  test(`home masthead survives an empty welcome title in ${colorScheme} mode`, async ({ page }) => {
    await page.emulateMedia({ colorScheme });
    await page.addInitScript(() => localStorage.setItem('welcomeHidden', 'true'));
    await page.route('**/campus', async route => {
      const response = await route.fetch();
      const html = await response.text();
      expect(html).toMatch(/"welcomeTitle"\s*:\s*"/);
      await route.fulfill({ response, body: html.replace(/"welcomeTitle"\s*:\s*"(?:[^"\\]|\\.)*"/, '"welcomeTitle":""') });
    });
    await page.goto('/campus');
    await expect(page.locator('.WelcomeHero .Hero-title')).toHaveText(/校园公共论坛/);
    await expect(page.locator('.Campus-homeSearch')).toBeVisible();
    await expect(page.locator('.Campus-homeArtwork img')).toBeVisible();
    await expect(page.locator('.WelcomeHero .Hero-close')).toHaveCount(0);
    // A homepage exception must not erase a visitor's native welcome preference.
    expect(await page.evaluate(() => localStorage.getItem('welcomeHidden'))).toBe('true');
    await page.reload();
    await expect(page.locator('.Campus-homeSearch')).toBeVisible();
  });
}
