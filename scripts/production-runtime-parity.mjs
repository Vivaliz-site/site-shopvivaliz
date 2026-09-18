import { chromium } from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';

const baseUrl = (process.env.E2E_BASE_URL || 'https://shopvivaliz.com.br').replace(/\/$/, '');
const proxyServer = process.env.E2E_PROXY_SERVER || '';
const outDir = path.resolve('artifacts/public-layout-audit');
const evidence = { release: null, steps: [], pageErrors: [], consoleErrors: [], requestFailures: [], serverErrors: [] };

function pass(name, detail = {}) {
  evidence.steps.push({ name, ok: true, ...detail });
  console.log('PASS ' + name);
}

function fail(name, detail) {
  evidence.steps.push({ name, ok: false, detail: String(detail) });
  throw new Error(name + ': ' + detail);
}

function expectedConsoleErrorReason({ text, url }) {
  let parsed = null;
  try { parsed = url ? new URL(url) : null; } catch {}
  const analyticsProxy = (parsed?.origin === new URL(baseUrl).origin && parsed.pathname.startsWith('/0fb1/')) || text.includes(baseUrl + '/0fb1/');
  if (analyticsProxy && (text.includes('ERR_NETWORK_CHANGED') || text.includes("MIME type ('text/plain')"))) return 'analytics-proxy-transient';
  if (parsed?.hostname === 'fonts.gstatic.com' && text.includes('ERR_NETWORK_CHANGED')) return 'external-font-network-change';
  return '';
}

function expectedRequestFailureReason({ url, error, resourceType }) {
  let parsed;
  try { parsed = new URL(url); } catch { return ''; }
  const sameOrigin = parsed.origin === new URL(baseUrl).origin;
  if (error === 'net::ERR_NETWORK_CHANGED' && resourceType === 'font' && parsed.hostname === 'fonts.gstatic.com') return 'external-font-network-change';
  if (error === 'net::ERR_ABORTED') {
    if (resourceType === 'image' && parsed.hostname === 's3.amazonaws.com' && parsed.pathname.startsWith('/tiny-anexos-us/erp/')) return 'navigation-aborted-product-image';
    if (['www.google-analytics.com', 'www.googletagmanager.com'].includes(parsed.hostname)) return 'navigation-aborted-analytics';
    if (sameOrigin && (parsed.pathname.startsWith('/0fb1/') || parsed.pathname === '/cdn-cgi/rum')) return 'navigation-aborted-analytics-proxy';
    if (parsed.hostname === 'api.mercadopago.com' && ['/v1/payment_methods/search', '/v1/device_sessions/web_device', '/v1/devices/widgets'].includes(parsed.pathname)) return 'checkout-reload-aborted-mercadopago-probe';
    if (parsed.hostname === 'api.mercadolibre.com' && parsed.pathname === '/tracks') return 'checkout-navigation-aborted-tracking';
    if (['www.mercadolibre.com', 'www.mercadopago.com.br'].includes(parsed.hostname) && parsed.pathname.startsWith('/jms/lgz/')) return 'checkout-reload-aborted-fingerprint';
  }
  if (error === 'net::ERR_BLOCKED_BY_ORB' && parsed.hostname === 'www.mercadopago.com.br' && parsed.pathname.startsWith('/jms/lgz/')) return 'headless-fingerprint-orb';
  return '';
}

await fs.mkdir(outDir, { recursive: true });
const browser = await chromium.launch({
  headless: true,
  ...(proxyServer ? { proxy: { server: proxyServer } } : {}),
});
const context = await browser.newContext({ viewport: { width: 1365, height: 900 } });
const page = await context.newPage();
page.on('pageerror', error => evidence.pageErrors.push(String(error)));
page.on('console', message => {
  if (message.type() !== 'error') return;
  const location = message.location();
  const item = { text: message.text(), url: location?.url || '' };
  const expectedReason = expectedConsoleErrorReason(item);
  evidence.consoleErrors.push({ ...item, expected: expectedReason !== '', expectedReason });
});
page.on('requestfailed', request => {
  const item = { url: request.url(), error: request.failure()?.errorText || '', resourceType: request.resourceType() };
  const expectedReason = expectedRequestFailureReason(item);
  evidence.requestFailures.push({ ...item, expected: expectedReason !== '', expectedReason });
});
page.on('response', response => {
  if (response.status() >= 500) evidence.serverErrors.push({ url: response.url(), status: response.status() });
});

