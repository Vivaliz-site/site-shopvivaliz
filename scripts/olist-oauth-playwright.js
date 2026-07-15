/**
 * Automatizar login OAuth Olist com Playwright
 * Simula um usuário fazendo login e autorizando a aplicação
 *
 * Execução: npx playwright install && node olist-oauth-playwright.js
 */

const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const EMAIL = process.env.OLIST_EMAIL || process.env.EMAIL_USER || '';
const SENHA = process.env.OLIST_PASSWORD || process.env.EMAIL_PASSWORD || '';
const SITE_URL = 'https://dev.shopvivaliz.com.br';
const CONNECT_URL = `${SITE_URL}/olist/connect.php`;
const CALLBACK_URL = `${SITE_URL}/olist/callback.php`;
const SYNC_URL = `${SITE_URL}/olist/sync-products.php`;

async function loginOlistAndAuthorize() {
    console.log('[1] Iniciando Playwright...');
    const browser = await chromium.launch({ headless: false });
    const context = await browser.createBrowserContext();
    const page = await context.newPage();

    try {
        // ====================================================================
        // PASSO 1: Acessar connect.php (que redireciona para login Olist)
        // ====================================================================

        console.log('[2] Acessando', CONNECT_URL);
        await page.goto(CONNECT_URL, { waitUntil: 'networkidle' });

        // Aguardar redirecionamento para Olist
        console.log('[3] Aguardando redirecionamento para Olist...');
        await page.waitForNavigation({ waitUntil: 'networkidle', timeout: 10000 });

        const currentUrl = page.url();
        console.log('[4] URL atual:', currentUrl);

        // ====================================================================
        // PASSO 2: Fazer login com email e senha
        // ====================================================================

        if (currentUrl.includes('accounts.tiny.com.br') || currentUrl.includes('id.olist.com')) {
            console.log('[5] Fazendo login na Olist...');

            // Preencher email
            const emailSelector = 'input[type="email"], input[name="email"], input[id="email"], input[placeholder*="email" i]';
            const emailInput = await page.$(emailSelector);
            if (emailInput) {
                await emailInput.fill(EMAIL);
                console.log('   - Email preenchido');
            }

            // Preencher senha
            const senhaSelector = 'input[type="password"], input[name="senha"], input[name="password"]';
            const senhaInput = await page.$(senhaSelector);
            if (senhaInput) {
                await senhaInput.fill(SENHA);
                console.log('   - Senha preenchida');
            }

            // Clicar em login
            const loginBtnSelector = 'button:has-text("Login"), button:has-text("Entrar"), button:has-text("Sign in"), input[type="submit"]';
            const loginBtn = await page.$(loginBtnSelector);
            if (loginBtn) {
                console.log('   - Clicando em Login...');
                await loginBtn.click();
                await page.waitForNavigation({ waitUntil: 'networkidle', timeout: 15000 });
                console.log('   - Login realizado');
            }
        }

        // ====================================================================
        // PASSO 3: Autorizar a aplicação
        // ====================================================================

        console.log('[6] Procurando botão de autorização...');

        const currentUrl2 = page.url();
        console.log('   - URL atual:', currentUrl2);

        // Procurar por botão de autorização/consentimento
        const authBtnSelectors = [
            'button:has-text("Autorizar")',
            'button:has-text("Authorize")',
            'button:has-text("Consentir")',
            'button:has-text("Aceitar")',
            'button[type="submit"]:visible',
            'button:has-text("Permitir")'
        ];

        for (const selector of authBtnSelectors) {
            const btn = await page.$(selector);
            if (btn) {
                console.log('[7] Encontrado botão:', selector);
                console.log('   - Clicando em Autorizar...');
                await btn.click();

                try {
                    await page.waitForNavigation({ waitUntil: 'networkidle', timeout: 10000 });
                    console.log('   - Autorização realizada');
                } catch (e) {
                    console.log('   - Sem redirecionamento imediato (normal)');
                }
                break;
            }
        }

        // ====================================================================
        // PASSO 4: Aguardar callback.php
        // ====================================================================

        console.log('[8] Aguardando redirecionamento para callback.php...');
        let authorization_code = null;

        for (let i = 0; i < 30; i++) {
            const url = page.url();
            console.log(`   - Tentativa ${i + 1}: ${url}`);

            if (url.includes('callback.php')) {
                // Tentar extrair código da página
                const codeBox = await page.$('.code-box, #codeBox, [id*="code"]');
                if (codeBox) {
                    authorization_code = await codeBox.textContent();
                    authorization_code = authorization_code.trim();
                    console.log('[9] Código de autorização encontrado!');
                    console.log('    Código:', authorization_code);
                    break;
                }
            }

            await page.waitForTimeout(1000);
        }

        if (!authorization_code) {
            console.log('[AVISO] Codigo de autorização não encontrado');
            console.log('        Verifique manualmente em', CALLBACK_URL);
        }

        // ====================================================================
        // PASSO 5: Acessar sync-products.php para sincronizar
        // ====================================================================

        console.log('[10] Acessando sync-products.php para sincronizar...');
        await page.goto(SYNC_URL, { waitUntil: 'networkidle', timeout: 30000 });

        console.log('[11] Aguardando sincronização (pode levar alguns minutos)...');

        // Aguardar resultado
        for (let i = 0; i < 60; i++) {
            const content = await page.content();

            if (content.includes('"sucesso": true') || content.includes('198')) {
                console.log('[SUCESSO] Sincronização completada!');
                console.log(content.substring(0, 500));
                break;
            }

            if (i % 10 === 0) {
                console.log(`   - Aguardando... (${i}s)`);
            }

            await page.waitForTimeout(1000);
        }

        // ====================================================================
        // RESULTADO
        // ====================================================================

        const finalUrl = page.url();
        const bodyText = await page.textContent('body');

        console.log('\n' + '='.repeat(70));
        console.log('RESULTADO FINAL');
        console.log('='.repeat(70));
        console.log('URL final:', finalUrl);
        console.log('Resposta:', bodyText?.substring(0, 300));

        // Salvar resultado
        const resultFile = path.join(__dirname, '../logs/olist-oauth-resultado.json');
        const result = {
            timestamp: new Date().toISOString(),
            sucesso: bodyText?.includes('"sucesso": true'),
            authorization_code,
            url_final: finalUrl,
            resposta: bodyText?.substring(0, 1000)
        };

        require('fs').mkdirSync(path.dirname(resultFile), { recursive: true });
        require('fs').writeFileSync(resultFile, JSON.stringify(result, null, 2));
        console.log('Resultado salvo em:', resultFile);

    } catch (error) {
        console.error('[ERRO]', error.message);
    } finally {
        await context.close();
        await browser.close();
    }
}

// Executar
loginOlistAndAuthorize().then(() => {
    console.log('\n[CONCLUÍDO] Script finalizado');
    process.exit(0);
}).catch(err => {
    console.error('[ERRO CRÍTICO]', err);
    process.exit(1);
});
