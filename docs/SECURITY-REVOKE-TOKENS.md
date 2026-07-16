# 🔐 Guia de Revogação de Tokens Sensíveis

**⚠️ AÇÃO URGENTE:** Tokens foram expostos em texto plano. Revogue IMEDIATAMENTE!

---

## 🚨 O que foi exposto

```
✅ REVOGADO? | Tipo | Descrição
───────────────────────────────
[ ] Facebook Access Token | EAANZCLNk4tOkBR... | Page ID 919743089739419
[ ] Google Analytics | G-1H55K1TZ5D | ID de rastreamento
[ ] Google Merchant | 5381803710 | Loja Google
[ ] Google Tag Manager | GTM-PHZ55CP3 | Container GTM
[ ] Cloudflare API Token | cfut_07P0ISC5... | Gerenciamento DNS
```

---

## 📋 Como Revogar Cada Um

### 1️⃣ Facebook Access Token
```
1. Abra: https://developers.facebook.com/apps/
2. Selecione o App (ID: 919743089739419)
3. Vá em: Settings > Basic
4. Clique em "Regenerate Token"
5. Copie o novo token
6. Atualize em: GitHub Secrets > FACEBOOK_ACCESS_TOKEN
```

### 2️⃣ Google Analytics (G-1H55K1TZ5D)
```
1. Abra: https://analytics.google.com/
2. Clique em Admin (roda de engrenagem)
3. Vá em: Propriedades > Configurações da propriedade
4. Copie o novo ID de rastreamento
5. Atualize em: GitHub Secrets > GOOGLE_ANALYTICS_ID
6. Atualize em: /admin/integrations.php
```

### 3️⃣ Google Merchant Center (5381803710)
```
1. Abra: https://merchants.google.com/
2. Clique em Configurações
3. Copie o novo ID da loja
4. Atualize em: GitHub Secrets > GOOGLE_MERCHANT_ID
5. Atualize em: /admin/integrations.php
```

### 4️⃣ Google Tag Manager (GTM-PHZ55CP3)
```
1. Abra: https://tagmanager.google.com/
2. Selecione o Container
3. Vá em: Workspace padrão
4. Copie o novo ID do container (GTM-XXXXXXX)
5. Atualize em: GitHub Secrets > GOOGLE_TAG_MANAGER_ID
6. Atualize em: /admin/integrations.php
```

### 5️⃣ Cloudflare API Token
```
1. Abra: https://dash.cloudflare.com/profile/api-tokens
2. Procure por "cfut_07P0ISC5..."
3. Clique em "Delete" (lixo)
4. Confirme a exclusão
5. Crie um novo token (veja guia abaixo)
6. Atualize em: GitHub Secrets > CLOUDFLARE_API_TOKEN
```

---

## ✅ Checklist de Segurança

- [ ] Facebook token revogado e novo gerado
- [ ] Google Analytics ID atualizado
- [ ] Google Merchant ID atualizado
- [ ] Google Tag Manager ID atualizado
- [ ] Cloudflare token antigo deletado
- [ ] Novos tokens adicionados ao GitHub Secrets
- [ ] Novos tokens adicionados ao admin (/admin/integrations.php)
- [ ] .env local atualizado (se necessário)
- [ ] Verificado que nenhum token velho está no git history

---

## 🔍 Verificar se token está no git history

```bash
# Procurar por token antigo
git log -p --all | grep "cfut_07P0ISC5"
git log -p --all | grep "EAANZCLNk4tOkBR"

# Se encontrar, fazer rebase para remover
git rebase -i <commit-anterior>
# Marcar como 'drop'
```

---

## 📝 Notas Importantes

1. **GitHub Secrets são privados** - mas o token foi exposto aqui no chat
2. **Revogue TODOS os tokens expostos** - não confiar em "segurança por obscuridade"
3. **Use senhas diferentes** para cada serviço (não reutilize)
4. **Monitore logs** - verificar se houve uso não autorizado
5. **Ativar 2FA** em todas as contas (Facebook, Google, Cloudflare)

---

**Data:** 2026-07-12  
**Status:** ⏳ AGUARDANDO REVOGAÇÃO
