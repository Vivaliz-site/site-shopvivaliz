#!/usr/bin/env node
/**
 * Teste Visual com Playwright - Homepage ShopVivaliz
 * Tira screenshot e verifica estrutura DOM renderizada
 */

import { chromium } from 'playwright';
import fs from 'fs';
import path from 'path';

const SITE_URL = 'https://dev.shopvivaliz.com.br/?nocache=1';
const SCREENSHOT_DIR = './playwright-report/screenshots';
const REPORT_FILE = './playwright-report/visual-test-report.json';

// Criar diretório de screenshots
if (!fs.existsSync(SCREENSHOT_DIR)) {
  fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

async function runTest() {
  const browser = await chromium.launch();
  const context = await browser.newContext();
  const page = await context.newPage();

  console.log('🌐 Abrindo página:', SITE_URL);
  await page.goto(SITE_URL, { waitUntil: 'networkidle' });

  // ✅ Screenshot 1: Visual completo
  const screenshotPath = path.join(SCREENSHOT_DIR, `homepage-${Date.now()}.png`);
  await page.screenshot({ path: screenshotPath, fullPage: true });
  console.log('📸 Screenshot capturado:', screenshotPath);

  // ✅ Verificação 1: HTML Structure
  console.log('\n📋 Verificando Estrutura do DOM...');
  const domChecks = await page.evaluate(() => {
    const checks = {
      timestamp: new Date().toISOString(),
      url: window.location.href,
      title: document.title,

      // Elementos críticos
      heroCarousel: {
        exists: !!document.querySelector('.hero-carousel-section'),
        elementCount: document.querySelectorAll('[class*="hero-carousel"]').length,
        banners: document.querySelectorAll('.hero-carousel-section img').length
      },

      homeCategories: {
        exists: !!document.querySelector('.home-categories'),
        categoryCount: document.querySelectorAll('.category-card, [class*="category"]').length,
        svgPresent: !!document.querySelector('svg'),
      },

      products: {
        productCards: document.querySelectorAll('.product-card, [class*="product"]').length,
        images: document.querySelectorAll('.product-card img, [class*="product"] img').length,
      },

      navigation: {
        navbar: !!document.querySelector('.navbar'),
        navLinks: document.querySelectorAll('.navbar a').length,
      },

      footer: {
        footer: !!document.querySelector('footer'),
        footerLinks: document.querySelectorAll('footer a').length,
      },

      // Verificar estilos computados
      heroComputedStyle: {
        display: window.getComputedStyle(document.querySelector('.hero-carousel-section') || {}).display,
        backgroundColor: window.getComputedStyle(document.querySelector('.hero-carousel-section') || {}).backgroundColor,
      }
    };
    return checks;
  });

  console.log(JSON.stringify(domChecks, null, 2));

  // ✅ Verificação 2: Erros de Console
  console.log('\n🐛 Console Errors:');
  page.on('console', msg => {
    if (msg.type() === 'error') {
      console.log('  ❌', msg.text());
    }
  });

  page.on('pageerror', error => {
    console.log('  ❌ Page Error:', error.message);
  });

  // ✅ Verificação 3: Recursos (CSS/JS)
  console.log('\n📦 Verificando Recursos...');
  const resourceChecks = await page.evaluate(() => {
    const stylesheets = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
      .map(el => el.href);
    const scripts = Array.from(document.querySelectorAll('script[src]'))
      .map(el => el.src);

    return {
      stylesheets,
      scripts,
      cssLoaded: stylesheets.length > 0,
      jsLoaded: scripts.length > 0,
    };
  });

  console.log('Stylesheets:', resourceChecks.stylesheets);
  console.log('Scripts:', resourceChecks.scripts);

  // ✅ Verificação 4: Viewport e Layout
  console.log('\n📐 Layout Info:');
  const layoutInfo = await page.evaluate(() => {
    const hero = document.querySelector('.hero-carousel-section');
    const categories = document.querySelector('.home-categories');

    return {
      viewport: {
        width: window.innerWidth,
        height: window.innerHeight,
      },
      heroElement: hero ? {
        offsetHeight: hero.offsetHeight,
        offsetWidth: hero.offsetWidth,
        display: window.getComputedStyle(hero).display,
        visibility: window.getComputedStyle(hero).visibility,
      } : null,
      categoriesElement: categories ? {
        offsetHeight: categories.offsetHeight,
        offsetWidth: categories.offsetWidth,
        display: window.getComputedStyle(categories).display,
        visibility: window.getComputedStyle(categories).visibility,
      } : null,
    };
  });

  console.log(JSON.stringify(layoutInfo, null, 2));

  // ✅ Salvar relatório completo
  const report = {
    timestamp: new Date().toISOString(),
    url: SITE_URL,
    domChecks,
    resourceChecks,
    layoutInfo,
    screenshotPath,
  };

  fs.writeFileSync(REPORT_FILE, JSON.stringify(report, null, 2));
  console.log('\n✅ Relatório salvo em:', REPORT_FILE);

  // Esperar um pouco para ver a página
  console.log('\n⏱️  Mantendo página aberta por 10 segundos...');
  await page.waitForTimeout(10000);

  await browser.close();
  console.log('\n✅ Teste concluído!');

  // Exibir resultado
  console.log('\n📊 RESULTADO FINAL:');
  console.log('  Screenshot:', screenshotPath);
  console.log('  Relatório:', REPORT_FILE);
}

runTest().catch(err => {
  console.error('❌ Erro:', err);
  process.exit(1);
});
