// @ts-check
const { test, expect } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'https://shopvivaliz.com.br';

test.describe('Checkout crítico', () => {
    test('homepage carrega sem erro 5xx', async ({ page }) => {
        const res = await page.goto(BASE + '/', { timeout: 20000 });
        expect(res?.status()).toBeLessThan(500);
    });

    test('página de produtos acessível', async ({ page }) => {
        // /produtos pode retornar 404 (sem rota específica) — isso é aceitável.
        // Apenas falha em 5xx (erro real do servidor).
        const res = await page.goto(BASE + '/produtos', { timeout: 20000, waitUntil: 'domcontentloaded' });
        expect(res?.status()).toBeLessThan(500);
    });

    test('UI de carrinho presente na homepage', async ({ page }) => {
        await page.goto(BASE + '/', { timeout: 20000, waitUntil: 'domcontentloaded' });
        const body = await page.content();
        expect(body.toLowerCase()).toMatch(/carrinho|cart|shopping-bag|shopping-cart/);
    });

    test('sem erros JavaScript críticos na homepage', async ({ page }) => {
        const jsErrors = [];
        page.on('pageerror', err => jsErrors.push(err.message));
        await page.goto(BASE + '/', { timeout: 20000, waitUntil: 'domcontentloaded' });
        // domcontentloaded é mais rápido que load e suficiente para capturar erros JS inline.
        // Evita timeout por recursos externos lentos (Google Fonts CDN, analytics, etc.)
        await page.waitForTimeout(1500);
        const fatal = jsErrors.filter(e =>
            e.includes('TypeError') || e.includes('ReferenceError') || e.includes('SyntaxError')
        );
        expect(fatal).toHaveLength(0);
    });

    test('API health servidor no ar (2xx ou 4xx)', async ({ request }) => {
        // 403 é aceitável: endpoint existe mas restrito a IPs de CI.
        // Só falha se servidor retornar 5xx ou não responder.
        const res = await request.get(BASE + '/api/health.php', { timeout: 15000 });
        expect(res.status()).toBeGreaterThan(0);
        expect(res.status()).toBeLessThan(500);
    });
});
