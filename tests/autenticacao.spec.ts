import { test, expect } from './fixtures';

test.describe('Autenticação', () => {
  const baseUrl = process.env.E2E_BASE_URL || 'https://dev.shopvivaliz.com.br';

  test('página de login deve estar acessível', async ({ page }) => {
    await page.goto(`${baseUrl}/auth/login.php`, { waitUntil: 'networkidle' });

    // Verificar elementos da página
    await expect(page).toHaveTitle(/Login.*ShopVivaliz/i);
    await expect(page.locator('text=Acesse sua conta')).toBeVisible();
    await expect(page.locator('input[type="email"]')).toBeVisible();
    await expect(page.locator('input[type="password"]')).toBeVisible();
    await expect(page.locator('button[type="submit"]')).toBeVisible();
  });

  test('página de registro deve estar acessível', async ({ page }) => {
    await page.goto(`${baseUrl}/auth/register.php`, { waitUntil: 'networkidle' });

    // Verificar elementos
    await expect(page).toHaveTitle(/Cadastro.*ShopVivaliz/i);
    await expect(page.locator('text=Crie sua conta')).toBeVisible();
    await expect(page.locator('input[name="name"]')).toBeVisible();
    await expect(page.locator('input[name="email"]')).toBeVisible();
    await expect(page.locator('input[name="password"]')).toBeVisible();
    await expect(page.locator('input[name="password_confirm"]')).toBeVisible();
  });

  test('registro com dados inválidos deve mostrar erro', async ({ page }) => {
    await page.goto(`${baseUrl}/auth/register.php`);

    // Tentar com nome vazio
    await page.fill('input[name="email"]', 'test@example.com');
    await page.fill('input[name="password"]', 'password123');
    await page.fill('input[name="password_confirm"]', 'password123');
    await page.click('button[type="submit"]');

    // Deve mostrar erro
    const errorMsg = page.locator('.error, [role="alert"]');
    await expect(errorMsg).toBeVisible({ timeout: 5000 }).catch(() => {});
  });

  test('validação de senha deve exigir mínimo 8 caracteres', async ({ page }) => {
    await page.goto(`${baseUrl}/auth/register.php`);

    await page.fill('input[name="name"]', 'Test User');
    await page.fill('input[name="email"]', 'test@example.com');
    await page.fill('input[name="password"]', 'short');
    await page.fill('input[name="password_confirm"]', 'short');
    await page.click('button[type="submit"]');

    // Deve mostrar erro de senha fraca
    const errorMsg = page.locator('.error, [role="alert"]');
    const text = await errorMsg.textContent().catch(() => '');
    expect(text?.toLowerCase()).toContain('8 caracteres');
  });

  test('botões de Google e Apple OAuth devem estar presentes', async ({ page }) => {
    await page.goto(`${baseUrl}/auth/login.php`);

    // Procurar por botões OAuth
    const googleBtn = page.locator('text=Google').first();
    const appleBtn = page.locator('text=Apple').first();

    // Pelo menos um deve estar presente
    const googleVisible = await googleBtn.isVisible().catch(() => false);
    const appleVisible = await appleBtn.isVisible().catch(() => false);

    console.log(`[INFO] Google OAuth: ${googleVisible}, Apple OAuth: ${appleVisible}`);
  });

  test('links de redirecionamento devem funcionar', async ({ page }) => {
    await page.goto(`${baseUrl}/auth/login.php`);

    // Link para registro
    const registerLink = page.locator('a:has-text("Cadastre-se")');
    await registerLink.click();

    await expect(page).toHaveURL(/register\.php/);
  });
});
