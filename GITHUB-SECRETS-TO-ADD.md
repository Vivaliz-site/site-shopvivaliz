# GitHub Secrets para ShopVivaliz

Adicione estes secrets no repositório GitHub:
https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions

## Banco de Dados

```
DB_HOST = localhost
DB_PORT = 3306
DB_NAME = shopv506_shopvivaliz
DB_USERNAME = claude
DB_PASSWORD = (sua senha)
DB_CHARSET = utf8mb4
```

## APIs de IA

```
OPENAI_API_KEY = (sua chave)
ANTHROPIC_API_KEY = (sua chave)
GEMINI_API_KEY = (sua chave)
```

## Olist/Tiny ERP

```
OLIST_CLIENT_ID = SEU_OLIST_CLIENT_ID_AQUI
OLIST_CLIENT_SECRET = SEU_OLIST_CLIENT_SECRET_AQUI
OLIST_ACCESS_TOKEN = (será gerado pelo OAuth)
OLIST_REFRESH_TOKEN = (será gerado pelo OAuth)
```

## Melhor Envio

```
MELHOR_ENVIO_CLIENT_ID = (sua chave)
MELHOR_ENVIO_CLIENT_SECRET = (sua chave)
MELHOR_ENVIO_ACCESS_TOKEN = (sua chave)
```

## Pagar.me

```
PAGARME_API_KEY = (sua chave)
PAGARME_ENCRYPTION_KEY = (sua chave)
```

## FTP Deploy (já configurado?)

```
FTP_SERVER = (seu servidor FTP)
FTP_USERNAME = (seu usuário FTP)
FTP_PASSWORD = (sua senha FTP)
FTP_PORT = 21
FTP_REMOTE_DIR = /
```

---

## Como adicionar no GitHub:

1. Vá para: https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions
2. Clique em "New repository secret"
3. Nome: `DB_HOST` Valor: `localhost`
4. Clique em "Add secret"
5. Repita para cada secret acima

Ou use GitHub CLI:
```bash
gh secret set DB_HOST --body "localhost"
gh secret set DB_NAME --body "shopv506_shopvivaliz"
gh secret set DB_USERNAME --body "claude"
gh secret set DB_PASSWORD --body "<sua senha>"
```
