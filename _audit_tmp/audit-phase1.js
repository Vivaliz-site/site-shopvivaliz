const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const REPORT_PATH = path.join(__dirname, 'audit-phase1-report.md');
const BASE_URL = 'https://shopvivaliz.com.br';

let report = '# 🔍 AUDITORIA FASE 1: MAPEAMENTO E NAVEGAÇÃO EXPLORATÓRIA\n\n';
report += `**Data:** ${new Date().toISOString()}\n`;
report += `**URL Testada:** ${BASE_URL}\n`;
report += `**Navegador:** Playwright + Chromium\n\n`;

let currentPageName = 'INIT';
const consoleLog = []; // { page, type, text }
const failedRequests = []; // { page, url, status, resourceType }
const slowRequests = []; // { page, url, duration }

function attachGlobalListeners(page) {
  page.on('console', msg => {
    consoleLog.push({ page: currentPageName, type: msg.type(), text: msg.text() });
  });
  page.on('pageerror', err => {
    consoleLog.push({ page: currentPageName, type: 'pageerror', text: err.message });
  });
  page.on('requestfailed', req => {
    failedRequests.push({
      page: currentPageName,
      url: req.url(),
      resourceType: req.resourceType(),
      failure: req.failure()?.errorText || 'unknown',
    });
  });
  page.on('response', res => {
    if (res.status() >= 400) {
      failedRequests.push({
        page: currentPageName,
        url: res.url(),
        resourceType: res.request().resourceType(),
        failure: `HTTP ${res.status()}`,
      });
    }
  });
}

async function capturePageMetrics(page, pageName) {
  currentPageName = pageName;
  const metrics = await page.evaluate(() => {
    const perf = performance.getEntriesByType('navigation')[0];
    const resources = performance.getEntriesByType('resource');
    return {
      domContentLoaded: perf?.domContentLoadedEventEnd - perf?.domContentLoadedEventStart,
      loadComplete: perf?.loadEventEnd - perf?.loadEventStart,
      firstPaint: performance.getEntriesByType('paint').find(p => p.name === 'first-paint')?.startTime || 'N/A',
      firstContentfulPaint: performance.getEntriesByType('paint').find(p => p.name === 'first-contentful-paint')?.startTime || 'N/A',
      resourceTiming: resources.length,
      totalTransferSize: resources.reduce((sum, r) => sum + (r.transferSize || 0), 0),
      slowest: resources
        .filter(r => r.duration > 300)
        .map(r => ({ name: r.name.split('/').pop(), duration: Math.round(r.duration) }))
        .sort((a, b) => b.duration - a.duration)
        .slice(0, 5),
    };
  });

  return { metrics };
}

function reportPerf(metrics) {
  report += `### Performance Metrics\n`;
  report += `- DOM Content Loaded: ${metrics.domContentLoaded}ms\n`;
  report += `- Load Complete: ${metrics.loadComplete}ms\n`;
  report += `- First Paint: ${metrics.firstPaint}ms\n`;
  report += `- First Contentful Paint: ${metrics.firstContentfulPaint}ms\n`;
  report += `- Recursos carregados: ${metrics.resourceTiming}\n`;
  report += `- Transfer size total: ${(metrics.totalTransferSize / 1024).toFixed(1)} KB\n`;
  if (metrics.slowest.length > 0) {
    report += `- Recursos mais lentos (>300ms):\n`;
    metrics.slowest.forEach(r => report += `  - ${r.name}: ${r.duration}ms\n`);
  }
  report += '\n';
}

function reportPageErrors(pageName) {
  const errors = consoleLog.filter(m => m.page === pageName && (m.type === 'error' || m.type === 'warning' || m.type === 'pageerror'));
  if (errors.length > 0) {
    report += `### ⚠️ Console Errors/Warnings\n`;
    errors.forEach(err => report += `- [${err.type.toUpperCase()}] ${err.text}\n`);
    report += '\n';
  }
  const failed = failedRequests.filter(f => f.page === pageName);
  if (failed.length > 0) {
    report += `### 🔴 Requisições Falhas (${pageName})\n`;
    failed.forEach(f => report += `- [${f.resourceType}] ${f.url} → ${f.failure}\n`);
    report += '\n';
  }
}

