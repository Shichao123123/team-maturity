/**
 * 小程序员工手册界面示意截图（HTML 还原，非微信真机截屏）
 */
const { chromium } = require(require('path').join(__dirname, '..', 'manual-pc', 'node_modules', 'playwright'));
const fs = require('fs');
const path = require('path');

const OUT = path.join(__dirname, 'images');
const HTML = path.join(__dirname, 'mock.html');
fs.mkdirSync(OUT, { recursive: true });

const screens = [
  ['01-home', 'home', '中钢安环院 · 班组成熟度'],
  ['02-exam-enter', 'exam', '进入测评'],
  ['03-paper', 'paper', '第 15 / 40 题'],
  ['04-result', 'result', '个人结果'],
  ['05-blocked', 'blocked', '进入测评'],
];

async function main() {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  const page = await browser.newPage({
    viewport: { width: 438, height: 860 },
    deviceScaleFactor: 2,
  });
  await page.goto('file:///' + HTML.replace(/\\/g, '/'), { waitUntil: 'domcontentloaded' });

  for (const [file, screen, title] of screens) {
    await page.evaluate(
      ([s, t]) => window.showScreen(s, t),
      [screen, title]
    );
    await page.waitForTimeout(200);
    await page.locator('#phone').screenshot({ path: path.join(OUT, file + '.png') });
    console.log('saved', file + '.png');
  }

  await browser.close();
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
