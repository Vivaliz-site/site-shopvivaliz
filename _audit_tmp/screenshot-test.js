const { chromium } = require('playwright');
const path = require('path');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  await page.setViewportSize({ width: 900, height: 300 });
  await page.goto('file://' + path.resolve(__dirname, 'contrast-test.html'));
  await page.waitForTimeout(300);

  // Contrast check via getComputedStyle + relative luminance
  const contrastInfo = await page.evaluate(() => {
    function luminance(r, g, b) {
      const a = [r, g, b].map(v => {
        v /= 255;
        return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
      });
      return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2];
    }
    function parseRgb(str) {
      const m = str.match(/\d+/g).map(Number);
      return m;
    }
    const btn = document.querySelector('.sv-consent-accept');
    const style = getComputedStyle(btn);
    const bg = parseRgb(style.backgroundColor);
    const fg = parseRgb(style.color);
    const lBg = luminance(...bg);
    const lFg = luminance(...fg);
    const lighter = Math.max(lBg, lFg);
    const darker = Math.min(lBg, lFg);
    const ratio = (lighter + 0.05) / (darker + 0.05);
    return { bg: style.backgroundColor, fg: style.color, ratio: ratio.toFixed(2) };
  });

  console.log('Contraste calculado:', JSON.stringify(contrastInfo));
  await page.screenshot({ path: path.join(__dirname, 'contrast-after.png') });
  await browser.close();
})();
