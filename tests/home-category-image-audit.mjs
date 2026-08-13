import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

const baseUrl = (process.env.BASE_URL || 'https://shopvivaliz.com.br').replace(/\/$/, '');
const outputDir = process.env.OUTPUT_DIR || 'test-results/production-image-audit';
const injectBranchScript = process.env.GITHUB_EVENT_NAME === 'pull_request';
fs.mkdirSync(outputDir, { recursive: true });

const source = fs.readFileSync('js/public-experience-v1.js', 'utf8');
for (const [needle, label] of [
  ["value === '/index.php'", 'suporte a /index.php'],
  ['fetchCatalogPage(page)', 'paginacao do catalogo'],
  ['image.onerror = function', 'fallback para imagem quebrada'],
  ['Nunca reutiliza deliberadamente', 'fallback quando imagem real ja foi usada'],
]) {
  if (!source.includes(needle)) throw new Error(`source_guard_missing:${label}`);
}

const aliasSource = fs.readFileSync('js/category-real-images-v52.js', 'utf8');
for (const [needle, label] of [
  ['/api/catalog/products.php', 'fonte real de produtos'],
  ["'organizacao':['armarios e organizacao']", 'alias de organizacao'],
  ['__svCategoryAliasRepairDone', 'marcador de conclusao'],
]) {
  if (!aliasSource.includes(needle)) throw new Error(`alias_source_guard_missing:${label}`);
}
if (/s3\.amazonaws\.com\/tiny-anexos-us/i.test(aliasSource)) {
  throw new Error('alias_source_guard_historical_urls');
}

const browser = await chromium.launch({ headless: true });

async function audit(name, viewport, isMobile = false, route = '/') {
  const context = await browser.newContext({
    viewport,
    isMobile,
    locale: 'pt-BR',
    userAgent: 'ShopVivaliz-Production-Health/1.0',
    extraHTTPHeaders: { 'Accept-Language': 'pt-BR,pt;q=0.9,en;q=0.8' },
  });
  const page = await context.newPage();

  if (injectBranchScript) {
    await page.route('**/js/category-real-images-v52.js*', (routeHandle) => routeHandle.abort());
    await page.addInitScript({ path: path.resolve('js/public-experience-v1.js') });
    await page.addInitScript({ path: path.resolve('js/category-real-images-v52.js') });
  }

  const response = await page.goto(`${baseUrl}${route}`, { waitUntil: 'domcontentloaded', timeout: 60_000 });
  if (!response || response.status() >= 400) {
    await page.screenshot({ path: path.join(outputDir, `home-http-failure-${name}.png`), fullPage: true }).catch(() => {});
    throw new Error(`home_http_status=${route}:${response?.status() ?? 'none'}`);
  }
  await page.waitForSelector('.home-categories .category-slide img.category-slide-img', { timeout: 45_000 });

  await page.waitForFunction(() => window.__svCategoryAliasRepairDone === true, { timeout: 45_000 });
  await page.waitForFunction(() => {
    const images = Array.from(document.querySelectorAll('.home-categories img.category-slide-img'));
    return images.length >= 5 && images.every((img) => img instanceof HTMLImageElement && !!img.dataset.svCategorySource);
  }, { timeout: 45_000 });

  await page.locator('.home-categories').scrollIntoViewIfNeeded();
  await page.evaluate(() => {
    document.querySelectorAll('.home-categories img.category-slide-img').forEach((img) => {
      if (img instanceof HTMLImageElement) img.loading = 'eager';
    });
  });

  await page.waitForFunction(() => {
    const cards = Array.from(document.querySelectorAll('.home-categories .category-slide'));
    return cards.length >= 5 && cards.every((card) => {
      const img = card.querySelector('img.category-slide-img');
      return img instanceof HTMLImageElement && img.complete;
    });
  }, { timeout: 45_000 });
  await page.waitForTimeout(800);

  const metrics = await page.evaluate(() => {
    const cards = Array.from(document.querySelectorAll('.home-categories .category-slide')).map((card) => {
      const img = card.querySelector('img.category-slide-img');
      const label = String(card.querySelector('strong')?.textContent || '').trim();
      const src = img instanceof HTMLImageElement ? (img.currentSrc || img.src || '') : '';
      return {
        label,
        host: (() => { try { return new URL(src, location.href).host; } catch { return 'invalid-url'; } })(),
        src,
        source: img instanceof HTMLImageElement ? String(img.dataset.svCategorySource || '') : '',
        alt: img instanceof HTMLImageElement ? img.alt : '',
        loaded: img instanceof HTMLImageElement && img.complete && img.naturalWidth > 1 && img.naturalHeight > 1,
        unsplash: /unsplash\.com/i.test(src),
      };
    });
    const real = cards.filter((card) => card.source === 'catalog');
    const fallbacks = cards.filter((card) => card.source === 'local-fallback');
    const duplicateReal = real.filter((card, index, list) => list.findIndex((other) => other.src === card.src) !== index);
    const assigned = cards.filter((card) => card.source === 'catalog' || card.source === 'local-fallback');
    const meaningfulAlt = cards.filter((card) => card.alt.trim().length >= 8);
    return {
      cardCount: cards.length,
      loadedCount: cards.filter((card) => card.loaded).length,
      assignedCount: assigned.length,
      catalogCount: real.length,
      fallbackCount: fallbacks.length,
      catalogCoverage: cards.length ? real.length / cards.length : 0,
      meaningfulAltCount: meaningfulAlt.length,
      unsplashCount: cards.filter((card) => card.unsplash).length,
      duplicateRealCount: duplicateReal.length,
      cards: cards.map(({ src, ...safe }) => safe),
    };
  });

  metrics.assertions = {
    enoughCards: metrics.cardCount >= 5,
    allAssigned: metrics.assignedCount === metrics.cardCount,
    allLoaded: metrics.loadedCount === metrics.cardCount,
    meaningfulAlt: metrics.meaningfulAltCount === metrics.cardCount,
    noUnsplash: metrics.unsplashCount === 0,
    noDuplicateRealImages: metrics.duplicateRealCount === 0,
    realCatalogCoverage: metrics.catalogCoverage >= 0.7,
  };
  metrics.ok = Object.values(metrics.assertions).every(Boolean);
  await page.screenshot({ path: path.join(outputDir, `home-categories-${name}.png`), fullPage: true });
  await context.close();
  return metrics;
}

try {
  const desktop = await audit('desktop', { width: 1440, height: 1000 });
  const mobile = await audit('mobile', { width: 390, height: 844 }, true);
  const directIndex = await audit('direct-index', { width: 1024, height: 900 }, false, '/index.php');
  const report = {
    checkedAt: new Date().toISOString(),
    injectedBranchScript: injectBranchScript,
    desktop,
    mobile,
    directIndex,
    ok: desktop.ok && mobile.ok && directIndex.ok,
  };
  fs.writeFileSync(path.join(outputDir, 'home-categories.json'), `${JSON.stringify(report, null, 2)}\n`, 'utf8');
  console.log(JSON.stringify({
    ok: report.ok,
    injectedBranchScript: injectBranchScript,
    desktop: desktop.assertions,
    mobile: mobile.assertions,
    directIndex: directIndex.assertions,
  }));
  if (!report.ok) throw new Error('home_category_images_failed');
} finally {
  await browser.close();
}
