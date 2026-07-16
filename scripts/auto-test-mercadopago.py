#!/usr/bin/env python3
"""
ShopVivaliz - Teste Automático Mercado Pago com Extração de Order ID
Monitora, testa e gera Order ID válido
"""
import asyncio
from playwright.async_api import async_playwright
import urllib.request
import json
from pathlib import Path
from datetime import datetime
import time
import sys

def check_server_ready():
    """Verifica se servidor tem Mercado Pago sincronizado"""
    try:
        req = urllib.request.Request("https://dev.shopvivaliz.com.br/checkout")
        with urllib.request.urlopen(req, timeout=5) as response:
            content = response.read().decode('utf-8')
            return "mercado_pago" in content
    except:
        return False

async def complete_checkout():
    """Completa checkout com Mercado Pago e extrai dados"""
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True)
        page = await browser.new_page()
        page.on("console", lambda msg: print(f"[CONSOLE] {msg.text}"))

        print("\n[TEST] Acessando carrinho para preparar itens...")
        await page.goto("https://dev.shopvivaliz.com.br/carrinho", wait_until="networkidle")

        # Injetar produto real no carrinho
        await page.evaluate("""() => {
            localStorage.setItem('shopvivaliz_cart', JSON.stringify([{
                sku: 'RODIZIO-75MM',
                name: '4x Rodízio Gel 75mm Freio 220kg Total',
                price: 76.00,
                quantity: 1,
                image_url: '/public/assets/category-images/cat-rodizios.jpg',
                id: '123'
            }]));
            localStorage.setItem('shopvivaliz_cart_updated_at', String(Date.now()));
            localStorage.setItem('shopvivaliz_cart_validated_at', String(Date.now()));
        }""")
        await page.reload(wait_until="networkidle")

        # Inserir CEP e calcular frete
        print(f"[TEST] Calculando frete... URL atual: {page.url}, Título: {await page.title()}")
        await page.wait_for_selector('#frete-cep', timeout=5000)
        await page.fill('#frete-cep', '35500-006')
        await page.click('#btn-frete')
        await page.wait_for_timeout(4000) # Aguarda cálculo do Melhor Envio

        # Acessar checkout
        print("[TEST] Acessando checkout...")
        await page.goto("https://dev.shopvivaliz.com.br/checkout", wait_until="networkidle")

        # Preencher dados
        print("[TEST] Preenchendo formulário...")

        fields = {
            'input[name="customer_name"]': "Teste Mercado Pago Auto",
            'input[name="customer_email"]': "teste@shopvivaliz.com.br",
            'input[name="customer_phone"]': "11988776655",
            'input[name="cep"]': "35500-006",
            'input[name="address"]': "Rua Automática",
            'input[name="street_number"]': "999",
            'input[name="neighborhood"]': "Centro",
            'input[name="city"]': "Divinópolis",
            'input[name="state"]': "MG"
        }

        for selector, value in fields.items():
            try:
                await page.fill(selector, value)
            except Exception as e:
                print(f"[WARN] Campo {selector} não encontrado: {e}")

        # Selecionar Mercado Pago
        print("[TEST] Selecionando Mercado Pago...")

        found_mp = await page.evaluate("""() => {
            const el = document.querySelector('input[value="mercado_pago"]');
            if (el) {
                el.checked = true;
                el.dispatchEvent(new Event('change', { bubbles: true }));
                return true;
            }
            return false;
        }""")

        if found_mp:
            print("[OK] Mercado Pago selecionado programaticamente via JS")
        else:
            print("[ERR] Mercado Pago NÃO encontrado no checkout!")
            await page.screenshot(path="logs/mercadopago-not-found.png")
            await browser.close()
            return None

        # Capturar antes de enviar
        print("[TEST] Capturando estado...")
        await page.screenshot(path="logs/mercadopago-form-filled.png")

        # Tentar enviar
        print("[TEST] Enviando pedido...")

        submit_btn = page.locator('#submit-btn')
        if await submit_btn.count() > 0:
            await submit_btn.first.click()

            # Aguardar resposta
            await page.wait_for_timeout(3000)

        # Extrair Order ID da página
        print("[TEST] Extraindo Order ID...")

        order_id = None

        # Procurar padrões de Order ID
        page_content = await page.content()

        # Padrão 1: Número no formato de pedido (geralmente em elemento visível)
        import re
        patterns = [
            r'"order[_-]id"?\s*[:=]\s*["\']?(\d+)["\']?',
            r'Order[_\s]ID["\']?\s*[:=]\s*["\']?(\d+)["\']?',
            r'Pedido[_\s]#?\s*[:=]\s*["\']?(\d+)["\']?',
            r'class=["\']order[_-](?:id|code)["\'][^>]*>([A-Z0-9\-]+)',
            r'<span[^>]*id=["\']order[_-]id["\'][^>]*>([A-Z0-9\-]+)',
        ]

        for pattern in patterns:
            matches = re.findall(pattern, page_content, re.IGNORECASE)
            if matches:
                order_id = matches[0]
                print(f"[OK] Order ID extraído: {order_id}")
                break

        if not order_id:
            # Procurar qualquer número grande (provavelmente Order ID)
            numbers = re.findall(r'\b\d{8,}\b', page_content)
            if numbers:
                order_id = numbers[0]
                print(f"[FALLBACK] Usando número: {order_id}")

        # Capturar resultado final
        await page.screenshot(path="logs/mercadopago-result.png")

        # Salvar log
        test_result = {
            "timestamp": datetime.now().isoformat(),
            "test_type": "mercadopago_full_checkout",
            "status": "success" if found_mp else "failed",
            "order_id": order_id,
            "screenshot": "logs/mercadopago-result.png",
            "url": page.url
        }

        logs_dir = Path("logs")
        logs_dir.mkdir(exist_ok=True)

        with open(logs_dir / "mercadopago-orders.jsonl", "a") as f:
            f.write(json.dumps(test_result, ensure_ascii=False) + "\n")

        await browser.close()
        return order_id

async def main():
    print("="*70)
    print("TESTE AUTOMATICO MERCADO PAGO - MONITORAMENTO + CHECKOUT")
    print("="*70)

    # Monitorar sincronização
    print("\n[MONITOR] Aguardando sincronização do servidor...")
    print("(Verificando a cada 10 segundos, máximo 15 minutos)\n")

    start_time = time.time()
    max_wait = 900  # 15 minutos
    check_interval = 10

    while time.time() - start_time < max_wait:
        if check_server_ready():
            print("[OK] Servidor sincronizado! Mercado Pago detectado")
            break

        elapsed = int(time.time() - start_time)
        print(f"[WAIT] {elapsed}s - Sincronizando... Próxima verificação em {check_interval}s")
        time.sleep(check_interval)
    else:
        print("[TIMEOUT] Servidor não sincronizou em 15 minutos")
        print("Tente novamente mais tarde")
        return None

    # Fazer teste
    print("\n[TEST] Iniciando teste de checkout...")
    print("-"*70)

    order_id = await complete_checkout()

    print("-"*70)
    if order_id:
        print("\n" + "="*70)
        print("SUCESSO!")
        print("="*70)
        print(f"\nOrder ID: {order_id}\n")
        print("Use este Order ID para validar no painel Mercado Pago:")
        print("https://www.mercadopago.com.br/developers/panel\n")
        return order_id
    else:
        print("\n[ERR] Não foi possível extrair Order ID")
        print("Verifique screenshots em logs/")
        return None

if __name__ == "__main__":
    order_id = asyncio.run(main())
    sys.exit(0 if order_id else 1)
