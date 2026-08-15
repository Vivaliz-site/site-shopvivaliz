const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const REPORT_PATH = path.join(__dirname, 'audit-phase1-report.md');
const BASE_URL = 'https://shopvivaliz.com.br';

let report = '# 🔍 AUDITORIA FASE 1: MAPEAMENTO E NAVEGAÇÃO EXPLORATÓRIA\n\n';
report += `**Data:** ${new Date().toISOString()}\n`;
report += `**URL Testada:** ${BASE_URL}\n`;
report += `**Navegador:** Playwright + Chromium\n\n`;

async function capturePageMetrics(page, pageName) {
  const metrics = await page.evaluate(() => {
    const perf = performance.getEntriesByType('navigation')[0];
    return {
      domContentLoaded: perf?.domContentLoadedEventEnd - perf?.domContentLoadedEventStart,
      loadComplete: perf?.loadEventEnd - perf?.loadEventStart,
      firstPaint: performance.getEntriesByType('paint')[0]?.startTime || 'N/A',
      resourceTiming: performance.getEntriesByType('resource').length,
    };
  });

  const consoleMessages = [];
  page.on('console', msg => {
    consoleMessages.push({
      type: msg.type(),
      text: msg.text(),
      location: msg.location(),
    });
  });

  return { metrics, consoleMessages };
}

async function auditHomePage(page) {
  console.log('🏠 Auditando HOME...');
  report += '\n## 1️⃣ HOME PAGE\n\n';

  await page.goto(BASE_URL, { waitUntil: 'load', timeout: 45000 });

  const { metrics, consoleMessages } = await capturePageMetrics(page, 'HOME');
  report += `### Performance Metrics\n`;
  report += `- DOM Content Loaded: ${metrics.domContentLoaded}ms\n`;
  report += `- Load Complete: ${metrics.loadComplete}ms\n`;
  report += `- First Paint: ${metrics.firstPaint}ms\n`;
  report += `- Recursos carregados: ${metrics.resourceTiming}\n\n`;

  const errors = consoleMessages.filter(m => m.type === 'error' || m.type === 'warning');
  if (errors.length > 0) {
    report += `### ⚠️ Console Errors/Warnings\n`;
    errors.forEach(err => {
      report += `- [${err.type.toUpperCase()}] ${err.text}\n`;
    });
    report += '\n';
  }

  // Screenshot
  await page.screenshot({ path: path.join(__dirname, 'home-full.png') });
  report += `📸 Screenshot salvo: \`home-full.png\`\n\n`;

  // Verificar elementos críticos
  const homeElements = await page.evaluate(() => {
    return {
      heroExists: !!document.querySelector('[class*="hero"]'),
      searchExists: !!document.querySelector('input[type="search"], input[placeholder*="buscar" i]'),
      productsVisible: document.querySelectorAll('[class*="product"]').length,
      bannerImages: document.querySelectorAll('img[alt*="banner" i]').length,
    };
  });

  report += `### Elementos Críticos Detectados\n`;
  report += `- Hero/Banner: ${homeElements.heroExists ? '✅' : '❌'}\n`;
  report += `- Busca: ${homeElements.searchExists ? '✅' : '❌'}\n`;
  report += `- Produtos visíveis: ${homeElements.productsVisible} elementos\n`;
  report += `- Imagens de banner: ${homeElements.bannerImages}\n\n`;
}

