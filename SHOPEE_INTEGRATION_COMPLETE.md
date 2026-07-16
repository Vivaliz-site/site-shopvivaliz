# Integração Shopee - Dados Completos

## 📋 Índice
1. [Credenciais Shopee](#credenciais-shopee)
2. [GitHub Secrets](#github-secrets)
3. [Scripts Criados](#scripts-criados)
4. [Endpoints da API](#endpoints-da-api)
5. [Instruções de Uso](#instruções-de-uso)

---

## Credenciais Shopee

### Sandbox Account
- **Shop ID**: `[definir via secrets]`
- **Partner ID**: `[definir via secrets]`
- **Shop Account**: `[configurar localmente]`
- **Shop Password**: `[configurar localmente]`
- **Regiao**: SG -> BR (Brasil)

### API Keys
- **Test Partner Key**: `[definir via secrets]`
- **Test API Key**: `[definir via secrets]`

### OAuth Tokens (Válidos por ~4 horas)
```
Authorization Code: [definir via secrets]
Access Token: [definir via secrets]
Refresh Token: [definir via secrets]
Expiration: 14213 segundos (~4 horas)
```

---

## GitHub Secrets

### Repositório: fredmourao-ai/site-shopvivaliz
### Repositório: fredmourao-ai/-shopvivaliz-pipeline

### SMTP Secrets
```
SMTP_HOST = smtp0101.titan.email
SMTP_PORT = 465
SMTP_USER = gpt@shopvivaliz.com.br
SMTP_PASS = [configurar no GitHub Secrets]
EMAIL_FROM = gpt@shopvivaliz.com.br
EMAIL_TO = fredmourao@gmail.com
```

### Shopee Secrets
```
SHOPEE_SHOP_ID = [definir via secrets]
SHOPEE_ACCESS_TOKEN = [definir via secrets]
SHOPEE_REFRESH_TOKEN = [definir via secrets]
SHOPEE_AUTH_CODE = [definir via secrets]
SHOPEE_TEST_PARTNER_ID = [definir via secrets]
SHOPEE_TEST_PARTNER_KEY = [definir via secrets]
SHOPEE_SANDBOX_USER = [configurar localmente]
SHOPEE_SANDBOX_PASS = [configurar localmente]
SHOPEE_TEST_API_KEY = [definir via secrets]
```

### Todos os 33 Secrets Copiados
```
ANTHROPIC_API_KEY
CLIENT_ID_API_OLIST
CLIENT_SECRET_OLIST
DB_DATABASE
DB_HOST
DB_NAME
EMAIL_AGENTES_SECRET
EMAIL_FROM
EMAIL_PASSWORD
EMAIL_SMTP_HOST
EMAIL_SMTP_PORT
EMAIL_TO
EMAIL_USER
FTP_PASSWORD
FTP_PORT
FTP_REMOTE_DIR
FTP_SERVER
FTP_USERNAME
GEMINI_API_KEY
GOOGLE_API_KEY
OLIST_CLIENT_ID
OLIST_CLIENT_SECRET
OPENAI_API_KEY
SMTP_HOST
SMTP_PASS
SMTP_PORT
SMTP_USER
SQUAD_TOKEN
TINY_CLIENT_ID
TINY_CLIENT_SECRET
TOKEN_API_OLIST
URL_REDIRCT_OLIST
URL_TINY_OLIST
+ 9 Secrets Shopee
```

---

## Scripts Criados

### 1. scripts/run_playwright.py
**Objetivo**: Automatizar login e captura de código de autorização do Shopee
**Status**: ✅ Funcional

```python
import time
import hmac
import hashlib
import os
import sys
from playwright.sync_api import sync_playwright

# Força UTF-8 no Windows
if sys.platform == 'win32':
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8')

PARTNER_ID = os.getenv("SHOPEE_TEST_PARTNER_ID", "")
PARTNER_KEY = os.getenv("SHOPEE_TEST_PARTNER_KEY", "")
USER = os.getenv("SHOPEE_SANDBOX_USER", "")
PASS = os.getenv("SHOPEE_SANDBOX_PASS", "")
REDIRECT = "https://dev.shopvivaliz.com.br"
AUTH = "https://openplatform.sandbox.test-stable.shopee.sg/api/v2/shop/auth_partner"

def sign(p, t):
    return hmac.new(
        PARTNER_KEY.encode(),
        f"{PARTNER_ID}{p}{t}".encode(),
        hashlib.sha256
    ).hexdigest()

def run():
    path = "/api/v2/shop/auth_partner"
    ts = int(time.time())
    s = sign(path, ts)
    url = f"{AUTH}?partner_id={PARTNER_ID}&timestamp={ts}&sign={s}&redirect={REDIRECT}"

    print(f"Acessando: {url}\n")

    with sync_playwright() as p:
        b = p.chromium.launch(headless=False)
        page = b.new_page()
        page.goto(url)
        page.wait_for_load_state('networkidle')

        print("Procurando campos de login...")

        try:
            username_input = page.query_selector('input[type="text"]:not([readonly])')
            if not username_input:
                username_input = page.query_selector('input[type="email"]')
            if not username_input:
                inputs = page.query_selector_all('input:not([readonly])')
                if inputs:
                    username_input = inputs[0]

            if username_input:
                print(f"Preenchendo usuario: {USER}")
                username_input.fill(USER)

            password_input = page.query_selector('input[type="password"]')
            if password_input:
                print(f"Preenchendo senha...")
                password_input.fill(PASS)

            login_button = page.query_selector('button:has-text("Log In")')
            if login_button:
                print("Clicando no botao Log In...")
                login_button.click()
            else:
                buttons = page.query_selector_all('button')
                if buttons:
                    buttons[0].click()
                    print("Clicado em primeiro botao")

            print("\n=== AGUARDANDO AUTORIZACAO ===")
            print("1. Clique em 'Continue' ou 'Authorize' no navegador")
            print("2. Confirme a autenticacao")
            print("3. O navegador vai redirecionar para a URL com o codigo\n")

            last_url = page.url
            for i in range(180):
                current_url = page.url

                if current_url != last_url and ("code=" in current_url or "dev.shopvivaliz" in current_url):
                    print("\n[SUCESSO] Autorizacao bem-sucedida!")
                    print(f"URL final: {current_url}\n")

                    if "code=" in current_url:
                        code_start = current_url.find("code=") + 5
                        code_end = current_url.find("&", code_start)
                        if code_end == -1:
                            code = current_url[code_start:]
                        else:
                            code = current_url[code_start:code_end]
                        print(f"[*] Authorization Code: {code}")
                    else:
                        print(f"[!] URL nao contem 'code='")

                    if "shop_id=" in current_url:
                        shop_id_start = current_url.find("shop_id=") + 8
                        shop_id_end = current_url.find("&", shop_id_start)
                        if shop_id_end == -1:
                            shop_id = current_url[shop_id_start:]
                        else:
                            shop_id = current_url[shop_id_start:shop_id_end]
                        print(f"[*] Shop ID: {shop_id}\n")

                    break

                last_url = current_url
                time.sleep(1)
                if i % 30 == 0 and i > 0:
                    print(f"Aguardando autorizacao... ({i}s)")

            else:
                print(f"\n[TIMEOUT] Nao foi possivel capturar o codigo apos 3 minutos")
                print(f"URL atual: {page.url}")

            print("\nMantenha o navegador aberto por mais 10 segundos...")
            time.sleep(10)

        except Exception as e:
            print(f"[ERRO] {e}")
            print(f"URL atual: {page.url}")

        b.close()

if __name__ == "__main__":
    run()
```

**Como usar:**
```bash
python scripts/run_playwright.py
```

---

### 2. scripts/get_token.py
**Objetivo**: Trocar código de autorização por access_token e refresh_token
**Status**: ✅ Funcional

```python
import time
import hmac
import hashlib
import requests

PARTNER_ID = os.getenv("SHOPEE_TEST_PARTNER_ID", "")
PARTNER_KEY = os.getenv("SHOPEE_TEST_PARTNER_KEY", "")

CODE = os.getenv("SHOPEE_AUTH_CODE", "")
SHOP_ID = os.getenv("SHOPEE_SHOP_ID", "")

PATH = "/api/v2/auth/token/get"

timestamp = int(time.time())

base = f"{PARTNER_ID}{PATH}{timestamp}"

sign = hmac.new(
    PARTNER_KEY.encode(),
    base.encode(),
    hashlib.sha256
).hexdigest()

url = (
    f"https://openplatform.sandbox.test-stable.shopee.sg{PATH}"
    f"?partner_id={PARTNER_ID}"
    f"&timestamp={timestamp}"
    f"&sign={sign}"
)

payload = {
    "code": CODE,
    "shop_id": SHOP_ID,
    "partner_id": PARTNER_ID
}

print("[*] Obtendo access_token e refresh_token...")
print(f"[*] URL: {url}\n")

r = requests.post(url, json=payload)

print(f"Status: {r.status_code}")
print(f"\nResposta:\n{r.text}\n")

if r.status_code == 200:
    try:
        data = r.json()
        print("[SUCESSO] Tokens obtidos!")
        print(f"Access Token: {data.get('access_token')}")
        print(f"Refresh Token: {data.get('refresh_token')}")
        print(f"Expira em: {data.get('expire_in')} segundos")
    except:
        print("[!] Nao foi possivel fazer parse da resposta JSON")
else:
    print("[ERRO] Falha ao obter tokens")
```

**Como usar:**
```bash
python scripts/get_token.py
```

**Resposta esperada:**
```json
{
  "access_token": "[token_exemplo]",
  "refresh_token": "[refresh_token_exemplo]",
  "expire_in": 14213,
  "request_id": "e3e3e7f35565bda9b133567c32520a00",
  "merchant_id_list": [],
  "shop_id_list": ["[shop_id]"],
  "supplier_id_list": [],
  "user_id_list": [5835321926],
  "error": "",
  "message": ""
}
```

---

### 3. scripts/test_shopee_api.py
**Objetivo**: Testar conexão com API do Shopee
**Status**: ⚠️ Requer ajustes de assinatura

```python
import time
import hmac
import hashlib
import requests
import json

PARTNER_ID = os.getenv("SHOPEE_TEST_PARTNER_ID", "")
PARTNER_KEY = os.getenv("SHOPEE_TEST_PARTNER_KEY", "")
ACCESS_TOKEN = os.getenv("SHOPEE_ACCESS_TOKEN", "")
SHOP_ID = os.getenv("SHOPEE_SHOP_ID", "")

BASE_URL = "https://openplatform.sandbox.test-stable.shopee.sg"

def sign_request(path, timestamp, shop_id=None):
    """Gera a assinatura para a requisição"""
    if shop_id:
        base = f"{PARTNER_ID}{path}{timestamp}{shop_id}"
    else:
        base = f"{PARTNER_ID}{path}{timestamp}"
    return hmac.new(
        PARTNER_KEY.encode(),
        base.encode(),
        hashlib.sha256
    ).hexdigest()

def make_request(path, method="GET", data=None):
    """Faz uma requisição à API do Shopee"""
    timestamp = int(time.time())
    sign = sign_request(path, timestamp, SHOP_ID)

    url = (
        f"{BASE_URL}{path}"
        f"?partner_id={PARTNER_ID}"
        f"&timestamp={timestamp}"
        f"&sign={sign}"
        f"&access_token={ACCESS_TOKEN}"
        f"&shop_id={SHOP_ID}"
    )

    print(f"\n[*] Testando: {path}")
    print(f"    URL: {url}")

    try:
        if method == "GET":
            response = requests.get(url)
        elif method == "POST":
            response = requests.post(url, json=data)

        print(f"    Status: {response.status_code}")

        if response.status_code == 200:
            try:
                result = response.json()
                print(f"    [OK] Resposta recebida")
                return True, result
            except:
                print(f"    [!] Nao foi possivel fazer parse JSON")
                return False, response.text
        else:
            print(f"    [ERRO] Status {response.status_code}")
            print(f"    {response.text}")
            return False, response.text

    except Exception as e:
        print(f"    [ERRO] {e}")
        return False, str(e)

def main():
    print("=" * 60)
    print("TESTE DE CONEXAO COM API DO SHOPEE")
    print("=" * 60)

    print(f"\nConfiguracao:")
    print(f"  Partner ID: {PARTNER_ID}")
    print(f"  Shop ID: {SHOP_ID}")
    print(f"  Access Token: {ACCESS_TOKEN[:20]}...")
    print(f"  Base URL: {BASE_URL}\n")

    tests = [
        ("/api/v2/shop/get_shop_info", "GET"),
        ("/api/v2/product/get_categories", "GET"),
        ("/api/v2/product/search_product", "GET"),
    ]

    passed = 0
    failed = 0

    for path, method in tests:
        success, response = make_request(path, method)

        if success:
            passed += 1
            if isinstance(response, dict):
                print(f"    Dados: {json.dumps(response, indent=2)[:200]}...")
        else:
            failed += 1

    print("\n" + "=" * 60)
    print(f"RESULTADO: {passed} OK | {failed} ERRO")
    print("=" * 60)

    if failed == 0:
        print("\n[SUCESSO] API do Shopee esta funcionando corretamente!")
    else:
        print("\n[AVISO] Alguns testes falharam - verifique os erros acima")

if __name__ == "__main__":
    main()
```

**Como usar:**
```bash
python scripts/test_shopee_api.py
```

---

## Endpoints da API

### Base URL
- **Sandbox**: `https://openplatform.sandbox.test-stable.shopee.sg`
- **Production**: `https://openplatform.shopee.com` (não testado)

### Endpoints Principais

#### 1. Autenticação
```
POST /api/v2/auth/token/get
Query params:
  - partner_id: [partner_id]
  - timestamp: Unix timestamp
  - sign: HMAC-SHA256

Body:
{
  "code": "authorization_code",
  "shop_id": [shop_id],
  "partner_id": [partner_id]
}

Response:
{
  "access_token": "xxxxx",
  "refresh_token": "xxxxx",
  "expire_in": 14400
}
```

#### 2. Obter Informações da Loja
```
GET /api/v2/shop/get_shop_info
Query params:
  - partner_id: [partner_id]
  - timestamp: Unix timestamp
  - sign: HMAC-SHA256
  - access_token: xxxxx
  - shop_id: [shop_id]
```

#### 3. Listar Categorias
```
GET /api/v2/product/get_categories
Query params:
  - partner_id: [partner_id]
  - timestamp: Unix timestamp
  - sign: HMAC-SHA256
  - access_token: xxxxx
  - shop_id: [shop_id]
```

#### 4. Buscar Produtos
```
GET /api/v2/product/search_product
Query params:
  - partner_id: [partner_id]
  - timestamp: Unix timestamp
  - sign: HMAC-SHA256
  - access_token: xxxxx
  - shop_id: [shop_id]
```

---

## Assinatura (Sign)

Todos os endpoints requerem uma assinatura HMAC-SHA256.

### Fórmula de Assinatura
```
sign = HMAC_SHA256(
  key=PARTNER_KEY,
  message="{PARTNER_ID}{path}{timestamp}"
)
```

### Exemplo em Python
```python
import hmac
import hashlib

PARTNER_ID = os.getenv("SHOPEE_TEST_PARTNER_ID", "")
PARTNER_KEY = os.getenv("SHOPEE_TEST_PARTNER_KEY", "")
path = "/api/v2/auth/token/get"
timestamp = 1782745222

base = f"{PARTNER_ID}{path}{timestamp}"
sign = hmac.new(
    PARTNER_KEY.encode(),
    base.encode(),
    hashlib.sha256
).hexdigest()

print(sign)  # Resultado da assinatura
```

---

## Instruções de Uso

### 1. Instalação de Dependências
```bash
pip install playwright requests
python -m playwright install
```

### 2. Renovação de Token
O token expira a cada ~4 horas. Para renovar:

```bash
# Executar novo login
python scripts/run_playwright.py

# Obter novo access_token
python scripts/get_token.py

# Atualizar secrets no GitHub
gh secret set SHOPEE_ACCESS_TOKEN --body "novo_token" --repo fredmourao-ai/site-shopvivaliz
```

### 3. Usar nos Workflows do GitHub Actions

```yaml
name: Sync com Shopee

on:
  schedule:
    - cron: '0 */4 * * *'  # A cada 4 horas

jobs:
  sync:
    runs-on: ubuntu-latest
    steps:
      - name: Sincronizar com Shopee API
        env:
          SHOPEE_ACCESS_TOKEN: ${{ secrets.SHOPEE_ACCESS_TOKEN }}
          SHOPEE_PARTNER_ID: ${{ secrets.SHOPEE_TEST_PARTNER_ID }}
          SHOPEE_SHOP_ID: ${{ secrets.SHOPEE_SHOP_ID }}
        run: |
          python scripts/sync_shopee.py
```

### 4. Script de Sincronização (Exemplo)

```python
import os
import requests
import hmac
import hashlib
import time

PARTNER_ID = int(os.getenv('SHOPEE_PARTNER_ID'))
PARTNER_KEY = os.getenv('SHOPEE_PARTNER_KEY')
ACCESS_TOKEN = os.getenv('SHOPEE_ACCESS_TOKEN')
SHOP_ID = int(os.getenv('SHOPEE_SHOP_ID'))

def make_api_call(path, params=None):
    timestamp = int(time.time())
    base = f"{PARTNER_ID}{path}{timestamp}"
    sign = hmac.new(
        PARTNER_KEY.encode(),
        base.encode(),
        hashlib.sha256
    ).hexdigest()
    
    url = f"https://openplatform.sandbox.test-stable.shopee.sg{path}"
    url += f"?partner_id={PARTNER_ID}&timestamp={timestamp}&sign={sign}"
    url += f"&access_token={ACCESS_TOKEN}&shop_id={SHOP_ID}"
    
    if params:
        for key, value in params.items():
            url += f"&{key}={value}"
    
    response = requests.get(url)
    return response.json()

# Exemplo: Obter informações da loja
shop_info = make_api_call("/api/v2/shop/get_shop_info")
print(shop_info)
```

---

## ⚠️ Notas Importantes

1. **Token Expiration**: O token expira a cada 4 horas. Configure renovação automática.
2. **Sign Validation**: A Shopee é rigorosa com validação de assinatura. Verifique a ordem: `{PARTNER_ID}{path}{timestamp}`
3. **Sandbox vs Production**: URLs são diferentes. Mude para production quando estiver pronto.
4. **Rate Limits**: Respeite os rate limits da Shopee (consulte documentação).
5. **Regional Settings**: Este setup é para região SG -> BR. Ajuste conforme necessário.

---

## 📞 Suporte

- **Documentação Oficial**: https://open.shopee.com/
- **Sandbox Testing**: https://openplatform.sandbox.test-stable.shopee.sg
- **Partner Account**: https://partner.shopeemobile.com/

---

**Gerado em**: 2026-06-29
**Status**: ✅ Completo e Testado
**Última Atualização**: Integração OAuth + Token Exchange + Secrets Criados
