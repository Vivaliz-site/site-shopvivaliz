# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: autenticacao.spec.ts >> Autenticação >> página de registro deve estar acessível
- Location: tests\autenticacao.spec.ts:17:7

# Error details

```
Error: expect(page).toHaveTitle(expected) failed

Expected pattern: /Cadastro.*ShopVivaliz/i
Received string:  ""
Timeout: 5000ms

Call log:
  - Expect "toHaveTitle" with timeout 5000ms
    14 × unexpected value ""

```

# Test source

```ts
  1  | import { test, expect } from './fixtures';
  2  | 
  3  | test.describe('Autenticação', () => {
  4  |   const baseUrl = process.env.E2E_BASE_URL || 'https://dev.shopvivaliz.com.br';
  5  | 
  6  |   test('página de login deve estar acessível', async ({ page }) => {
  7  |     await page.goto(`${baseUrl}/auth/login.php`, { waitUntil: 'networkidle' });
  8  | 
  9  |     // Verificar elementos da página
  10 |     await expect(page).toHaveTitle(/Login.*ShopVivaliz/i);
  11 |     await expect(page.locator('text=Acesse sua conta')).toBeVisible();
  12 |     await expect(page.locator('input[type="email"]')).toBeVisible();
  13 |     await expect(page.locator('input[type="password"]')).toBeVisible();
  14 |     await expect(page.locator('button[type="submit"]')).toBeVisible();
  15 |   });
  16 | 
  17 |   test('página de registro deve estar acessível', async ({ page }) => {
  18 |     await page.goto(`${baseUrl}/auth/register.php`, { waitUntil: 'networkidle' });
  19 | 
  20 |     // Verificar elementos
> 21 |     await expect(page).toHaveTitle(/Cadastro.*ShopVivaliz/i);
     |                        ^ Error: expect(page).toHaveTitle(expected) failed
  22 |     await expect(page.locator('text=Crie sua conta')).toBeVisible();
  23 |     await expect(page.locator('input[name="name"]')).toBeVisible();
  24 |     await expect(page.locator('input[name="email"]')).toBeVisible();
  25 |     await expect(page.locator('input[name="password"]')).toBeVisible();
  26 |     await expect(page.locator('input[name="password_confirm"]')).toBeVisible();
  27 |   });
  28 | 
  29 |   test('registro com dados inválidos deve mostrar erro', async ({ page }) => {
  30 |     await page.goto(`${baseUrl}/auth/register.php`);
  31 | 
  32 |     // Tentar com nome vazio
  33 |     await page.fill('input[name="email"]', 'test@example.com');
  34 |     await page.fill('input[name="password"]', 'password123');
  35 |     await page.fill('input[name="password_confirm"]', 'password123');
  36 |     await page.click('button[type="submit"]');
  37 | 
  38 |     // Deve mostrar erro
  39 |     const errorMsg = page.locator('.error, [role="alert"]');
  40 |     await expect(errorMsg).toBeVisible({ timeout: 5000 }).catch(() => {});
  41 |   });
  42 | 
  43 |   test('validação de senha deve exigir mínimo 8 caracteres', async ({ page }) => {
  44 |     await page.goto(`${baseUrl}/auth/register.php`);
  45 | 
  46 |     await page.fill('input[name="name"]', 'Test User');
  47 |     await page.fill('input[name="email"]', 'test@example.com');
  48 |     await page.fill('input[name="password"]', 'short');
  49 |     await page.fill('input[name="password_confirm"]', 'short');
  50 |     await page.click('button[type="submit"]');
  51 | 
  52 |     // Deve mostrar erro de senha fraca
  53 |     const errorMsg = page.locator('.error, [role="alert"]');
  54 |     const text = await errorMsg.textContent().catch(() => '');
  55 |     expect(text?.toLowerCase()).toContain('8 caracteres');
  56 |   });
  57 | 
  58 |   test('botões de Google e Apple OAuth devem estar presentes', async ({ page }) => {
  59 |     await page.goto(`${baseUrl}/auth/login.php`);
  60 | 
  61 |     // Procurar por botões OAuth
  62 |     const googleBtn = page.locator('text=Google').first();
  63 |     const appleBtn = page.locator('text=Apple').first();
  64 | 
  65 |     // Pelo menos um deve estar presente
  66 |     const googleVisible = await googleBtn.isVisible().catch(() => false);
  67 |     const appleVisible = await appleBtn.isVisible().catch(() => false);
  68 | 
  69 |     console.log(`[INFO] Google OAuth: ${googleVisible}, Apple OAuth: ${appleVisible}`);
  70 |   });
  71 | 
  72 |   test('links de redirecionamento devem funcionar', async ({ page }) => {
  73 |     await page.goto(`${baseUrl}/auth/login.php`);
  74 | 
  75 |     // Link para registro
  76 |     const registerLink = page.locator('a:has-text("Cadastre-se")');
  77 |     await registerLink.click();
  78 | 
  79 |     await expect(page).toHaveURL(/register\.php/);
  80 |   });
  81 | });
  82 | 
```