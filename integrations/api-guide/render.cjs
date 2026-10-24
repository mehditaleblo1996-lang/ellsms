// Renders one guide page set to PDF with the pre-installed Chromium.
// usage: node render.cjs <in.html> <out.pdf> [--footer | --markers]
const { chromium } = require('playwright');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const { pathToFileURL } = require('node:url');

const [, , input, output, flag] = process.argv;
const here = __dirname;
const font = readFileSync(resolve(here, '../../public/assets/fonts/Vazirmatn-Regular.woff2')).toString('base64');

(async () => {
const browser = await chromium.launch();
const page = await browser.newPage();
await page.goto(pathToFileURL(resolve(input)).href, { waitUntil: 'networkidle' });
await page.evaluate(() => document.fonts.ready);
if (flag === '--markers') {
  // Pass used only to learn which page each section starts on: an out-of-flow marker per heading, so the
  // layout is identical to the real render.
  await page.evaluate(() => document.querySelectorAll('h2.sec[id]').forEach((h) => {
    const m = document.createElement('span');
    m.textContent = 'QQMARK' + h.id + 'QQ';
    m.style.cssText = 'position:absolute;font-size:4px;color:#fff;direction:ltr';
    h.style.position = 'relative';
    h.appendChild(m);
  }));
}

const footer = `
<style>@font-face{font-family:V;src:url(data:font/woff2;base64,${font})}</style>
<div style="width:100%;margin:0 17mm;padding-top:5px;border-top:1px solid #e6e4f5;display:flex;justify-content:space-between;
            direction:rtl;font-family:V,sans-serif;font-size:7.5pt;color:#5c657d;-webkit-print-color-adjust:exact">
  <span>راهنمای API پیامک — داتیس رایان گستر ایرانیان</span>
  <span class="pageNumber" style="font-weight:bold;color:#4a32e8"></span>
</div>`;

await page.pdf({
  path: output,
  preferCSSPageSize: true,
  printBackground: true,
  displayHeaderFooter: flag !== undefined,
  headerTemplate: '<span></span>',
  footerTemplate: flag !== undefined ? footer : '<span></span>',
});
await browser.close();
})();
