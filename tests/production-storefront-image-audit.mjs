import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

const baseUrl = (process.env.BASE_URL || 'https://shopvivaliz.com.br').replace(/\/$/, '');
const outputDir = process.env.OUTPUT_DIR || 'test-results/production-image-audit';
fs.mkdirSync(outputDir, { recursive: true });

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({
  viewport: { width: 1440, height: 1000 },
  locale: 'pt-BR',
  userAgent: 'ShopVivaliz-Production-Image-Audit/1.0',
});
const page = await context.newPage();

const imageHttpFailures = [];
const requestFailures = [];
page.on('response', (response) => {
  if (response.request().resourceType() === 'image' && response.status() >= 400) {
    imageHttpFailures.push({ status: response.status(), url: response.url() });
  }
});
page.on('requestfailed', (request) => {
  if (request.resourceType() === 'image') {
    requestFailures.push({ url: request.url(), error: request.failure()?.errorText || 'unknown' });
  }
});

let metrics;
try {
  const response = await page.goto(`${baseUrl}/catalogo`, {
    waitUntil: 'domcontentloaded',
    timeout: 60_000,
  });
  if (!response || response.status() >= 400) {
    throw new Error(`catalog_http_status=${response?.status() ?? 'no-response'}`);
  }

  await page.waitForSelector('.product-card .product-image img', { timeout: 45_000 });

  // Trigger native lazy-loading across the full catalog. Recompute the document
  // height on every pass because cards/images can increase it while rendering.
  let previousHeight = 0;
  for (let pass = 0; pass < 3; pass += 1) {
    const documentHeight = await page.evaluate(() => document.documentElement.scrollHeight);
    for (let y = 0; y <= documentHeight; y += 650) {
      await page.evaluate((scrollY) => window.scrollTo(0, scrollY), y);
      await page.waitForTimeout(250);
    }
    if (documentHeight === previousHeight) break;
    previousHeight = documentHeight;
  }

  // Some browsers do not start a native lazy image until that exact element is
  // intersected. Visit every still-incomplete image individually, then wait for
  // either load/error completion. The final assertions remain strict: zero
  // incomplete and zero broken product images are required.
  const imageCount = await page.locator('.product-card .product-image img').count();
  for (let index = 0; index < imageCount; index += 1) {
    const image = page.locator('.product-card .product-image img').nth(index);
    const complete = await image.evaluate((img) => img instanceof HTMLImageElement && img.complete);
    if (complete) continue;
    await image.scrollIntoViewIfNeeded();
    try {
      await page.waitForFunction(
        (position) => {
          const images = Array.from(document.querySelectorAll('.product-card .product-image img'));
          const img = images[position];
          return img instanceof HTMLImageElement && img.complete;
        },
        index,
        { timeout: 15_000 },
      );
    } catch {
      // Keep the image incomplete so the strict assertion below reports it.
    }
  }

  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(1_000);

  metrics = await page.evaluate(() => {
    const cards = Array.from(document.querySelectorAll('.product-card'));
    const images = cards
      .map((card) => card.querySelector('.product-image img'))
      .filter((img) => img instanceof HTMLImageElement);

    const rows = images.map((img) => {
      const src = img.currentSrc || img.src || '';
      const isFallback = src.includes('/images/logo-vivaliz-square-v2.png');
      return {
        src,
        complete: img.complete,
        naturalWidth: img.naturalWidth,
        naturalHeight: img.naturalHeight,
        isFallback,
        loaded: img.complete && img.naturalWidth > 1 && img.naturalHeight > 1,
      };
    });

    return {
      url: location.href,
      title: document.title,
      productCards: cards.length,
      productImages: rows.length,
      loadedProductImages: rows.filter((row) => row.loaded && !row.isFallback).length,
      fallbackProductImages: rows.filter((row) => row.isFallback).length,
      brokenProductImages: rows.filter((row) => row.complete && row.naturalWidth === 0).length,
      incompleteProductImages: rows.filter((row) => !row.complete).length,
      sourceHosts: Array.from(new Set(rows.map((row) => {
        try { return new URL(row.src, location.href).host; } catch { return 'invalid-url'; }
      }))).sort(),
      sample: rows.slice(0, 5).map(({ src, complete, naturalWidth, naturalHeight, isFallback }) => ({
        host: (() => { try { return new URL(src, location.href).host; } catch { return 'invalid-url'; } })(),
        complete,
        naturalWidth,
        naturalHeight,
        isFallback,
      })),
    };
  });

  metrics.imageHttpFailureCount = imageHttpFailures.length;
  metrics.imageRequestFailureCount = requestFailures.length;
  metrics.checkedAt = new Date().toISOString();

  await page.screenshot({
    path: path.join(outputDir, 'catalogo-production.png'),
    fullPage: true,
  });

  const maxFallback = Math.max(2, Math.floor(metrics.productImages * 0.25));
  const assertions = {
    enoughProductCards: metrics.productCards >= 8,
    enoughProductImageElements: metrics.productImages >= 8,
    enoughRealImagesLoaded: metrics.loadedProductImages >= 8,
    fallbackWithinLimit: metrics.fallbackProductImages <= maxFallback,
    noBrokenProductImages: metrics.brokenProductImages === 0,
    noIncompleteProductImages: metrics.incompleteProductImages === 0,
    noImageHttpFailures: metrics.imageHttpFailureCount === 0,
    noImageRequestFailures: metrics.imageRequestFailureCount === 0,
  };
  metrics.assertions = assertions;
  metrics.ok = Object.values(assertions).every(Boolean);

  fs.writeFileSync(
    path.join(outputDir, 'metrics.json'),
    `${JSON.stringify(metrics, null, 2)}\n`,
    'utf8',
  );

  // Deliberately do not print image URLs; host-level evidence is sufficient.
  console.log(JSON.stringify({
    ok: metrics.ok,
    productCards: metrics.productCards,
    productImages: metrics.productImages,
    loadedProductImages: metrics.loadedProductImages,
    fallbackProductImages: metrics.fallbackProductImages,
    brokenProductImages: metrics.brokenProductImages,
    incompleteProductImages: metrics.incompleteProductImages,
    sourceHosts: metrics.sourceHosts,
    imageHttpFailureCount: metrics.imageHttpFailureCount,
    imageRequestFailureCount: metrics.imageRequestFailureCount,
    assertions,
  }));

  if (!metrics.ok) {
    throw new Error('production_product_images_not_rendering');
  }
} finally {
  await browser.close();
}
