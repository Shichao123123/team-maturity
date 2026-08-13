/**
 * PC 后台使用手册截图脚本（本机 Chrome）
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.TM_BASE || 'http://127.0.0.1:8088';
const OUT = path.join(__dirname, 'images');
fs.mkdirSync(OUT, { recursive: true });

async function shot(page, name, fullPage = true) {
  const file = path.join(OUT, name + '.png');
  await page.waitForTimeout(900);
  await page.screenshot({ path: file, fullPage });
  console.log('saved', path.basename(file));
}

async function main() {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const context = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    locale: 'zh-CN',
  });
  const page = await context.newPage();

  // 登录页（站点根路径）
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('input[name="username"]', { timeout: 15000 });
  await shot(page, '01-login', false);

  await page.fill('input[name="username"]', 'admin');
  await page.fill('input[name="password"]', '123456');
  await page.click('button[lay-filter="login"], button[type="submit"], button:has-text("登")');
  await page.waitForTimeout(2000);

  // 若仍在登录页，走 API
  if (await page.locator('input[name="username"]').count()) {
    const res = await page.evaluate(async () => {
      const body = new URLSearchParams({ username: 'admin', password: '123456' });
      const r = await fetch('/index/login/login', {
        method: 'POST',
        body,
        credentials: 'include',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      try { return await r.json(); } catch (e) { return { ok: false, status: r.status }; }
    });
    console.log('login api', JSON.stringify(res));
  }

  await page.goto(BASE + '/index/index/index', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);
  await shot(page, '02-home', false);

  const pages = [
    ['03-welcome', '/index/index/welcome'],
    ['04-org', '/index/tm_org/index'],
    ['05-team', '/index/tm_team/index'],
    ['06-member', '/index/tm_member/index'],
    ['07-indicator-version', '/index/tm_indicator_version/index'],
    ['08-indicator', '/index/tm_indicator/index'],
    ['09-level', '/index/tm_level/index'],
    ['10-batch', '/index/tm_batch/index'],
    ['11-result', '/index/tm_result/index'],
    ['12-dashboard', '/index/tm_dashboard/index'],
    ['13-trend', '/index/tm_dashboard/trend'],
    ['14-rank', '/index/tm_dashboard/rank'],
    ['15-admin', '/index/tm_admin/index'],
    ['16-role', '/index/tm_role/index'],
    ['17-log', '/index/tm_log/index'],
  ];

  // ThinkPHP 兼容多套 URL；失败则尝试驼峰控制器
  const alt = (u) => {
    const map = {
      '/index/tm_org/index': '/TmOrg/index',
      '/index/tm_team/index': '/TmTeam/index',
      '/index/tm_member/index': '/TmMember/index',
      '/index/tm_indicator_version/index': '/TmIndicatorVersion/index',
      '/index/tm_indicator/index': '/TmIndicator/index',
      '/index/tm_level/index': '/TmLevel/index',
      '/index/tm_batch/index': '/TmBatch/index',
      '/index/tm_result/index': '/TmResult/index',
      '/index/tm_dashboard/index': '/TmDashboard/index',
      '/index/tm_dashboard/trend': '/TmDashboard/trend',
      '/index/tm_dashboard/rank': '/TmDashboard/rank',
      '/index/tm_admin/index': '/TmAdmin/index',
      '/index/tm_role/index': '/TmRole/index',
      '/index/tm_log/index': '/TmLog/index',
      '/index/index/welcome': '/Index/welcome',
      '/index/index/index': '/Index/index',
    };
    return map[u] || u;
  };

  for (const [name, url] of pages) {
    let ok = false;
    for (const u of [alt(url), url]) {
      const resp = await page.goto(BASE + u, { waitUntil: 'domcontentloaded', timeout: 20000 });
      await page.waitForTimeout(1200);
      const title = await page.title();
      const bodyText = await page.locator('body').innerText().catch(() => '');
      if (resp && resp.status() < 400 && !bodyText.includes('非法请求') && !bodyText.includes('HttpException')) {
        await shot(page, name, true);
        ok = true;
        break;
      }
      console.log('retry', name, u, resp && resp.status());
    }
    if (!ok) console.log('FAIL', name);
  }

  // 批次详情
  await page.goto(BASE + '/TmBatch/index', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  const detail = page.locator('text=详情').first();
  if (await detail.count()) {
    await detail.click();
    await page.waitForTimeout(1800);
    await shot(page, '18-batch-detail', true);
  }

  await browser.close();
  console.log('done');
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
