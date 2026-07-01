// @ts-check
const { test, expect } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'https://shopvivaliz.com.br';

test.describe('Checkout crítico', () => {
    test('homepage carrega sem erro 5xx', async ({ page }) => {
        const res = await page.goto(BASE + '/');
        expect(res?.status()).toBeLessThan(500);
    });

    test('página de produtos acessível', async ({ page }) => {
        const res = await page.goto(BASE + '/produtos');
        expect(res?.status()).toBeLessThan(500);
    });

    test('carrinho acessível', async ({ page }) => {
        const res = await page.goto(BASE + '/carrinho');
        expect(res?.status()).toBeLessThan(500);
        // deve conter elemento do carrinho
        const body = await page.content();
        expect(body.toLowerCase()).toMatch(/carrinho|cart|checkout/);
    });

    test('sem erros JavaScript críticos na homepage', async ({ page }) => {
        const jsErrors = [];
        page.on('pageerror', err => jsErrors.push(err.message));
        await page.goto(BASE + '/');
        await page.waitForLoadState('networkidle');
        const fatal = jsErrors.filter(e =>
            e.includes('TypeError') || e.includes('ReferenceError') || e.includes('SyntaxError')
        );
        expect(fatal).toHaveLength(0);
    });

    test('API health retorna 200 ou 204', async ({ request }) => {
        const res = await request.get(BASE + '/api/health.php');
        expect([200, 204]).toContain(res.status());
    });
});
