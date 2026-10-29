const {test,expect}=require('@playwright/test');

test('toolbox ships the imported timetable, both semester calendars and the shuttle table',async({page})=>{
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('/campus/tools');
  const items=page.locator('.Campus-toolboxItem');
  await expect(items).toHaveCount(6);
  for(const name of ['校历','课程表','摆渡车时间表']) await expect(page.locator('.Campus-toolboxList')).toContainText(name);
  await expect(page.locator('.Campus-toolboxList')).toContainText('已录入');
  expect(errors).toEqual([]);
});

test('timetable renders the weekly grid and filters by week, unit and query',async({page})=>{
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto('/campus/tools/timetable');
  await expect(page.locator('table.Campus-tt thead th').first()).toHaveText('节次');
  await expect(page.locator('table.Campus-tt tbody tr')).toHaveCount(15);
  const all=await page.locator('.Campus-ttSlot').count();
  expect(all).toBeGreaterThan(500);
  // A later week drops the short-range courses (poll: the grid re-renders).
  await page.locator('.Campus-ttField select').first().selectOption('16');
  await expect.poll(()=>page.locator('.Campus-ttSlot').count()).toBeLessThan(all);
  // Query filtering matches course, teacher or room text.
  await page.locator('.Campus-ttField input[type=search]').fill('大学英语');
  await expect(page.locator('.Campus-ttSlot').first()).toContainText('大学英语');
  const filtered=await page.locator('.Campus-ttSlot').count();
  expect(filtered).toBeLessThan(all);
  // Phone widths must not overflow the page; the table scrolls inside.
  for(const width of [390,320]){
    await page.setViewportSize({width,height:900});
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'timetable overflow at '+width).toBeTruthy();
  }
  expect(errors).toEqual([]);
});

test('semester calendar lists 18 weeks with holidays and key dates',async({page})=>{
  await page.goto('/campus/tools/calendar');
  await expect(page.locator('.Campus-calWeeks tbody tr')).toHaveCount(18);
  await expect(page.locator('.Campus-calWeeks')).toContainText('9月7日');
  await expect(page.locator('.Campus-calWeeks')).toContainText('中秋节');
  await expect(page.locator('.Campus-calWeeks')).toContainText('国庆节');
  await page.getByRole('button',{name:/春季学期/}).click();
  await expect(page.locator('.Campus-calWeeks')).toContainText('3月1日');
  await expect(page.locator('.Campus-calWeeks')).toContainText('清明节');
  await expect(page.locator('.Campus-calEvents')).toContainText('考试周');
  await expect(page.locator('.Campus-calNotes li')).toHaveCount(7);
});

test('shuttle table keeps all 24 trips and the three suspension gaps',async({page})=>{
  await page.goto('/campus/tools/shuttle');
  await expect(page.locator('.Campus-shuttle tbody tr')).toHaveCount(27);
  await expect(page.locator('.Campus-shuttleStop')).toHaveCount(3);
  await expect(page.locator('.Campus-shuttle tbody tr').first()).toContainText('6:30');
  await expect(page.locator('.Campus-shuttle tbody tr').last()).toContainText('23:15');
});
