# Credenciais Google OAuth 2.0 - Registro Sanitizado

**Data:** 2026-07-19  
**Status:** PARCIAL

## Credenciais

### OAuth 2.0 Client ID
```
Client ID: m71jvyuls7c4die88db14nv3bllmth0i.app.s.googleusercontent.com
Client Secret: [REMOVIDO - armazenar somente em .env privado/GitHub Secrets]
```

### Projeto Google Cloud
- **Projeto:** Default Gemini Project
- **Tipo:** Web Application
- **Nome:** ShopVivaliz-GoogleAds-Campaign

---

## Proximas etapas criticas

### 1. Copiar credenciais para .env privado
```bash
# Crie um arquivo .env na raiz do projeto (NÃO commitar!)
GOOGLE_OAUTH_CLIENT_ID=m71jvyuls7c4die88db14nv3bllmth0i.app.s.googleusercontent.com
GOOGLE_OAUTH_CLIENT_SECRET=<novo_secret_rotacionado>
GOOGLE_ADS_CUSTOMER_ID=5104079137
GOOGLE_ADS_DEVELOPER_TOKEN=[CONFIGURADO - valor somente em .env/GitHub Secrets]
GOOGLE_ADS_REFRESH_TOKEN=[PRESENTE, mas regenerador falha invalid_grant; reemitir]
GOOGLE_ADS_CONVERSION_SOURCE=GA4_IMPORT
GOOGLE_ADS_PURCHASE_CONVERSION_NAME=www.shopvivaliz.com.br (web) purchase
```

### 2. Liberar DEVELOPER_TOKEN para producao
- Token criado na MCC `shopvivaliz ltda` (`634-264-0666`)
- Nivel atual: `Conta de teste`
- Solicitar `Acesso basico` na Central de API
- Concluir verificacao de marca/OAuth no Google Cloud se o Google exigir

### 3. Ativar Google Ads API
- Vá para: https://console.cloud.google.com/apis/library
- Procure por "Google Ads API"
- Clique "Ativar" (Enable)

### 4. Validar antes de executar campanha
```bash
python3 scripts/google_ads_real_readiness.py
```

---

## STATUS DO SISTEMA

- **Sistema autonomo:** Codigo pronto
- **OAuth 2.0:** Client criado, secret deve ser rotacionado por ter sido exposto
- **Google Ads API:** Readiness passou local/VM; bloqueio externo e acesso basico + refresh token invalido
- **Monitoramento 30 dias:** Codigo/configuracao preparada
- **Campaign Config:** Preparada, deve criar campanha pausada primeiro

---

## ⚠️ SEGURANÇA

**NUNCA commite o .env com credenciais reais.**

Este arquivo ja continha um client secret em claro. Trate o segredo antigo como comprometido:

1. Rotacione o segredo no Google Cloud.
2. Atualize `.env` privado e GitHub Secrets.
3. Nunca registre o novo valor neste arquivo.

---

## RESUMO DA CAMPANHA

- **Nome:** Rodizios-Search-AGRESSIVO-10xROI-2026-07
- **Budget:** R$ 15.00/dia (R$ 450 total 30 dias)
- **Keywords:** 6 PHRASE match (high-intent)
- **ROI Target:** 10x+
- **Duração:** 30 dias

---

## Resultado esperado apos credenciais reais

Após configurar tudo:
- Sistema conectará com Google Ads API
- Criará campanha pausada primeiro
- Monitorará dados reais por 30 dias
- Gerará relatórios diários
- Calculará ROI real

**Status atual:** READINESS passou local/VM; bloqueio externo e acesso basico do Google Ads + reemissao do refresh token.
