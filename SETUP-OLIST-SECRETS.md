# 🔐 CONFIGURAR OLIST NOS GITHUB SECRETS

## ✅ Dados a Configurar

```
OLIST_CLIENT_ID = SEU_OLIST_CLIENT_ID_AQUI
OLIST_CLIENT_SECRET = SEU_OLIST_CLIENT_SECRET_AQUI
OLIST_REDIRECT_URI = https://dev.shopvivaliz.com.br/olist/callback.php
```

---

## 🚀 COMO ADICIONAR NO GITHUB

### **Opção 1: Via Web (Mais fácil)**

1. Abra: https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions

2. Clique em **"New repository secret"** (verde no canto superior direito)

3. **Adicione 3 secrets (um por um):**

   **Secret 1:**
   - Name: `OLIST_CLIENT_ID`
   - Value: `SEU_OLIST_CLIENT_ID_AQUI`
   - Clique em **"Add secret"**

   **Secret 2:**
   - Name: `OLIST_CLIENT_SECRET`
   - Value: `SEU_OLIST_CLIENT_SECRET_AQUI`
   - Clique em **"Add secret"**

   **Secret 3:**
   - Name: `OLIST_REDIRECT_URI`
   - Value: `https://dev.shopvivaliz.com.br/olist/callback.php`
   - Clique em **"Add secret"**

4. ✅ Pronto! Os 3 secrets foram adicionados

---

### **Opção 2: Via GitHub CLI (Se tiver instalado)**

```bash
gh secret set OLIST_CLIENT_ID --repo fredmourao-ai/site-shopvivaliz --body "SEU_OLIST_CLIENT_ID_AQUI"

gh secret set OLIST_CLIENT_SECRET --repo fredmourao-ai/site-shopvivaliz --body "SEU_OLIST_CLIENT_SECRET_AQUI"

gh secret set OLIST_REDIRECT_URI --repo fredmourao-ai/site-shopvivaliz --body "https://dev.shopvivaliz.com.br/olist/callback.php"
```

---

### **Opção 3: Local (Para testes agora)**

Se quer testar AGORA sem adicionar ao GitHub:

```bash
# Criar .env local
cat > .env << EOF
OLIST_CLIENT_ID=SEU_OLIST_CLIENT_ID_AQUI
OLIST_CLIENT_SECRET=SEU_OLIST_CLIENT_SECRET_AQUI
OLIST_REDIRECT_URI=https://dev.shopvivaliz.com.br/olist/callback.php
EOF

# Depois executar sincronização
python3 scripts/sync-olist-oauth.py
```

---

## ✅ VERIFICAR SE FOI CONFIGURADO

Depois de adicionar, pode verificar em:
https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions

Você verá os 3 secrets na lista (valores ocultos por segurança)

---

## 🔄 PRÓXIMO PASSO

Após configurar os secrets:

1. **Você abre o link de autorização:**
   ```
   https://accounts.tiny.com.br/oauth/authorize?client_id=SEU_OLIST_CLIENT_ID_AQUI&redirect_uri=https%3a%2f%2fdev.shopvivaliz.com.br%2folist%2fcallback.php&response_type=code&scope=produtos:read
   ```

2. **Você autoriza e pega o CODE**

3. **Me envia o CODE**

4. **Eu sincronizo 198 produtos com imagens**

---

## ⚠️ SEGURANÇA

- ✅ Nunca compartilhe SECRET fora do GitHub Secrets
- ✅ Secrets são ocultos em logs e histórico
- ✅ Só GitHub Actions consegue acessar
- ✅ Pode revogar sempre em Settings → Secrets

---

**Faça agora e me avise quando terminar!** 🚀