async function auditCatalogPage(page) {
  console.log('📦 Auditando CATÁLOGO...');
  report += '\n## 2️⃣ CATEGORIA / VITRINE\n\n';

  // Navegar para o catálogo
  const catalogLinks = await page.evaluate(() => {
    return Array.from(document.querySelectorAll('a'))
      .filter(a => a.href.includes('/catalogo') || a.textContent.toLowerCase().includes('catálogo'))
      .map(a => a.href);
  });

  if (catalogLinks.length > 0) {
    await page.goto(catalogLinks[0], { waitUntil: 'load', timeout: 45000 });
  } else {
    await page.goto(`${BASE_URL}/catalogo/`, { waitUntil: 'load', timeout: 45000 });
  }

  const { metrics, consoleMessages } = await capturePageMetrics(page, 'CATALOG');
  report += `### Performance Metrics\n`;
  report += `- DOM Content Loaded: ${metrics.domContentLoaded}ms\n`;
  report += `- Load Complete: ${metrics.loadComplete}ms\n\n`;

  const catalogElements = await page.evaluate(() => {
    return {
      productCards: document.querySelectorAll('[class*="product"], [class*="card"]').length,
      filterOptions: document.querySelectorAll('input[type="checkbox"], select').length,
      sortOptions: document.querySelectorAll('[class*="sort"], select[name*="sort" i]').length,
    };
  });

  report += `### Elementos de Vitrine\n`;
  report += `- Cards de produto: ${catalogElements.productCards}\n`;
  report += `- Opções de filtro: ${catalogElements.filterOptions}\n`;
  report += `- Opções de ordenação: ${catalogElements.sortOptions}\n\n`;

  await page.screenshot({ path: path.join(__dirname, 'catalog.png') });
  report += `📸 Screenshot salvo: \`catalog.png\`\n\n`;
}

async function auditProductPage(page) {
  console.log('🛍️ Auditando PDP (Product Detail Page)...');
  report += '\n## 3️⃣ PÁGINA DO PRODUTO (PDP)\n\n';

  // Encontrar o primeiro link de produto e clicar
  const productLink = await page.evaluate(() => {
    const links = Array.from(document.querySelectorAll('a'));
    const productLink = links.find(a =>
      a.href.includes('/produto') ||
      a.closest('[class*="product"], [class*="card"]')?.querySelector('a')
    );
    return productLink?.href;
  });

  if (productLink) {
    await page.goto(productLink, { waitUntil: 'load', timeout: 45000 });
  } else {
    await page.goto(`${BASE_URL}/produto/`, { waitUntil: 'load', timeout: 45000 });
  }

  const { metrics, consoleMessages } = await capturePageMetrics(page, 'PDP');
  report += `### Performance Metrics\n`;
  report += `- DOM Content Loaded: ${metrics.domContentLoaded}ms\n`;
  report += `- Load Complete: ${metrics.loadComplete}ms\n\n`;

  const pdpElements = await page.evaluate(() => {
    return {
      productTitle: document.querySelector('h1')?.textContent.trim() || 'N/A',
      price: document.querySelector('[class*="price"]')?.textContent || 'N/A',
      images: document.querySelectorAll('img[alt*="product" i], img[class*="gallery"]').length,
      addToCartBtn: !!document.querySelector('button:has-text("Adicionar ao Carrinho"), [class*="add-to-cart"]'),
      variationOptions: document.querySelectorAll('select, [class*="variation"], [class*="option"]').length,
    };
  });

  report += `### Elementos de Produto\n`;
  report += `- Título: ${pdpElements.productTitle}\n`;
  report += `- Preço: ${pdpElements.price}\n`;
  report += `- Imagens de produto: ${pdpElements.images}\n`;
  report += `- Botão "Adicionar ao Carrinho": ${pdpElements.addToCartBtn ? '✅' : '❌'}\n`;
  report += `- Opções de variação: ${pdpElements.variationOptions}\n\n`;

  await page.screenshot({ path: path.join(__dirname, 'product-detail.png') });
  report += `📸 Screenshot salvo: \`product-detail.png\`\n\n`;
}

