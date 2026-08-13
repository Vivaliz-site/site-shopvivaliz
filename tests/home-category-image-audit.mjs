import fs from 'node:fs';
import path from 'node:path';
import { chromium } from 'playwright';

const baseUrl = (process.env.BASE_URL || 'https://shopvivaliz.com.br').replace(/\/$/, '');
const outputDir = process.env.OUTPUT_DIR || 'test-results/production-image-audit';
const injectBranchScript = process.env.GITHUB_EVENT_NAME === 'pull_request';
fs.mkdirSync(outputDir, { recursive: true });

const browser = await chromium.launch({ headless: true });

async function audit(name, viewport, isMobile = false) {
  const context = await browser.newContext({ viewport, isMobile, locale: 'pt-BR' });
  const page = await context.newPage();
  const response = await page.goto(`${baseUrl}/`, { waitUntil: 'domcontentloaded', timeout: 60_000 });
  if (!response || response.status() >= 400) throw new Error(`home_http_status=${response?.status() ?? 'none'}`);
  await page.waitForSelector('.home-categories .category-slide img.category-slide-img', { timeout: 45_000 });

  if (injectBranchScript) {
    await page.evaluate(() => { window.__svPublicExperienceInitialized = false; });
    await page.addScriptTag({ path: path.resolve('js/public-experience-v1.js') });
  }

  await page.waitForFunction(() => {
    const cards = Array.from(document.querySelectorAll('.home-categories .category-slide'));
    return cards.length >= 5 && cards.every((card) => {
      const img = card.querySelector('img.category-slide-img');
      return img instanceof HTMLImageElement && img.complete;
    });
  }, { timeout: 45_000 });
  await page.locator('.home-categories').scrollIntoViewIfNeeded();
  await page.waitForTimeout(1200);

  const metrics = await page.evaluate(() => {
    const norm = (value) => String(value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    const cards = Array.from(document.querySelectorAll('.home-categories .category-slide')).map((card) => {
      const img = card.querySelector('img.category-slide-img');
      const label = String(card.querySelector('strong')?.textContent || '').trim();
      const src = img instanceof HTMLImageElement ? (img.currentSrc || img.src || '') : '';
      return {
        label,
        normalizedLabel: norm(label),
        host: (() => { try { return new URL(src, location.href).host; } catch { return 'invalid-url'; } })(),
        src,
        source: img instanceof HTMLImageElement ? String(img.dataset.svCategorySource || '') : '',
        alt: img instanceof HTMLImageElement ? img.alt : '',
        loaded: img instanceof HTMLImageElement && img.complete && img.naturalWidth > 1 && img.naturalHeight > 1,
        unsplash: /unsplash\.com/i.test(src),
      };
    });
    const real = cards.filter((card) => card.source === 'catalog');
    const duplicateReal = real.filter((card, index, list) => list.findIndex((other) => other.src === card.src) !== index);
    const toolbox = cards.find((card) => card.normalizedLabel.includes('caixa') && card.normalizedLabel.includes('ferrament'));
    const wheels = cards.find((card) => card.normalizedLabel.includes('roda') && card.normalizedLabel.includes('carrinho'));
    return {
      cardCount: cards.length,
      loadedCount: cards.filter((card) => card.loaded).length,
      unsplashCount: cards.filter((card) => card.unsplash).length,
      duplicateRealCount: duplicateReal.length,
      toolbox: toolbox ? { label: toolbox.label, source: toolbox.source, loaded: toolbox.loaded, alt: toolbox.alt } : null,
      wheels: wheels ? { label: wheels.label, source: wheels.source, loaded: wheels.loaded, alt: wheels.alt } : null,
      cards: cards.map(({ src, ...safe }) => safe),
    };
  });

  metrics.assertions = {
    enoughCards: metrics.cardCount >= 5,
    allLoaded: metrics.loadedCount === metrics.cardCount,
    noUnsplash: metrics.unsplashCount === 0,
    noDuplicateRealImages: metrics.duplicateRealCount === 0,
    toolboxReal: !!metrics.toolbox && metrics.toolbox.source === 'catalog' && metrics.toolbox.loaded,
    wheelsReal: !!metrics.wheels && metrics.wheels.source === 'catalog' && metrics.wheels.loaded,
    toolboxAlt: !!metrics.toolbox && metrics.toolbox.alt.length >= 8,
    wheelsAlt: !!metrics.wheels && metrics.wheels.alt.length >= 8,
  };
  metrics.ok = Object.values(metrics.assertions).every(Boolean);
  await page.screenshot({ path: path.join(outputDir, `home-categories-${name}.png`), fullPage: true });
  await context.close();
  return metrics;
}

try {
  const desktop = await audit('desktop', { width: 1440, height: 1000 });
  const mobile = await audit('mobile', { width: 390, height: 844 }, true);
  const report = { checkedAt: new Date().toISOString(), injectedBranchScript: injectBranchScript, desktop, mobile, ok: desktop.ok && mobile.ok };
  fs.writeFileSync(path.join(outputDir, 'home-categories.json'), `${JSON.stringify(report, null, 2)}\n`, 'utf8');
  console.log(JSON.stringify({ ok: report.ok, injectedBranchScript: injectBranchScript, desktop: desktop.assertions, mobile: mobile.assertions }));
  if (!report.ok) throw new Error('home_category_images_failed');
} finally {
  await browser.close();
}
