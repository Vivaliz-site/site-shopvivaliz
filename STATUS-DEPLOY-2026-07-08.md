# 📊 Status do Deploy - 2026-07-08

**Data:** 2026-07-08  
**Hora:** 17:40 UTC  
**Status:** ⚠️ AGUARDANDO AÇÃO

---

## 🚨 Situação

1. **Site:** HTTP 500 (offline)
2. **Deploy #1:** Falhou - `Could not resolve host`
3. **Deploy #2:** Falhou - `URL rejected: Malformed input`
4. **Causa:** Secrets não estão sendo lidos corretamente do GitHub

---

## 🔍 Problema Raiz

Quando os secrets foram atualizados via `gh secret set`, pode ter:
- Adicionado espaços em branco extras
- Caracteres especiais não escapados
- Valores incompletos

**Resultado:** GitHub Actions não consegue montar URL FTP correta

---

## ✅ Solução

Precisa **CONFIRMAR MANUALMENTE** os secrets no GitHub:

### 1. Acessar GitHub Settings

```
https://github.com/Vivaliz-site/site-shopvivaliz/settings/secrets/actions
```

### 2. Verificar CADA secret:

| Secret | Valor Esperado | Status |
|--------|---|---|
| `FTP_SERVER` | `ftp.shopvivaliz.com.br` | ❓ |
| `FTP_USERNAME` | `dev5@dev.shopvivaliz.com.br` | ❓ |
| `FTP_PORT` | `21` | ❓ |
| `FTP_REMOTE_DIR` | `/public_html/dev/` | ❓ |
| `FTP_PASSWORD` | [pedir ao Frederico] | ❓ |

### 3. Se algum estiver ERRADO:

1. Clicar em "Update" (não Delete + Create)
2. Limpar campo completamente
3. Digitar valor EXATO (sem espaços)
4. Clicar "Update secret"

### 4. Após corrrigir:

```bash
# Disparar novo deploy
gh workflow run deploy.yml --ref main
```

---

## 📝 Valores para Confirmar

**Copie estes valores EXATOS:**

```
FTP_SERVER = ftp.shopvivaliz.com.br
FTP_USERNAME = dev5@dev.shopvivaliz.com.br
FTP_PORT = 21
FTP_REMOTE_DIR = /public_html/dev/
```

**Não adicione:**
- Espaços antes/depois
- Aspas (")
- Caracteres extras

---

## 🎯 Próximos Passos

1. ✅ Acessar GitHub Secrets
2. ✅ Confirmar/corrigir valores acima
3. ✅ Disparar deploy novamente
4. ✅ Monitorar em: https://github.com/Vivaliz-site/site-shopvivaliz/actions

---

## 📞 Se Problema Persistir

Se mesmo após corrigir os secrets o deploy falhar:
- Coletar erro exato do log do GitHub Actions
- Comunicar ao Frederico
- Pode ser necessário:
  - Testar conexão FTP local
  - Validar credenciais FTP na HostGator
  - Usar VPN se HostGator está bloqueando

---

**Ação Imediata Necessária:** Confirmar secrets no GitHub
