# 🔐 Renovação de Token Tiny ERP - Setup Completo

**Data:** 2026-07-24  
**Status:** ✅ Aguardando login do usuário  
**Objetivo:** Restaurar sincronização automática de produtos do Tiny ERP

---

## 📋 O que foi feito

1. ✅ **Client Secret atualizado**
   - Valor antigo: `ZCr4ymUHY4M8pi69OXnGLOIPJNRXaouP`
   - Novo: `2lGdMfxZjUh25f8Feha3CqInywGR55jG`
   - Local: `.env` (linhas OLIST_CLIENT_SECRET)

2. ✅ **Scripts de OAuth criados**
   - `api/olist/login.php` → Gera URL de autorização
   - `api/olist/callback.php` → Recebe code e troca por tokens
   - Ambos commitados e sincronizados para VM

3. ✅ **VM sincronizada**
   - Git: `HEAD at 1cb092aa`
   - `.env` com novo Client Secret
   - Scripts de login/callback prontos

---

## 🚀 O que você precisa fazer AGORA

### Passo 1: Abrir URL de Autorização

Clique neste link (ou copie e cole no navegador):

```
https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth?client_id=tiny-api-d4eb7c80a2e7e8abebad641a446a2f69d9e98289-1784255157&redirect_uri=https%3A%2F%2Fdev.shopvivaliz.com.br%2Folist%2Fcallback.php&response_type=code&scope=openid+email+offline_access
```

**Importante:** Use HTTPS (o link começa com `https://`)

### Passo 2: Autorizar no Tiny

1. Faça login com suas credenciais do Tiny ERP
2. Uma tela pedir para autorizar: "O aplicativo DEV gostaria de acessar"
   - Escopo: `openid`, `email`, `offline_access`
3. Clique em **Autorizar** ou **Permitir**

### Passo 3: Callback Automático

- Você será redirecionado para: `https://dev.shopvivaliz.com.br/olist/callback.php?code=...&state=...`
- **Não feche a aba** — deixe carregar até o final
- Você verá uma resposta JSON com:
  ```json
  {
    "status": "ok",
    "message": "Tokens obtained and saved successfully",
    "timestamp": "2026-07-24T...",
    "access_token_preview": "eyJ...",
    "next_step": "Daemon will sync products automatically..."
  }
  ```

### Passo 4: Sincronização Automática

**Opção A: Esperar (automático)**
- Daemon executa a cada 6 horas
- Próximo sync: ~06:00 ou ~12:00 ou ~18:00
- Você verá `products-cache-ativos.json` atualizado com 1000+ produtos

**Opção B: Forçar agora (manual)**
```bash
ssh -i "C:\Users\FRED\Downloads\ssh-key-2026-07-04.key" ubuntu@137.131.156.17 \
  "cd /home/ubuntu/site-shopvivaliz && python3 daemon-sync-products.py"
```

---

## ✅ Validação após login

Após fazer o login acima, verificar:

1. **Em local** (`C:\Users\FRED\site-shopvivaliz\.env`):
   - `TINY_ACCESS_TOKEN` foi atualizado? (começa com `eyJ...`)
   - `TINY_REFRESH_TOKEN` foi atualizado?

2. **Na VM** (`/home/ubuntu/site-shopvivaliz/.env`):
   - Mesmos tokens sincronizados?
   - Comando: `ssh ubuntu@137.131.156.17 "grep TINY_ACCESS_TOKEN /home/ubuntu/site-shopvivaliz/.env | cut -c1-50"`

3. **Sync de produtos**:
   - Arquivo criado? `/home/ubuntu/site-shopvivaliz/storage/products-cache-ativos.json`
   - Comando: `ssh ubuntu@137.131.156.17 "ls -lh /home/ubuntu/site-shopvivaliz/storage/products-cache-*.json"`

4. **Na home do site**:
   - Produtos aparecem com imagens? (não mais "Esgotado")
   - URL: https://shopvivaliz.com.br/ ou https://dev.shopvivaliz.com.br/

---

## 🔧 Troubleshooting

**Erro 1: "invalid_grant: Token is not active"**
- Significa que o refresh token ainda está expirado
- Solução: Fazer o login novamente usando a URL de autorização acima

**Erro 2: "redirect_uri_mismatch"**
- Redirect URI não confere
- Verificar que está em: `https://dev.shopvivaliz.com.br/olist/callback.php`
- NÃO: `https://shopvivaliz.com.br/...` ou `http://...`

**Erro 3: Callback não funciona**
- Verify PHP curl está instalado na VM
- Comando: `ssh ubuntu@137.131.156.17 "php -m | grep curl"`

**Erro 4: Produtos não sincronizaram após 1 hora**
- Ver logs: `ssh ubuntu@137.131.156.17 "tail -50 /home/ubuntu/site-shopvivaliz/logs/daemon-sync-*.log"`
- Ou rodar daemon manualmente (veja Passo 4B acima)

---

## 📚 Referências

- **Tiny ERP OAuth2:** https://docs.tiny.com.br/seção/autenticação
- **Daemon sync:** `/home/ubuntu/site-shopvivaliz/daemon-sync-products.py`
- **Cron setup:** `/home/ubuntu/site-shopvivaliz/install-cron.sh`
- **Histórico:** `CHANGELOG.md`

---

## 🎯 Meta final

Após completar os passos acima:
- ✅ Token Tiny atualizado e válido
- ✅ Daemon sincroniza 1000+ produtos a cada 6 horas
- ✅ Imagens de produtos aparecem na home
- ✅ Sistema de vendas não quebra por token expirado novamente

**Nenhuma ação técnica adicional necessária após o login.**

---

**Criado em:** 2026-07-24  
**Responsável:** Claude Code  
**Próxima revisão:** Após login bem-sucedido (confirmação de sync)
