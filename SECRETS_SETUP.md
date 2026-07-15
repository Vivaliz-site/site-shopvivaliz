# Configuração de Secrets - ShopVivaliz

## Visão Geral

Os secrets são credenciais sensíveis armazenadas de forma segura no GitHub. São usados automaticamente nos workflows GitHub Actions.

---

## Secrets Necessários

### Essenciais para Automação IA

| Secret | Descrição | Onde Obter |
|--------|-----------|-----------|
| `OPENAI_API_KEY` | API Key OpenAI (GPT-4 Vision) | https://platform.openai.com/api-keys |
| `OPENAI_MODEL` | Modelo OpenAI (padrão: gpt-4-vision) | Configuração |

### Shopee Integration

| Secret | Descrição | Valor |
|--------|-----------|-------|
| `SHOPEE_ACCESS_TOKEN` | Token de acesso Shopee API | De authorize flow |
| `SHOPEE_REFRESH_TOKEN` | Token de refresh | De authorize flow |
| `SHOPEE_SHOP_ID` | ID da loja Shopee | 227695582 |
| `SHOPEE_PARTNER_ID` | ID do parceiro | 1237032 |
| `SHOPEE_PARTNER_KEY` | Chave do parceiro | shpk574f454f6a756e534e7476726b67727a5242554c76736d4b56567769554d |

### TikTok Integration

| Secret | Descrição | Valor |
|--------|-----------|-------|
| `TIKTOK_ACCESS_TOKEN` | Token de acesso TikTok | De authorize flow |
| `TIKTOK_APP_KEY` | Chave da aplicação | 6kf502maarj2k |
| `TIKTOK_APP_SECRET` | Segredo da aplicação | f0a2a1e58a7le4ca8b5f0f7fdfdb2o0ebee06c |
| `TIKTOK_SHOP_ID` | ID da loja TikTok | De authorize flow |

### Email & SMTP

| Secret | Descrição | Valor |
|--------|-----------|-------|
| `SMTP_HOST` | Host SMTP | smtp0101.titan.email |
| `SMTP_PORT` | Porta SMTP | 465 |
| `SMTP_USER` | Usuário SMTP | gpt@shopvivaliz.com.br |
| `SMTP_PASS` | Senha SMTP | De HostGator |
| `EMAIL_FROM` | Email remetente | gpt@shopvivaliz.com.br |
| `EMAIL_TO` | Email destinatário | fredmourao@gmail.com |

### FTP & Storage

| Secret | Descrição | Valor |
|--------|-----------|-------|
| `FTP_HOST` | Host FTP | De host |
| `FTP_USER` | Usuário FTP | De host |
| `FTP_PASS` | Senha FTP | De host |
| `FTP_PATH` | Caminho FTP | /public_html |

### Database

| Secret | Descrição | Valor |
|--------|-----------|-------|
| `DB_HOST` | Host do banco | localhost |
| `DB_USER` | Usuário banco | root |
| `DB_PASS` | Senha banco | De setup |
| `DB_NAME` | Nome banco | shopvivaliz |

---

## Como Configurar

### Método 1: Via GitHub CLI

```bash
# Instalar GitHub CLI se não tiver
# https://cli.github.com

# Fazer login
gh auth login

# Adicionar secrets um por um
gh secret set OPENAI_API_KEY --body "sk-..."
gh secret set SHOPEE_ACCESS_TOKEN --body "..."
gh secret set TIKTOK_APP_KEY --body "6kf502maarj2k"
# etc...

# Listar secrets
gh secret list
```

### Método 2: Via GitHub Web UI

1. Ir para repositório: https://github.com/fredmourao-ai/site-shopvivaliz

2. Settings → Secrets and variables → Actions

3. Clicar em "New repository secret"

4. Adicionar nome do secret e valor

5. Salvar

---

## Como Usar nos Workflows

### Em GitHub Actions

