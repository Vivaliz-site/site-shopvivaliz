# ⚡ CHECKLIST RÁPIDO - SECRETS E FTP
## USE ISTO TODA VEZ ANTES DE ALTERAR ALGO

---

## 🚫 OS 3 ERROS QUE DEIXARAM O SITE OFFLINE

### ❌ ERRO #1: FTP_PORT
```
ERRADO: FTP_PORT = 2121
CORRETO: FTP_PORT = 21
```
**Por quê?** Porta 2121 é FTPS, mas deploy usa FTP puro.

### ❌ ERRO #2: FTP_REMOTE_DIR
```
ERRADO: FTP_REMOTE_DIR = /home1/shop506/public_html/dev
CORRETO: FTP_REMOTE_DIR = /public_html/dev/
```
**Por quê?** HostGator estrutura padrão é /public_html/, nunca /home1/

### ❌ ERRO #3: DEPLOY.YML LINHA 192
```
ERRADO: path.write_text("\\n".join(...))
CORRETO: path.write_text("\n".join(...))
```
**Por quê?** \n vs \\n - um é newline real, outro é dois caracteres.

---

## ✅ VALORES CORRETOS

```
FTP_SERVER = ftp.shopvivaliz.com.br
FTP_USERNAME = dev5@dev.shopvivaliz.com.br
FTP_PORT = 21  ← NÃO 2121!
FTP_REMOTE_DIR = /public_html/dev/  ← NÃO /home1/shop506/...
FTP_PASSWORD = [pedir ao Frederico]
```

---

## 🛡️ ANTES DE ALTERAR ALGO

- [ ] Li este arquivo
- [ ] Criei branch separada (`git checkout -b fix/...`)
- [ ] Validei FTP_PORT = 21
- [ ] Validei FTP_REMOTE_DIR = /public_html/dev/
- [ ] Validei deploy.yml linha 192 tem `"\n"` não `"\\n"`
- [ ] Fiz commit em branch separada
- [ ] Criei PR (Pull Request)
- [ ] NÃO fiz push direto para main

---

## 🚀 COMANDO PARA VERIFICAR

```bash
# Ver valores dos secrets
gh secret list | grep FTP_

# Ver linha 192 do deploy.yml
sed -n '192p' .github/workflows/deploy.yml
# Deve aparecer: path.write_text("\n".join...
```

---

## 🆘 SE ALGO DER ERRADO

1. **PAUSE tudo**
2. **Não fazer push --force**
3. **Revertir commit:**
   ```bash
   git revert HEAD
   git push origin [sua-branch]
   ```
4. **Comunicar Frederico**

---

## 📞 DÚVIDAS?

**Leia os documentos completos:**
- `DOCUMENTO-CRITICO-2026-07-08-REPASSE.md` ← LEIA ISTO PRIMEIRO
- `INSTRUCOES-PARA-AGENTES.md`

**Contato:** Frederico (@fredmourao-ai)

---

**REGRA DE OURO:** Antes de alterar secrets/deploy, ler documentos, fazer em branch separada, criar PR!
