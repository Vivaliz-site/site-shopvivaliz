const { test, expect } = require('@playwright/test');

const baseURL = process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:8000';

const seededCart = [
  {
    sku: 'SKU-900',
    name: 'Kit Presente Lavanda',
    image_url: '/favicon.ico',
    price: 149.9,
    quantity: 2,
    olist_product_id: '900',
  },
];

test.describe('AutoDev checkout flow', () => {
  test('finaliza pedido com carrinho persistido e payload valido', async ({ page }) => {
    let orderRequestBody = null;

    await page.route('**/api/orders/create.php', async route => {
      orderRequestBody = JSON.parse(route.request().postData() || '{}');
      await route.fulfill({
        status: 200,
        contentType: 'application/json; charset=utf-8',
        body: JSON.stringify({
          ok: true,
          order_number: 'SVTEST123',
          status: 'pending_confirmation',
          message: 'Pedido registrado para confirmacao manual de frete e pagamento.',
        }),
      });
    });

    await page.goto(`${baseURL}/checkout.php`);
    await page.evaluate(items => {
      localStorage.setItem('shopvivaliz_cart', JSON.stringify(items));
    }, seededCart);
    await page.reload();

    await expect(page.getByRole('heading', { name: 'Finalizar pedido' })).toBeVisible();
    await expect(page.locator('#cart-items')).toContainText('Kit Presente Lavanda');
    await expect(page.locator('#cart-total')).toContainText('299,80');

    await page.locator('[name="customer_name"]').fill('Maria da Silva');
    await page.locator('[name="customer_email"]').fill('maria@example.com');
    await page.locator('[name="customer_phone"]').fill('(37) 99999-8888');
    await page.locator('[name="cep"]').fill('35500025');
    await expect(page.locator('[name="cep"]')).toHaveValue('35500-025');
    await page.locator('[name="address"]').fill('Rua das Flores, 123');
    await page.locator('[name="notes"]').fill('Apartamento 42');

    await page.getByRole('button', { name: 'Enviar pedido' }).click();

    await expect(page.locator('#checkout-status')).toContainText('Pedido SVTEST123 registrado.');
    await expect(page.locator('#cart-items')).toContainText('Seu carrinho está vazio.');
    await expect(page.locator('#cart-total')).toContainText('Preço sob consulta');

    expect(orderRequestBody).toMatchObject({
      customer_name: 'Maria da Silva',
      customer_email: 'maria@example.com',
      customer_phone: '(37) 99999-8888',
      cep: '35500025',
      address: 'Rua das Flores, 123',
      notes: 'Apartamento 42',
    });
    expect(orderRequestBody.items).toEqual(seededCart);
  });

  test('valida CEP antes de tentar enviar pedido', async ({ page }) => {
    let orderRequestCount = 0;

    await page.route('**/api/orders/create.php', async route => {
      orderRequestCount += 1;
      await route.abort();
    });

    await page.goto(`${baseURL}/checkout.php`);
    await page.evaluate(items => {
      localStorage.setItem('shopvivaliz_cart', JSON.stringify(items));
    }, seededCart);
    await page.reload();

    await page.locator('[name="customer_name"]').fill('Teste AutoDev');
    await page.locator('[name="customer_email"]').fill('teste@example.com');
    await page.locator('[name="customer_phone"]').fill('31999999999');
    await page.locator('[name="cep"]').fill('12345');
    await page.locator('[name="address"]').fill('Rua Teste, 100');

    await page.getByRole('button', { name: 'Enviar pedido' }).click();

    await expect(page.locator('#checkout-status')).toContainText('Informe um CEP válido com 8 dígitos.');
    expect(orderRequestCount).toBe(0);
  });
});