```yaml
jobs:
  automation:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      
      - name: Run automation pipeline
        env:
          OPENAI_API_KEY: ${{ secrets.OPENAI_API_KEY }}
          SHOPEE_ACCESS_TOKEN: ${{ secrets.SHOPEE_ACCESS_TOKEN }}
          TIKTOK_APP_KEY: ${{ secrets.TIKTOK_APP_KEY }}
        run: |
          python scripts/automation/pipeline_orchestrator.py
```

### Em Scripts Python

```python
import os

# Acessar secrets como variáveis de ambiente
openai_key = os.getenv('OPENAI_API_KEY')
shopee_token = os.getenv('SHOPEE_ACCESS_TOKEN')
tiktok_key = os.getenv('TIKTOK_APP_KEY')

# Usar em chamadas de API
```

### Em Workflows Python

```python
# scripts/utils/config.py
OPENAI_API_KEY = os.getenv('OPENAI_API_KEY', '')
SHOPEE_ACCESS_TOKEN = os.getenv('SHOPEE_ACCESS_TOKEN', '')
TIKTOK_APP_KEY = os.getenv('TIKTOK_APP_KEY', '')
```

---

## Renovação de Tokens

### Shopee Token

1. Executar: `scripts/integrations/shopee.py --refresh-token`
2. Novo token será salvo em `SHOPEE_ACCESS_TOKEN`
3. Atualizar secret no GitHub

### TikTok Token

1. Executar: `scripts/integrations/tiktok.py --refresh-token`
2. Novo token será salvo
3. Atualizar secret no GitHub

---

## Segurança

### Boas Práticas

✅ **SEMPRE:**
- Use variáveis de ambiente para secrets
- Nunca commite secrets no código
- Rotacione secrets periodicamente
- Use tokens com expiração quando possível

❌ **NUNCA:**
- Coloque secrets em .env que está no git
- Printe secrets em logs
- Compartilhe secrets em chats/emails
- Use mesma chave em dev e produção

### Proteção

- GitHub criptografa secrets
- Secrets não são exibidos em logs de workflow
- Apenas workflows do mesmo repositório acessam
- Revogue tokens antigos imediatamente

---

## Troubleshooting

### Secret não funciona no workflow

1. Verificar se secret foi criado:
   ```bash
   gh secret list | grep NOME
   ```

2. Verificar sintaxe no workflow:
   ```yaml
   env:
     KEY: ${{ secrets.SECRET_NAME }}
   ```

3. Recrear secret se necessário

### Erro de autenticação

1. Verificar se token está válido
2. Verificar se token foi renovado
3. Recrear token na plataforma (Shopee/TikTok)
4. Atualizar secret no GitHub

### Timeout ou erro de conexão

1. Verificar credenciais de rede (FTP, SMTP)
2. Verificar IP whitelist (se aplicável)
3. Testar conexão localmente primeiro

---

## Automação de Secrets

### Script para Sincronizar Secrets

Ver `scripts/integrations/sync_secrets.py`

```bash
python scripts/integrations/sync_secrets.py --from prod --to staging
```

---

## Checklist de Configuração

- [ ] OPENAI_API_KEY criado
- [ ] SHOPEE_ACCESS_TOKEN configurado
- [ ] SHOPEE_SHOP_ID definido (227695582)
- [ ] TIKTOK_APP_KEY configurado (6kf502maarj2k)
- [ ] TIKTOK_APP_SECRET definido
- [ ] SMTP_HOST configurado (smtp0101.titan.email)
- [ ] SMTP_USER definido (gpt@shopvivaliz.com.br)
- [ ] SMTP_PASS configurado
- [ ] EMAIL_TO definido (fredmourao@gmail.com)
- [ ] FTP credentials configurados
- [ ] Database credentials configurados
- [ ] Secrets testados em workflow

---

**Todos os secrets configurados = Sistema pronto para operação!** ✅
