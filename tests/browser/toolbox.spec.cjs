const {test,expect}=require('@playwright/test');

test('toolbox lists six tools and opens the published image pages',async({page})=>{
  await page.goto('/campus/tools');
  await expect(page.locator('.Campus-toolboxItem')).toHaveCount(6);
  await page.goto('/campus/tools/calendar');
  await expect(page.locator('.Campus-toolImage img')).toHaveCount(2);
  await expect.poll(()=>page.locator('.Campus-toolImage img').first().evaluate(el=>el.complete&&el.naturalWidth>100)).toBeTruthy();
  await page.goto('/campus/tools/shuttle');
  await expect(page.locator('.Campus-toolImage img')).toHaveCount(1);
});

test('timetable is a day-by-period button grid with a slot dialog',async({page})=>{
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('/campus/tools/timetable');
  await expect(page.locator('.Campus-ttCell')).toHaveCount(90);
  await expect(page.locator('.Campus-ttCell.is-filled').first()).toBeVisible();
  await page.locator('label.Campus-ttField',{hasText:'院系'}).locator('select').selectOption({index:1});
  await expect.poll(()=>page.locator('.Campus-ttCell.is-filled').count()).toBeGreaterThan(0);
  await page.locator('.Campus-ttField',{hasText:'院系'}).locator('select').selectOption('');
  await page.locator('.Campus-ttCell.is-filled').first().click();
  const modal=page.locator('.CampusSlotModal');
  await expect(modal).toBeVisible();
  expect(await modal.locator('.Campus-slotItem').count()).toBeGreaterThan(0);
  await expect(modal.getByRole('button',{name:'订阅这门课'}).first()).toBeVisible();
  await page.keyboard.press('Escape');
  for(const width of [390,320]){
    await page.setViewportSize({width,height:900});
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'overflow at '+width).toBeTruthy();
  }
  expect(errors).toEqual([]);
});
