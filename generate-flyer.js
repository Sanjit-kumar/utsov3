const { chromium } = require('C:/Users/sanji/OneDrive/Documents/Web Test/node_modules/@playwright/test');
const path = require('path');

(async () => {
  console.log('Launching browser...');
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();

  await page.setViewportSize({ width: 1080, height: 1350 });

  const htmlPath = 'file:///' + path.join('C:/Users/sanji/OneDrive/Documents/UTSOV3', 'flyer-social.html').replace(/\\/g, '/');
  console.log('Loading flyer HTML...');
  await page.goto(htmlPath, { waitUntil: 'networkidle' });

  // Extra wait for Google Fonts to fully render
  await page.waitForTimeout(3000);

  const outPath = path.join('C:/Users/sanji/OneDrive/Documents/UTSOV3', 'utsov-flyer-2026.png');
  await page.screenshot({
    path: outPath,
    clip: { x: 0, y: 0, width: 1080, height: 1350 }
  });

  console.log('Done! Flyer saved to: ' + outPath);
  await browser.close();
})();
