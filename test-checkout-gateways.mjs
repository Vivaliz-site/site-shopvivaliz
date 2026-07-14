#!/usr/bin/env node
import { chromium } from 'playwright';

const SITE_URL = 'https://dev.shopvivaliz.com.br/checkout?nocache=1';

async function runTest() {
  const browser = await chromium.launch();
  const context = await browser.newContext();
  const page = await context.newPage();

  console.log('🌐 Abrindo página de checkout:', SITE_URL);
  await page.goto(SITE_URL, { waitUntil: 'networkidle' });

  const result = await page.evaluate(() => {
    const options = Array.from(document.querySelectorAll('input[name="payment_method"]'))
      .map(input => input.value);
    const visibleTexts = Array.from(document.querySelectorAll('.payment-opt-box strong'))
      .map(el => el.textContent.trim());

    return {
      options,
      visibleTexts,
      hasMercadoPago: options.includes('mercado_pago')
    };
  });

  console.log('📋 Opções de pagamento encontradas:', JSON.stringify(result, null, 2));

  await browser.close();
  if (!result.hasMercadoPago) {
    console.error('❌ Falha: Mercado Pago não encontrado!');
    process.exit(1);
  } else {
    console.log('✅ Sucesso: Mercado Pago está visível!');
  }
}

runTest().catch(console.error);
