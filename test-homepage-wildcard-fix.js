const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch({ headless: false });
  const page = await browser.newPage();

  console.log('[TESTE] Abrindo homepage...');
  await page.goto('http://dev.shopvivaliz.com.br', { waitUntil: 'networkidle' });

  console.log('[TESTE] Aguardando conteúdo carregar...');
  await page.waitForTimeout(2000);

  // Screenshot
  await page.screenshot({ path: 'test-screenshot-wildcard-fix.png' });
  console.log('OK: Screenshot salvo');

  // Verificações
  const categoryCount = await page.locator('.category-slide').count();
  const productCount = await page.locator('.product-card').count();
  const heroVisible = await page.locator('.hero-carousel').isVisible();

  console.log(`CATEGORIAS: ${categoryCount}`);
  console.log(`PRODUTOS: ${productCount}`);
  console.log(`HERO VISÍVEL: ${heroVisible}`);

  // Verificar CSS version
  const cssHref = await page.locator('link[href*="visual-enhancements.css"]').first().getAttribute('href');
  console.log(`CSS VERSION: ${cssHref}`);

  await browser.close();
  console.log('TESTE COMPLETO');
})();
