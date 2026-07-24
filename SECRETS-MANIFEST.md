# 🔐 Secrets Manifest - Unificado

> **CRÍTICO**: Este arquivo documenta ONDE cada secret deve estar.
> Nunca commitar valores reais aqui (só nomes de variáveis).

---

## 📋 Secrets Obrigatórias

### Database
```
DB_HOST=<VM Oracle IP ou localhost>
DB_USER=<MySQL user>
DB_PASS=<MySQL password>
DB_NAME=shopvivaliz
```

### Email
```
EMAIL_FROM=noreply@shopvivaliz.com.br
EMAIL_TO=<admin email>
MAIL_HOST=<SMTP host>
MAIL_PORT=<SMTP port>
MAIL_USER=<SMTP user>
MAIL_PASS=<SMTP password>
```

### APIs Externas
```
ANTHROPIC_API_KEY=sk-ant-...
OPENAI_API_KEY=sk-...
GEMINI_API_KEY=AIzaSy...
GOOGLE_OAUTH_CLIENT_ID=...
GOOGLE_OAUTH_CLIENT_SECRET=...
```

### Integrações ERP/E-commerce
```
TINY_ERP_API_TOKEN=<Tiny ERP token>
OLIST_API_KEY=<Olist token>
MERCADOPAGO_ACCESS_TOKEN=<MP token>
MERCADOPAGO_PUBLIC_KEY=<MP public>
MERCADOPAGO_WEBHOOK_SECRET=<MP webhook>
MELHORENVIO_ACCESS_TOKEN=<Melhor Envio>
MELHORENVIO_CLIENTE_ID=<ID>
MELHORENVIO_CLIENTE_SECRET=<Secret>
```

### GitHub / Deploy
```
GH_REPO_TOKEN=github_pat_...
CLOUDFLARE_API_TOKEN=<CF token>
FTP_SERVER=<HostGator>
FTP_USERNAME=<FTP user>
FTP_PASSWORD=<FTP pass>
FTP_PORT=21
FTP_REMOTE_DIR=/public_html
```

---

## 📍 Locais de Armazenamento

| Secret | Local (PC) | VM Oracle | GitHub |
|--------|-----------|-----------|--------|
| **Database** | .env | /home/ubuntu/site-shopvivaliz/.env | ❌ Nunca |
| **Email** | .env | /home/ubuntu/site-shopvivaliz/.env | ❌ Nunca |
| **APIs IA** | .env | /home/ubuntu/site-shopvivaliz/.env | ✅ Settings > Secrets |
| **ERP/Commerce** | .env | /home/ubuntu/site-shopvivaliz/.env | ✅ Settings > Secrets |
| **GitHub Token** | .env | N/A | ✅ Settings > Secrets |
| **CloudFlare** | .env | N/A | ✅ Settings > Secrets |
| **FTP** | .env | N/A | ✅ Settings > Secrets |

---

## ✅ Checklist de Sincronização

- [ ] `.env` Local (PC) atualizado
- [ ] `.env` VM Oracle sincronizado
- [ ] GitHub Secrets atualizados
- [ ] Permissions verificadas (❌ Nunca commitar .env)
- [ ] `.gitignore` contém `.env` e `.env.*`

---

## 🔄 Processo de Atualização

```bash
# 1. Atualizar local
vi C:\Users\FRED\site-shopvivaliz\.env

# 2. Copiar para VM
scp -i key.pem .env ubuntu@137.131.156.17:/home/ubuntu/site-shopvivaliz/

# 3. Atualizar GitHub
# Settings > Secrets and variables > Actions > Edit each secret

# 4. Validar
ssh ubuntu@137.131.156.17 "grep MERCADOPAGO /home/ubuntu/site-shopvivaliz/.env"
```

---

## ⚠️ Segurança

- ✅ `.env` está em `.gitignore`
- ✅ Nunca commitar valores sensíveis
- ✅ Usar GitHub Secrets para CI/CD
- ✅ Rotacionar tokens regularmente
- ✅ Auditar acesso (quem viu o quê)