try {
  const versionResponse = await page.request.get(baseUrl + '/api/health/version.php');
  const version = await versionResponse.json();
  evidence.release = version.release_sha || null;
  if (!version.ok || !evidence.release) fail('release_provenance', 'release SHA missing');
  pass('release_provenance', { sha: evidence.release });

  await page.goto(baseUrl + '/', { waitUntil: 'domcontentloaded' });
  const firstProduct = page.locator('#product-grid .product-card a.card-link').first();
  await firstProduct.waitFor({ state: 'visible' });
  await firstProduct.click();
  await page.waitForLoadState('domcontentloaded');
  pass('ui_home_to_product', { url: page.url() });

  const buy = page.locator('#buy-now');
  await buy.waitFor({ state: 'visible' });
  const sku = await buy.getAttribute('data-sku');
  await buy.click();
  await page.waitForURL(/\/carrinho/);
  await page.locator('.cart-item').first().waitFor({ state: 'visible' });
  let cart = await page.evaluate(() => JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]'));
  if (!cart.length) fail('ui_add_to_cart', 'cart did not persist');
  pass('ui_add_to_cart', { sku, items: cart.length });

  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.locator('.cart-item').first().waitFor({ state: 'visible' });
  cart = await page.evaluate(() => JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]'));
  if (!cart.some(item => String(item.sku || '') === String(sku || ''))) fail('cart_reload_persistence', 'SKU missing after reload');
  pass('cart_reload_persistence', { items: cart.length });

  await page.locator('#frete-cep').fill('35500006');
  await page.locator('#btn-frete').click();
  await page.locator('.sv-shipping-option').first().waitFor({ state: 'visible', timeout: 30000 });
  const quote = await page.evaluate(() => JSON.parse(localStorage.getItem('shopvivaliz_shipping_quote') || 'null'));
  if (!quote || !(Number(quote.total) > 0) || !quote.quote_id) fail('ui_real_shipping', 'invalid real shipping quote');
  pass('ui_real_shipping', { provider: quote.provider || '', totalPositive: true, hasQuoteId: true });

  await page.locator('#btn-checkout').click();
  await page.waitForURL(/\/checkout/, { timeout: 30000 });
  await page.locator('#checkout-form').waitFor({ state: 'attached' });
  pass('ui_cart_to_checkout');
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.locator('#checkout-form').waitFor({ state: 'attached' });
  const persisted = await page.evaluate(() => ({
    cart: JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]'),
    quote: JSON.parse(localStorage.getItem('shopvivaliz_shipping_quote') || 'null'),
  }));
  if (!persisted.cart.length || !persisted.quote || !(Number(persisted.quote.total) > 0)) fail('checkout_reload_persistence', 'cart/quote lost');
  pass('checkout_reload_persistence', { items: persisted.cart.length });

  const payment = page.locator('input[name="payment_method"][value="mercado_pago"]').first();
  if (!(await payment.count())) fail('payment_option_visible', 'mercado_pago option missing');
  await payment.check({ force: true });
  pass('payment_option_visible_no_submit');

  await page.screenshot({ path: path.join(outDir, 'runtime-parity-checkout.png'), fullPage: true });
  const unexpectedConsoleErrors = evidence.consoleErrors.filter(item => !item.expected);
  const unexpectedRequestFailures = evidence.requestFailures.filter(item => !item.expected);
  if (evidence.pageErrors.length) fail('pageerror', evidence.pageErrors.join(' | '));
  if (unexpectedConsoleErrors.length) fail('console_error', JSON.stringify(unexpectedConsoleErrors));
  if (unexpectedRequestFailures.length) fail('requestfailed', JSON.stringify(unexpectedRequestFailures));
  if (evidence.serverErrors.length) fail('server_5xx', JSON.stringify(evidence.serverErrors));
  pass('browser_runtime_errors', {
    expectedConsoleErrors: evidence.consoleErrors.filter(item => item.expected).length,
    expectedRequestAborts: evidence.requestFailures.filter(item => item.expected).length,
  });
  console.log('PRODUCTION_RUNTIME_PARITY=PASS');
} catch (error) {
  evidence.fatal = String(error?.stack || error);
  console.error('PRODUCTION_RUNTIME_PARITY=FAIL ' + (error?.message || error));
  process.exitCode = 1;
} finally {
  await fs.writeFile(path.join(outDir, 'runtime-parity-evidence.json'), JSON.stringify(evidence, null, 2) + '\n');
  await browser.close();
}
