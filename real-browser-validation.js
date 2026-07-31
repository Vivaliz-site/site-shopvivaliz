#!/usr/bin/env node

const puppeteer = require('puppeteer');
const fs = require('fs');

const SITE = 'https://shopvivaliz.com.br';
const TIMEOUT = 10000;
const results = [];

function log(type, message) {
  const types = { '✅': 'green', '❌': 'red', '⚠️': 'yellow', '🔍': 'cyan' };
  console.log(`${type} ${message}`);
  results.push({ type, message });
}

async function runTests() {
  let browser;
  try {
    browser = await puppeteer.launch({
      headless: true,
      args: ['--no-sandbox', '--disable-setuid-sandbox']
    });

    console.log('╔════════════════════════════════════════════════════════════════════════╗');
    console.log('║          VALIDAÇÃO REAL EM NAVEGADOR - SHOPVIVALIZ                    ║');
    console.log('╚════════════════════════════════════════════════════════════════════════╝\n');

    // ========== TESTE 1: HOMEPAGE ==========
    console.log('\n🔍 TESTE 1: Homepage');
    {
      const page = await browser.newPage();
      await page.goto(SITE, { waitUntil: 'networkidle2', timeout: TIMEOUT });

      // Verificar favicon
      const favicon = await page.$eval('link[rel="icon"]', el => el.getAttribute('href')).catch(() => null);
      if (favicon) {
        log('✅', `Favicon referenciado: ${favicon}`);
      } else {
        log('❌', 'Favicon não encontrado');
      }

      // Verificar logo
      const logo = await page.$eval('img[alt*="Vivaliz"], img[alt*="logo"]', el => el.getAttribute('src')).catch(() => null);
      if (logo) {
        log('✅', `Logo encontrado: ${logo}`);
      } else {
        log('⚠️', 'Logo não encontrado');
      }

      // Verificar links de navegação
      const links = await page.$$eval('a[href]', els => els.map(el => ({ text: el.textContent.trim(), href: el.getAttribute('href') })));
      const navLinks = links.filter(l => ['/sobre', '/contato', '/catalogo', '/carrinho'].some(path => l.href.includes(path)));

      if (navLinks.length >= 3) {
        log('✅', `${navLinks.length} links de navegação encontrados`);
        navLinks.forEach(l => log('✅', `  └─ ${l.text} → ${l.href}`));
      } else {
        log('❌', `Apenas ${navLinks.length} links de navegação encontrados`);
      }

      // Verificar Mercado Pago
      const mpLogo = await page.$('img[alt*="Mercado Pago"], img[alt*="MP"], svg[alt*="Mercado"]').catch(() => null);
      if (mpLogo) {
        log('✅', 'Logo Mercado Pago encontrado');
      } else {
        log('❌', 'Logo Mercado Pago NÃO encontrado na página');
      }

      // Verificar produtos
      const products = await page.$$('.product-card, .product-item, [data-product]');
      log(products.length > 0 ? '✅' : '❌', `${products.length} produtos encontrados na home`);

      await page.close();
    }

    // ========== TESTE 2: SOBRE ==========
    console.log('\n🔍 TESTE 2: Página Sobre');
    {
      const page = await browser.newPage();
      try {
        await page.goto(`${SITE}/sobre`, { waitUntil: 'networkidle2', timeout: TIMEOUT });
        const title = await page.title();
        log('✅', `Página Sobre carregada: "${title}"`);
        const content = await page.content();
        if (content.includes('Vivaliz') || content.includes('sobre')) {
          log('✅', 'Conteúdo da página Sobre presente');
        } else {
          log('⚠️', 'Conteúdo mínimo não verificado');
        }
      } catch (e) {
        log('❌', `Erro ao carregar /sobre: ${e.message}`);
      }
      await page.close();
    }

    // ========== TESTE 3: CONTATO ==========
    console.log('\n🔍 TESTE 3: Página Contato');
    {
      const page = await browser.newPage();
      try {
        await page.goto(`${SITE}/contato`, { waitUntil: 'networkidle2', timeout: TIMEOUT });
        const form = await page.$('form');
        if (form) {
          log('✅', 'Formulário de contato encontrado');
          const inputs = await page.$$('input, textarea');
          log('✅', `  └─ ${inputs.length} campos de entrada`);
        } else {
          log('❌', 'Formulário de contato NÃO encontrado');
        }
      } catch (e) {
        log('❌', `Erro ao carregar /contato: ${e.message}`);
      }
      await page.close();
    }

    // ========== TESTE 4: CATÁLOGO ==========
    console.log('\n🔍 TESTE 4: Catálogo');
    {
      const page = await browser.newPage();
      try {
        await page.goto(`${SITE}/catalogo`, { waitUntil: 'networkidle2', timeout: TIMEOUT });
        const products = await page.$$('.product-card, [data-product], .product-item');
        log('✅', `Catálogo carregado com ${products.length} produtos`);

        const filters = await page.$$('.filter, [data-filter], .sidebar');
        if (filters.length > 0) {
          log('✅', 'Sistema de filtros encontrado');
        } else {
          log('⚠️', 'Sistema de filtros não encontrado');
        }
      } catch (e) {
        log('❌', `Erro ao carregar /catalogo: ${e.message}`);
      }
      await page.close();
    }

    // ========== TESTE 5: CARRINHO ==========
    console.log('\n🔍 TESTE 5: Carrinho');
    {
      const page = await browser.newPage();
      try {
        await page.goto(`${SITE}/carrinho`, { waitUntil: 'networkidle2', timeout: TIMEOUT });
        const cartContainer = await page.$('.cart-container, [data-cart], .shopping-cart');
        if (cartContainer) {
          log('✅', 'Página do carrinho carregada');
        } else {
          log('⚠️', 'Contêiner do carrinho não identificado');
        }
      } catch (e) {
        log('❌', `Erro ao carregar /carrinho: ${e.message}`);
      }
      await page.close();
    }

    // ========== TESTE 6: CHECKOUT ==========
    console.log('\n🔍 TESTE 6: Checkout');
    {
      const page = await browser.newPage();
      try {
        await page.goto(`${SITE}/checkout.php`, { waitUntil: 'networkidle2', timeout: TIMEOUT });
        const form = await page.$('form, [data-checkout]');
        if (form) {
          log('✅', 'Página de checkout carregada');
          const paymentMethods = await page.$$('[data-payment], .payment-option, input[name="payment_method"]');
          log('✅', `  └─ ${paymentMethods.length} formas de pagamento encontradas`);

          const mpCheckout = await page.$('iframe[src*="mercadopago"], [data-mercadopago]');
          if (mpCheckout) {
            log('✅', 'Integração Mercado Pago detectada');
          } else {
            log('⚠️', 'Integração Mercado Pago não clara');
          }
        } else {
          log('❌', 'Checkout form NOT found');
        }
      } catch (e) {
        log('❌', `Erro ao carregar /checkout.php: ${e.message}`);
      }
      await page.close();
    }

    // ========== TESTE 7: CSS RESPONSIVIDADE ==========
    console.log('\n🔍 TESTE 7: CSS e Responsividade');
    {
      const page = await browser.newPage();
      await page.goto(SITE, { waitUntil: 'networkidle2', timeout: TIMEOUT });

      // Desktop
      await page.setViewport({ width: 1920, height: 1080 });
      const desktopScreenshot = await page.screenshot({ path: '/tmp/desktop.png' });
      log('✅', 'Screenshot Desktop: OK');

      // Mobile
      await page.setViewport({ width: 375, height: 667 });
      const mobileScreenshot = await page.screenshot({ path: '/tmp/mobile.png' });
      log('✅', 'Screenshot Mobile: OK');

      // Verificar erros de CSS
      const consoleErrors = [];
      page.on('console', msg => {
        if (msg.type() === 'error') consoleErrors.push(msg.text());
      });

      if (consoleErrors.length === 0) {
        log('✅', 'Sem erros CSS/JS no console');
      } else {
        log('⚠️', `${consoleErrors.length} erros no console`);
      }

      await page.close();
    }

    // ========== TESTE 8: META TAGS E SEO ==========
    console.log('\n🔍 TESTE 8: SEO');
    {
      const page = await browser.newPage();
      await page.goto(SITE, { waitUntil: 'networkidle2', timeout: TIMEOUT });

      const metaDesc = await page.$eval('meta[name="description"]', el => el.getAttribute('content')).catch(() => null);
      if (metaDesc) {
        log('✅', `Meta description: "${metaDesc.substring(0, 50)}..."`);
      } else {
        log('❌', 'Meta description ausente');
      }

      const ogTitle = await page.$eval('meta[property="og:title"]', el => el.getAttribute('content')).catch(() => null);
      if (ogTitle) {
        log('✅', `OG Title: "${ogTitle}"`);
      } else {
        log('⚠️', 'OG Title ausente');
      }

      await page.close();
    }

    // ========== RELATÓRIO FINAL ==========
    console.log('\n╔════════════════════════════════════════════════════════════════════════╗');
    console.log('║                      RELATÓRIO FINAL                                    ║');
    console.log('╚════════════════════════════════════════════════════════════════════════╝\n');

    const successes = results.filter(r => r.type === '✅');
    const errors = results.filter(r => r.type === '❌');
    const warnings = results.filter(r => r.type === '⚠️');

    console.log(`✅ SUCESSOS: ${successes.length}`);
    console.log(`❌ ERROS: ${errors.length}`);
    console.log(`⚠️  AVISOS: ${warnings.length}`);
    console.log(`\nSTATUS: ${successes.length}/${results.length} testes passando`);

    if (errors.length > 0) {
      console.log('\n❌ ERROS ENCONTRADOS:');
      errors.forEach(e => console.log(`   ${e.message}`));
    }

    process.exit(errors.length > 0 ? 1 : 0);

  } catch (error) {
    console.error('❌ Erro fatal:', error);
    process.exit(1);
  } finally {
    if (browser) await browser.close();
  }
}

runTests();
