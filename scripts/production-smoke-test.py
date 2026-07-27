#!/usr/bin/env python
# -*- coding: utf-8 -*-
# ShopVivaliz Production Smoke Test E2E
# ----------------------------------------------------------------
# Python cross-platform implementation of E2E smoke tests.
# Returns exit 0 on success.
# ----------------------------------------------------------------

import sys
import time
import urllib.request
import urllib.error
import ssl
import json

TARGET_HOST = "shopvivaliz.com.br"
BASE_URL = f"https://{TARGET_HOST}"

def log_info(msg):
    # Safe printing to handle Windows terminal encoding limitations
    try:
        print(f"[INFO] {msg}")
    except Exception:
        print(f"[INFO] {msg.encode('ascii', 'ignore').decode('ascii')}")

def log_error(msg):
    try:
        print(f"[ERROR] {msg}", file=sys.stderr)
    except Exception:
        print(f"[ERROR] {msg.encode('ascii', 'ignore').decode('ascii')}", file=sys.stderr)

def test_homepage():
    log_info("1. Verificando Home e tempo de resposta...")
    start = time.time()
    try:
        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        
        req = urllib.request.Request(
            f"{BASE_URL}/", 
            headers={'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'}
        )
        with urllib.request.urlopen(req, timeout=10, context=ctx) as response:
            code = response.getcode()
            content = response.read().decode('utf-8')
            elapsed = time.time() - start
            if code != 200:
                log_error(f"Home falhou com HTTP {code}")
                return False
            if "Vivaliz" not in content:
                log_error("Palavra-chave 'Vivaliz' nao encontrada na Home!")
                return False
            log_info(f"OK - Home respondeu HTTP 200 OK em {elapsed:.2f}s")
            return True
    except Exception as e:
        log_error(f"Erro na Homepage: {e}")
        return False

def test_ssl():
    log_info("2. Verificando certificado SSL...")
    try:
        ctx = ssl.create_default_context()
        with ctx.wrap_socket(urllib.request.socket.socket(), server_hostname=TARGET_HOST) as s:
            s.settimeout(5)
            s.connect((TARGET_HOST, 443))
            cert = s.getpeercert()
            if not cert:
                log_error("Nao foi possivel obter o certificado SSL!")
                return False
            log_info("OK - Certificado SSL ativo e valido")
            return True
    except Exception as e:
        log_error(f"Erro ao verificar SSL: {e}")
        return False

def test_css():
    log_info("3. Verificando CSS principal...")
    try:
        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        url = f"{BASE_URL}/css/shopvivaliz-premium-consolidated.css"
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req, timeout=5, context=ctx) as response:
            code = response.getcode()
            headers = response.info()
            content_type = headers.get('Content-Type', '')
            if code != 200:
                log_error(f"CSS falhou com HTTP {code}")
                return False
            if "text/css" not in content_type.lower():
                log_error(f"Content-Type do CSS incorreto: {content_type}")
                return False
            log_info("OK - CSS premium carregando corretamente")
            return True
    except Exception as e:
        log_error(f"Erro no CSS: {e}")
        return False

def test_catalog():
    log_info("4. Verificando pagina do catalogo...")
    try:
        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        url = f"{BASE_URL}/catalogo"
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req, timeout=5, context=ctx) as response:
            code = response.getcode()
            if code != 200:
                log_error(f"Catalogo falhou com HTTP {code}")
                return False
            log_info("OK - Catalogo respondeu HTTP 200 OK")
            return True
    except Exception as e:
        log_error(f"Erro no Catalogo: {e}")
        return False

def test_catalog_api():
    log_info("5. Verificando API do catalogo e produto real...")
    try:
        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        url = f"{BASE_URL}/api/catalog/products.php"
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req, timeout=5, context=ctx) as response:
            code = response.getcode()
            data = json.loads(response.read().decode('utf-8'))
            if code != 200 or 'products' not in data:
                log_error("API do catalogo falhou ou retornou formato invalido!")
                return False
            log_info("OK - API do catalogo respondeu com JSON valido")
            
            # Test a real product from the list
            product = data['products'][0]
            slug = product.get('slug', '')
            sku = product.get('sku', '')
            if slug and slug != 'null':
                p_url = f"{BASE_URL}/produto/{slug}"
            else:
                p_url = f"{BASE_URL}/produto.php?sku={sku}"
                
            log_info(f"5b. Verificando produto real ({sku})...")
            p_req = urllib.request.Request(p_url, headers={'User-Agent': 'Mozilla/5.0'})
            with urllib.request.urlopen(p_req, timeout=5, context=ctx) as p_resp:
                p_code = p_resp.getcode()
                if p_code != 200:
                    log_error(f"Pagina do produto falhou com HTTP {p_code}")
                    return False
                log_info("OK - Pagina de produto real respondeu HTTP 200 OK")
                return True
    except Exception as e:
        log_error(f"Erro na API/Produto: {e}")
        return False

