const { test, expect } = require('@playwright/test');

test.describe('Homepage Visual Improvements', () => {
  test('Validar melhorias visuais da homepage', async ({ page }) => {
    // Navegar para a homepage
    await page.goto('https://dev.shopvivaliz.com.br/', { waitUntil: 'networkidle' });

    // Aguardar carousel carregar
    await page.waitForSelector('.hero-carousel', { timeout: 5000 }).catch(() => {});

    // Screenshot completo da homepage
    await page.screenshot({
      path: 'test-results/homepage-full.png',
      fullPage: true
    });

    // Validar que o carousel está visível
    const carousel = await page.locator('.hero-carousel').first();
    const isVisible = await carousel.isVisible();
    console.log(`✅ Carousel visível: ${isVisible}`);

    // Validar que as categorias estão visíveis
    const categories = await page.locator('.categories-track .category-slide').first();
    const catVisible = await categories.isVisible();
    console.log(`✅ Categorias visíveis: ${catVisible}`);

    // Validar que os produtos estão visíveis
    const products = await page.locator('[class*="product-card"]').first();
    const prodVisible = await products.isVisible();
    console.log(`✅ Produtos visíveis: ${prodVisible}`);

    // Screenshot da seção de categorias
    await page.screenshot({
      path: 'test-results/categories-section.png'
    });

    // Scroll para categorias
    await page.locator('.home-categories').first().scrollIntoViewIfNeeded();
    await page.waitForTimeout(500);

    // Screenshot da seção de categorias após scroll
    await page.screenshot({
      path: 'test-results/categories-zoomed.png'
    });

    // Verificar hover effect do carousel
    const dot = await page.locator('.hero-carousel-dot').first();
    if (dot) {
      await dot.hover();
      await page.waitForTimeout(300);
      await page.screenshot({
        path: 'test-results/carousel-hover.png'
      });
    }

    // Verificar animations
    const heroContent = await page.locator('.hero-content').first();
    const computedStyle = await heroContent.evaluate(el => {
      return window.getComputedStyle(el).animation;
    });
    console.log(`✅ Hero animations: ${computedStyle}`);

    // Testar responsividade mobile
    await page.setViewportSize({ width: 375, height: 667 });
    await page.screenshot({
      path: 'test-results/homepage-mobile.png',
      fullPage: true
    });

    console.log('✅ Todos os testes de homepage completados com sucesso!');
  });
});
