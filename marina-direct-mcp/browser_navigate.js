// Opens one http(s) URL in the service account's headless Chromium (headful Chromium
// does not complete navigation on this host's GPU-less Xvfb) and prints a single JSON line. Never prints page content other than title/URL/PNG.
const { chromium } = require('playwright');

(async () => {
  const [url, timeoutMs, profileDir] = process.argv.slice(2);
  const context = await chromium.launchPersistentContext(profileDir, {
    headless: true,
    acceptDownloads: false,
    args: ['--no-sandbox'],
  });
  try {
    const page = await context.newPage();
    await page.goto(url, { timeout: Number(timeoutMs) });
    const png = await page.screenshot({ type: 'png' });
    console.log(JSON.stringify({
      title: await page.title(),
      final_url: page.url(),
      screenshot_png_base64: png.toString('base64'),
    }));
  } finally {
    await context.close();
  }
})().catch((error) => {
  console.error(error && error.name ? error.name : 'Error');
  process.exit(1);
});