async function auditCart(page) {
  console.log('🛒 Auditando CARRINHO...');
  report += '\n## 4️⃣ CARRINHO\n\n';

  // Tentar clicar em "Adicionar ao Carrinho" se existir
  try {
    const addToCartBtn = await page.$('button:has-text("Adicionar ao Carrinho"), [class*="add-to-cart"]');
    if (addToCartBtn) {
      await addToCartBtn.click();
      await page.waitForTimeout(2000); // Aguardar resposta
    }
  } catch (e) {
    report += `⚠️ Não foi possível clicar em "Adicionar ao Carrinho": ${e.message}\n\n`;
  }

  // Navegar para carrinho
  const cartLink = await page.evaluate(() => {
    const links = Array.from(document.querySelectorAll('a'));
    return links.find(a => a.href.includes('/carrinho') || a.textContent.toLowerCase().includes('carrinho'))?.href;
  });

  if (cartLink) {
    await page.goto(cartLink, { waitUntil: 'load', timeout: 45000 });
  } else {
    await page.goto(`${BASE_URL}/carrinho/`, { waitUntil: 'load', timeout: 45000 });
  }

  const cartElements = await page.evaluate(() => {
    return {
      cartItems: document.querySelectorAll('[class*="cart-item"], tr').length,
      totalPrice: document.querySelector('[class*="total"]')?.textContent || 'N/A',
      checkoutBtn: !!document.querySelector('button:has-text("Checkout"), [class*="checkout"]'),
      quantityInputs: document.querySelectorAll('input[type="number"], [class*="quantity"]').length,
    };
  });

  report += `### Elementos do Carrinho\n`;
  report += `- Itens no carrinho: ${cartElements.cartItems}\n`;
  report += `- Preço total: ${cartElements.totalPrice}\n`;
  report += `- Botão Checkout: ${cartElements.checkoutBtn ? '✅' : '❌'}\n`;
  report += `- Inputs de quantidade: ${cartElements.quantityInputs}\n\n`;

  await page.screenshot({ path: path.join(__dirname, 'cart.png') });
  report += `📸 Screenshot salvo: \`cart.png\`\n\n`;
}

async function auditCheckout(page) {
  console.log('💳 Auditando CHECKOUT...');
  report += '\n## 5️⃣ CHECKOUT\n\n';

  // Tentar navegar para checkout
  const checkoutLink = await page.evaluate(() => {
    const links = Array.from(document.querySelectorAll('a'));
    return links.find(a => a.href.includes('/checkout') || a.textContent.toLowerCase().includes('checkout'))?.href;
  });

  if (checkoutLink) {
    await page.goto(checkoutLink, { waitUntil: 'load', timeout: 45000 });
  } else {
    await page.goto(`${BASE_URL}/checkout-v2/`, { waitUntil: 'load', timeout: 45000 });
  }

  const checkoutElements = await page.evaluate(() => {
    return {
      formFields: document.querySelectorAll('input[type!="hidden"]').length,
      steps: document.querySelectorAll('[class*="step"], [class*="stage"]').length,
      paymentMethods: document.querySelectorAll('[class*="payment"], input[name*="payment"]').length,
      securityBadges: document.querySelectorAll('img[alt*="ssl" i], [class*="security"]').length,
    };
  });

  report += `### Elementos de Checkout\n`;
  report += `- Campos de formulário: ${checkoutElements.formFields}\n`;
  report += `- Etapas visíveis: ${checkoutElements.steps}\n`;
  report += `- Métodos de pagamento: ${checkoutElements.paymentMethods}\n`;
  report += `- Selos de segurança: ${checkoutElements.securityBadges}\n\n`;

  await page.screenshot({ path: path.join(__dirname, 'checkout.png') });
  report += `📸 Screenshot salvo: \`checkout.png\`\n\n`;
}

async function runAudit() {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext();
  const page = await context.newPage();

  // Set viewport para testar responsividade
  await page.setViewportSize({ width: 1440, height: 900 });

  const steps = [
    ['HOME', auditHomePage],
    ['CATALOGO', auditCatalogPage],
    ['PDP', auditProductPage],
    ['CARRINHO', auditCart],
    ['CHECKOUT', auditCheckout],
  ];

  for (const [name, fn] of steps) {
    try {
      await fn(page);
    } catch (error) {
      report += `\n\n❌ ERRO EM ${name}:\n${error.message}\n\n`;
      console.log(`❌ Erro em ${name}: ${error.message}`);
    }
  }

  report += '\n---\n\n';
  report += `### 📊 Resumo Final\n`;
  report += `Auditoria concluída. Screenshots e métricas capturadas.\n`;

  {
    // Salvar relatório
    fs.writeFileSync(REPORT_PATH, report);
    console.log(`\n✅ Relatório salvo em: ${REPORT_PATH}`);

    await browser.close();
  }
}

runAudit().catch(console.error);