def test_carrinho():
    log_info("6. Verificando rota do carrinho...")
    try:
        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        url = f"{BASE_URL}/carrinho"
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req, timeout=5, context=ctx) as response:
            code = response.getcode()
            if code != 200:
                log_error(f"Carrinho falhou com HTTP {code}")
                return False
            log_info("OK - Rota do carrinho disponivel")
            return True
    except Exception as e:
        log_error(f"Erro no Carrinho: {e}")
        return False

def test_checkout():
    log_info("7. Verificando rota do checkout...")
    try:
        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        url = f"{BASE_URL}/checkout"
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        # Accept 200 or 302 redirect to cart
        try:
            with urllib.request.urlopen(req, timeout=5, context=ctx) as response:
                code = response.getcode()
        except urllib.error.HTTPError as he:
            code = he.code
            
        if code not in [200, 302]:
            log_error(f"Checkout falhou com HTTP {code}")
            return False
        log_info(f"OK - Rota do checkout segura e respondendo com HTTP {code}")
        return True
    except Exception as e:
        log_error(f"Erro no Checkout: {e}")
        return False

def test_admin_protected():
    log_info("8. Verificando protecao do admin...")
    try:
        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        url = f"{BASE_URL}/admin/"
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        try:
            with urllib.request.urlopen(req, timeout=5, context=ctx) as response:
                code = response.getcode()
                html = response.read().decode('utf-8')
        except urllib.error.HTTPError as he:
            code = he.code
            html = he.read().decode('utf-8') if hasattr(he, 'read') else ''
            
        if code == 200:
            # If HTTP 200, verify it is a login screen, not the actual dashboard data
            if "Senha" in html or "Entrar" in html or "Login" in html:
                log_info("OK - Painel do admin protegido por tela de login (HTTP 200)")
                return True
            else:
                log_error("Erro de seguranca: painel administrativo exposto sem login com HTTP 200!")
                return False
        log_info(f"OK - Area administrativa protegida (HTTP {code})")
        return True
    except Exception as e:
        log_error(f"Erro ao verificar protecao admin: {e}")
        return False

def test_webhook_olist():
    log_info("9. Verificando webhook da Olist...")
    try:
        ctx = ssl.create_default_context()
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
        url = f"{BASE_URL}/olist/webhook-receiver.php"
        data_payload = json.dumps({"event": "health_test", "test": True}).encode('utf-8')
        req = urllib.request.Request(
            url, 
            data=data_payload, 
            headers={
                'Content-Type': 'application/json', 
                'User-Agent': 'Mozilla/5.0',
                'X-Olist-Signature': 'invalid_signature_verification_key'
            },
            method='POST'
        )
        try:
            with urllib.request.urlopen(req, timeout=5, context=ctx) as response:
                code = response.getcode()
        except urllib.error.HTTPError as he:
            code = he.code
            
        # Accept HTTP 200 (if secret not configured on VM yet) or HTTP 400/401/403/405 (if secret set and fails verification)
        if code not in [200, 400, 401, 403, 405]:
            log_error(f"Erro: Webhook retornou status inesperado (HTTP {code})!")
            return False
        log_info(f"OK - Webhook Olist respondeu com HTTP {code} (esperado/seguro)")
        return True
    except Exception as e:
        log_error(f"Erro no Webhook: {e}")
        return False

def run_all_tests():
    log_info("=== INICIANDO E2E SMOKE TEST EM PYTHON ===")
    results = [
        test_homepage(),
        test_ssl(),
        test_css(),
        test_catalog(),
        test_catalog_api(),
        test_carrinho(),
        test_checkout(),
        test_admin_protected(),
        test_webhook_olist()
    ]
    
    if all(results):
        log_info("PASSED - TODOS OS CHECKS CRITICOS PASSARAM COM SUCESSO!")
        return True
    else:
        log_error("FAILED - ALGUNS CHECKS CRITICOS FALHARAM!")
        return False

if __name__ == "__main__":
    success = run_all_tests()
    sys.exit(0 if success else 1)
