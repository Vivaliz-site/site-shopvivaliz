# 🌐 Google APIs OAuth 2.0 - Guia de Integração e Operações

**Data de Atualização**: 2026-08-16  
**Status**: ✅ Ativo e Autorizado (Multi-escopo)  
**Ambiente**: Desenvolvimento, Staging e Produção  

---

## 📌 Visão Geral

Este documento descreve a infraestrutura unificada de autorização Google OAuth 2.0 para o ecossistema ShopVivaliz, cobrindo os serviços:
1. **Google Merchant Center** (Content API for Shopping)
2. **Google Tag Manager** (Container Management & Publication)
3. **Google Search Console** (Webmasters API)
4. **Google Analytics 4** (GA4 Data / Readonly API)
5. **Google Indexing API** (Instant Crawling & Indexation)

---

## 🔐 Configuração das Credenciais

As credenciais principais devem ser mantidas em variáveis de ambiente (`.env` local, `shared/.env` na VM de produção ou GitHub Secrets):

| Variável | Descrição | Exemplo / Formato |
| :--- | :--- | :--- |
| `GOOGLE_OAUTH_CLIENT_ID` | Client ID da aplicação Google Cloud | `515723698609-...apps.googleusercontent.com` |
| `GOOGLE_OAUTH_CLIENT_SECRET` | Client Secret do OAuth 2.0 | `GOCSPX-...` |
| `GOOGLE_OAUTH_REFRESH_TOKEN` | Refresh Token permanente gerado | `1//0hUBx7ImKHM...` |
| `GOOGLE_MERCHANT_ID` | ID da conta do Google Merchant Center | `123456789` |
| `GA4_PROPERTY_ID` | ID da Propriedade no GA4 | `123456789` |

> ⚠️ **Segurança:** Nunca comite tokens ou segredos reais em arquivos versionados. O repositório utiliza o diretório `.tokens/` (ignorado no `.gitignore`) para persistência de tokens locais em runtime.

---

## 🎯 Escopos Concedidos e Finalidades

| Serviço | Escopo | Finalidade Operacional |
| :--- | :--- | :--- |
| **Merchant Center** | `https://www.googleapis.com/auth/content` | Gestão automatizada do catálogo de produtos, feeds e status no Google Shopping. |
| **Tag Manager** | `https://www.googleapis.com/auth/tagmanager.edit.containers` | Criação e edição de tags, acionadores e variáveis de rastreamento. |
| **Tag Manager** | `https://www.googleapis.com/auth/tagmanager.publish` | Publicação de versões de contêineres GTM. |
| **Search Console** | `https://www.googleapis.com/auth/webmasters` | Consulta de indexação, sitemaps e métricas de desempenho de busca orgânica. |
| **Analytics (GA4)** | `https://www.googleapis.com/auth/analytics.readonly` | Consulta de relatórios de tráfego, sessões e conversões. |
| **Indexing API** | `https://www.googleapis.com/auth/indexing` | Envio de notificações de URLs novas ou atualizadas para indexação prioritária. |

---

## 🔄 Renovação de Tokens (Refresh Token Flow)

O `refresh_token` é permanente (enquanto não revogado no painel do Google). Para realizar chamadas às APIs, utilize o `refresh_token` para obter um `access_token` de curta duração (3600 segundos / 1 hora).

### 1. Obtenção do Access Token via PHP

```php
<?php
function getGoogleAccessToken(): ?string {
    $clientId = getenv('GOOGLE_OAUTH_CLIENT_ID');
    $clientSecret = getenv('GOOGLE_OAUTH_CLIENT_SECRET');
    $refreshToken = getenv('GOOGLE_OAUTH_REFRESH_TOKEN');

    if (!$clientId || !$clientSecret || !$refreshToken) {
        error_log('[GoogleOAuth] Credenciais ausentes no ambiente.');
        return null;
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token',
    ]));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        return $data['access_token'] ?? null;
    }

    error_log("[GoogleOAuth] Falha ao renovar token (HTTP $httpCode): $response");
    return null;
}
```

### 2. Obtenção do Access Token via Python

```python
import os
import urllib.parse
import urllib.request
import json

def get_google_access_token() -> str:
    client_id = os.getenv("GOOGLE_OAUTH_CLIENT_ID")
    client_secret = os.getenv("GOOGLE_OAUTH_CLIENT_SECRET")
    refresh_token = os.getenv("GOOGLE_OAUTH_REFRESH_TOKEN")

    payload = {
        "client_id": client_id,
        "client_secret": client_secret,
        "refresh_token": refresh_token,
        "grant_type": "refresh_token",
    }

    req = urllib.request.Request(
        "https://oauth2.googleapis.com/token",
        data=urllib.parse.urlencode(payload).encode("utf-8"),
        headers={"Content-Type": "application/x-www-form-urlencoded"}
    )
    with urllib.request.urlopen(req) as resp:
        data = json.loads(resp.read().decode("utf-8"))
        return data["access_token"]
```

---

## 📡 Exemplos de Uso por Serviço

### 1. Indexing API (Notificar nova página/produto)

```python
# Endpoint: https://indexing.googleapis.com/v3/urlNotifications:publish
def notify_url_indexing(access_token: str, url_to_index: str):
    import urllib.request, json
    
    payload = json.dumps({
        "url": url_to_index,
        "type": "URL_UPDATED"  # ou "URL_DELETED"
    }).encode("utf-8")

    req = urllib.request.Request(
        "https://indexing.googleapis.com/v3/urlNotifications:publish",
        data=payload,
        headers={
            "Authorization": f"Bearer {access_token}",
            "Content-Type": "application/json"
        }
    )
    with urllib.request.urlopen(req) as resp:
        return json.loads(resp.read().decode("utf-8"))
```

### 2. Google Search Console API (Consultar estatísticas de busca)

```python
def query_search_console(access_token: str, site_url: str, start_date: str, end_date: str):
    import urllib.request, json
    
    endpoint = f"https://www.googleapis.com/webmasters/v3/sites/{urllib.parse.quote_plus(site_url)}/searchAnalytics/query"
    payload = json.dumps({
        "startDate": start_date,
        "endDate": end_date,
        "dimensions": ["query", "page"],
        "rowLimit": 100
    }).encode("utf-8")

    req = urllib.request.Request(
        endpoint,
        data=payload,
        headers={
            "Authorization": f"Bearer {access_token}",
            "Content-Type": "application/json"
        }
    )
    with urllib.request.urlopen(req) as resp:
        return json.loads(resp.read().decode("utf-8"))
```

### 3. Google Merchant Center Content API

```python
def list_merchant_products(access_token: str, merchant_id: str):
    import urllib.request, json
    
    endpoint = f"https://shoppingcontent.googleapis.com/content/v2.1/{merchant_id}/products"
    req = urllib.request.Request(
        endpoint,
        headers={"Authorization": f"Bearer {access_token}"}
    )
    with urllib.request.urlopen(req) as resp:
        return json.loads(resp.read().decode("utf-8"))
```

---

## 🛠️ Ferramenta Local de Gestão de Tokens

O repositório inclui o script utilitário [`scripts/google_oauth_token_helper.py`](file:///c:/site-shopvivaliz-prod-liz/scripts/google_oauth_token_helper.py):

* **Iniciar servidor de autorização interativo:**
  ```bash
  python scripts/google_oauth_token_helper.py
  ```
* **Trocar código de autorização manual por tokens:**
  ```bash
  python scripts/google_oauth_token_helper.py --exchange-code "<CODIGO_DE_AUTORIZACAO>" --redirect-uri "https://developers.google.com/oauthplayground"
  ```