async function auditHomePage(page) {
  console.log('🏠 Auditando HOME...');
  report += '\n## 1️⃣ HOME PAGE\n\n';

  currentPageName = 'HOME';
  await page.goto(BASE_URL, { waitUntil: 'load', timeout: 45000 });
  await page.waitForTimeout(1500);

  const { metrics } = await capturePageMetrics(page, 'HOME');
  reportPerf(metrics);
  reportPageErrors('HOME');

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

  await page.waitForTimeout(1500);
  const { metrics } = await capturePageMetrics(page, 'CATALOG');
  reportPerf(metrics);
  reportPageErrors('CATALOG');

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

  await page.waitForTimeout(1500);
  const { metrics } = await capturePageMetrics(page, 'PDP');
  reportPerf(metrics);
  reportPageErrors('PDP');

  const pdpElements = await page.evaluate(() => {
    return {
      productTitle: document.querySelector('h1')?.textContent.trim() || 'N/A',
      price: document.querySelector('[class*="price"]')?.textContent || 'N/A',
      images: document.querySelectorAll('img[alt*="product" i], img[class*="gallery"]').length,
      addToCartBtn: !!document.querySelector('[class*="add-to-cart"]') || Array.from(document.querySelectorAll('button')).some(b => /adicionar ao carrinho/i.test(b.textContent)),
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
    const addToCartBtn = page.locator('[class*="add-to-cart"], button:has-text("Adicionar ao Carrinho")').first();
    if (await addToCartBtn.count() > 0) {
      await addToCartBtn.click({ timeout: 5000 });
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

  currentPageName = 'CARRINHO';
  if (cartLink) {
    await page.goto(cartLink, { waitUntil: 'load', timeout: 45000 });
  } else {
    await page.goto(`${BASE_URL}/carrinho/`, { waitUntil: 'load', timeout: 45000 });
  }
  await page.waitForTimeout(1500);
  reportPageErrors('CARRINHO');

  const cartElements = await page.evaluate(() => {
    return {
      cartItems: document.querySelectorAll('[class*="cart-item"], tr').length,
      totalPrice: document.querySelector('[class*="total"]')?.textContent || 'N/A',
      checkoutBtn: !!document.querySelector('[class*="checkout"]') || Array.from(document.querySelectorAll('button, a')).some(b => /checkout|finalizar/i.test(b.textContent)),
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

  currentPageName = 'CHECKOUT';
  if (checkoutLink) {
    await page.goto(checkoutLink, { waitUntil: 'load', timeout: 45000 });
  } else {
    await page.goto(`${BASE_URL}/checkout-v2/`, { waitUntil: 'load', timeout: 45000 });
  }
  await page.waitForTimeout(1500);
  reportPageErrors('CHECKOUT');

  const checkoutElements = await page.evaluate(() => {
    return {
      formFields: Array.from(document.querySelectorAll('input')).filter(i => i.type !== 'hidden').length,
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
  attachGlobalListeners(page);

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

  // Screenshots mobile (390x844 - iPhone 12/13) das mesmas páginas visitadas
  report += '\n## 📱 SCREENSHOTS MOBILE (390x844)\n\n';
  const mobilePages = [
    ['home', BASE_URL],
    ['catalogo', `${BASE_URL}/catalogo/`],
    ['produto', page.url().includes('/produto') ? page.url() : `${BASE_URL}/produto/`],
  ];
  await page.setViewportSize({ width: 390, height: 844 });
  for (const [name, url] of mobilePages) {
    try {
      await page.goto(url, { waitUntil: 'load', timeout: 45000 });
      await page.waitForTimeout(1000);
      await page.screenshot({ path: path.join(__dirname, `mobile-${name}.png`), fullPage: true });
      report += `📸 \`mobile-${name}.png\` capturado (${url})\n`;
    } catch (e) {
      report += `❌ Falha ao capturar mobile-${name}: ${e.message}\n`;
    }
  }

  report += '\n---\n\n';
  report += `### 📊 Resumo Final\n`;
  report += `Auditoria concluída. Screenshots e métricas capturadas.\n`;
  report += `Total de erros de console: ${consoleLog.filter(c => c.type === 'error' || c.type === 'pageerror').length}\n`;
  report += `Total de warnings: ${consoleLog.filter(c => c.type === 'warning').length}\n`;
  report += `Total de requisições falhas: ${failedRequests.length}\n`;

  {
    // Salvar relatório
    fs.writeFileSync(REPORT_PATH, report);
    console.log(`\n✅ Relatório salvo em: ${REPORT_PATH}`);

    await browser.close();
  }
}

runAudit().catch(console.error);
