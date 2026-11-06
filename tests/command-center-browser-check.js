/* Browser QA of actual PHP-rendered fixtures. Requires an external Playwright
 * install via OLAMA_BROWSER_MODULE; no production dependency or SMS transport. */
const { chromium } = require(process.env.OLAMA_BROWSER_MODULE || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const fixtureUrl = file => 'http://command-center.test/' + file;
const fixtures = process.env.OLAMA_WORKSPACE_FIXTURE;
const output = process.env.OLAMA_WORKSPACE_SCREENSHOTS;
if (!fixtures || !output) throw new Error('Set fixture and screenshot directories.');
(async () => {
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', message => { if (message.type()==='error') errors.push(message.text()); });
  await page.route('http://command-center.test/**', async route => {
    const url = new URL(route.request().url());
    const basename = url.pathname === '/rtl-campaigns.html' && url.searchParams.get('s') === 'تحديث' ? 'rtl-campaigns-filtered.html' : path.basename(url.pathname);
    const file = url.pathname.startsWith('/file/') ? decodeURIComponent(url.pathname.slice(6)) : path.join(fixtures,basename);
    let body = fs.readFileSync(file);
    const ext = path.extname(file);
    if (ext === '.html') body = Buffer.from(body.toString().replaceAll('file:///','http://command-center.test/file/'));
    await route.fulfill({body,contentType:({'.html':'text/html','.css':'text/css','.js':'text/javascript','.woff2':'font/woff2','.woff':'font/woff','.ttf':'font/ttf'})[ext] || 'application/octet-stream'});
  });
  // Requests stay inside this test: never reach WordPress or the agent.
  await page.route('http://127.0.0.1:13389/**', async route => {
    const params = new URLSearchParams(route.request().postData() || '');
    const action = params.get('action');
    let result = { success: true, data: { schools: [], grades: [], sections: [], items: [] } };
    if (action === 'olama_msg_recipient_detail') {
      return route.fulfill({ contentType: 'application/json', body: fs.readFileSync(path.join(fixtures, 'recipient-detail.json'), 'utf8'), headers: {'Access-Control-Allow-Origin':'*'} });
    }
    if (action === 'olama_msg_campaign_progress') result = {success: true, data: {counts: {}, percent: {sent:57,reserved:0,failed:0}, rows: [], terminal:true, campaign_status_html:'<span>قيد الإرسال</span>'}};
    if (action === 'olama_msg_save_campaign_draft') result = {success: true, data: {campaign_id:1000,url:page.url().split('?')[0]+'?campaign_id=1000',saved_at:'fixture'}};
    return route.fulfill({contentType:'application/json',body:JSON.stringify(result),headers:{'Access-Control-Allow-Origin':'*'}});
  });
  for (const width of [360,390,768,1440]) {
    await page.setViewportSize({width,height:1000});
    for (const view of ['overview','campaigns','wizard','progress','audience','library','settings','exclusions']) {
      await page.goto(fixtureUrl(`rtl-${view}.html`));
      await page.waitForTimeout(300);
      const layout = await page.evaluate(() => ({viewport:innerWidth,scroll:document.documentElement.scrollWidth}));
      assert.ok(layout.scroll <= layout.viewport+1, `${view} overflows at ${width}: ${JSON.stringify(layout)}`);
      if (width === 1440 || width === 390 || (width === 768 && view === 'overview')) {
        await page.screenshot({path:path.join(output,`${view}-${width}.png`),fullPage:true});
      }
    }
  }
  await page.setViewportSize({width:390,height:844});
  await page.goto(fixtureUrl('rtl-overview.html'));
  await page.locator('[data-workspace-menu]').click();
  assert.equal(await page.locator('[data-workspace-menu]').getAttribute('aria-expanded'),'true');
  await page.keyboard.press('Escape');
  assert.equal(await page.locator('[data-workspace-menu]').getAttribute('aria-expanded'),'false');
  await page.keyboard.press('Control+k');
  assert.equal(await page.locator('#omw-command').evaluate(el => el.open),true);
  await page.locator('[data-workspace-query]').fill('الحملات');
  assert.ok(await page.locator('[data-workspace-result]:visible').count() > 0);
  await page.keyboard.press('Escape');
  await page.goto(fixtureUrl('rtl-campaigns.html'));
  let filterCalls = 0;
  page.on('request',request=>{ if(request.url().includes('rtl-campaigns.html?')) filterCalls++; });
  await page.locator('input[name="s"]').pressSequentially('تحديث',{delay:40});
  await page.waitForURL(url=>url.searchParams.get('s')==='تحديث');
  assert.equal(filterCalls,1,'typing is debounced into one server request');
  assert.equal(await page.locator('input[name="s"]').inputValue(),'تحديث');
  assert.equal(await page.locator('input[name="s"]').evaluate(el=>document.activeElement===el),true,'filter preserves keyboard focus');
  await page.goto(fixtureUrl('rtl-progress.html'));
  await page.locator('[data-recipient-detail]').first().click();
  await page.locator('[data-recipient-content] dl').waitFor();
  assert.ok((await page.locator('[data-recipient-content]').innerText()).includes('•••'));
  assert.equal(await page.locator('#omw-recipient-drawer').evaluate(el => el.open),true);
  await page.screenshot({path:path.join(output,'recipient-drawer-390.png'),fullPage:true});
  await page.keyboard.press('Escape');
  assert.equal(await page.locator('[data-recipient-detail]').first().evaluate(el=>document.activeElement===el),true);
  await page.goto(fixtureUrl('rtl-wizard.html'));
  assert.equal(await page.locator('.omsg-steps li').count(),3);
  assert.equal(await page.locator('.omsg-step-panel[data-step="1"]').isVisible(),true);
  assert.equal(await page.locator('.omsg-step-panel[data-step="2"]').isVisible(),true);
  await page.locator('[data-wizard-next]').click();
  assert.equal(await page.locator('.omsg-wizard').getAttribute('data-step'),'1','empty title blocks progression');
  await page.locator('[name="title"]').fill('اجتماع المدرسة');
  await page.locator('[data-wizard-next]').click();
  await page.waitForFunction(()=>document.querySelector('.omsg-wizard').dataset.step==='3');
  await page.locator('[name="message_body_draft"]').fill('أولياء الأمور الكرام <script>alert(1)</script>');
  assert.ok((await page.locator('[data-workspace-message-preview]').innerText()).includes('<script>'));
  assert.equal(await page.locator('[data-workspace-message-preview] script').count(),0);
  await page.screenshot({path:path.join(output,'wizard-message-390.png'),fullPage:true});
  await page.locator('[data-wizard-next]').click();
  await page.waitForFunction(()=>document.querySelector('.omsg-wizard').dataset.step==='4');
  await page.locator('.omsg-preview-context').waitFor();
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth+1),'review preview fits mobile');
  await page.screenshot({path:path.join(output,'wizard-review-390.png'),fullPage:true});
  assert.deepEqual(errors,[],'no uncaught browser or console errors');
  console.log('PASS: 32 RTL layouts; navigation, search, drawer, focus restoration, three-stage wizard validation and safe preview. No transport connected.');
  await browser.close();
})().catch(error=>{console.error(error);process.exit(1);});
