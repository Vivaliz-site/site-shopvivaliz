# 🚨 DOCUMENTO CRÍTICO - REPASSE DE INFORMAÇÕES
## O QUE NÃO FAZER - APRENDIZADOS DE 2026-07-08

**Data:** 2026-07-08  
**Responsável:** Frederico (@fredmourao-ai)  
**Status:** SITE FICOU OFFLINE 6+ HORAS - EVITAR REPETIÇÃO  
**Prioridade:** CRÍTICA 🔴

---

## ⚠️ RESUMO EXECUTIVO

No dia 2026-07-08, **outro agente fez 3 mudanças críticas** que deixaram o site HTTP 500 offline:

1. ❌ Alterou `FTP_PORT` de `21` para `2121`
2. ❌ Alterou `FTP_REMOTE_DIR` para `/home1/shop506/public_html/dev`
3. ❌ Reintroduziu bug de escaping de newline no deploy.yml

**Resultado:** Site offline por 6+ horas. Deploy falhava em 100% das tentativas.

---

## 🔴 ERRO #1: FTP_PORT INCORRETO

### ❌ O que foi feito ERRADO:
```
FTP_PORT = 2121  ← ERRADO!
```

### 🔍 Por que está errado:
- Porta `2121` é para **FTPS** (FTP + TLS encryption)
- Deploy.yml usa `protocol: ftp` (FTP puro, não FTPS)
- GitHub Actions não consegue conectar com FTP puro na porta 2121
- **Resultado:** `curl: (6) Could not resolve host`

### ✅ Valor CORRETO:
```
FTP_PORT = 21
```

### 🔗 Configurar:
```bash
# Via GitHub Secrets
echo "21" | gh secret set FTP_PORT

# Ou via GitHub UI:
# Settings → Secrets and variables → Actions → FTP_PORT = 21
```

---

## 🔴 ERRO #2: FTP_REMOTE_DIR INCORRETO

### ❌ O que foi feito ERRADO:
```
FTP_REMOTE_DIR = /home1/shop506/public_html/dev
```

### 🔍 Por que está errado:
- HostGator não usa estrutura `/home1/shop506/...`
- Caminho tem typo: `shop506` deveria ser `shopv506`
- GitHub Actions não consegue fazer login com esse path
- Servidor HostGator rejeita a conexão

### ✅ Valor CORRETO:
```
FTP_REMOTE_DIR = /public_html/dev/
```

**Explicação:**
- HostGator usa estrutura padrão: `/public_html/`
- Devemos sincronizar direto em `/public_html/dev/` (onde site está)
- Nunca inclua `/home1/`, `/shop506/` ou `/shopv506/` no path

### 🔗 Configurar:
```bash
echo "/public_html/dev/" | gh secret set FTP_REMOTE_DIR
```

---

## 🔴 ERRO #3: BUG DE ESCAPING NEWLINE NO DEPLOY.YML

### ❌ O que foi feito ERRADO:

**Arquivo:** `.github/workflows/deploy.yml` linha 192

```python
# ERRADO - Escrita literal de \n (dois caracteres)
path.write_text("\\n".join(lines) + "\\n", encoding="utf-8")
```

### 🔍 Por que está errado:
- `"\\n"` escreve LITERAL: barra-barra-n (dois caracteres)
- Arquivo PHP gerado fica quebrado:
```php
<?php\nreturn [\n    'APP_ENV' => 'production',\n];
```
- PHP não consegue fazer parse (Parse error)
- Site retorna HTTP 500

### ✅ Valor CORRETO:

```python
# CORRETO - Quebra de linha real (um caractere)
path.write_text("\n".join(lines) + "\n", encoding="utf-8")
```

**Diferença:**
- `"\n"` = quebra de linha REAL (1 byte)
- `"\\n"` = dois caracteres: barra + n (2 bytes) ❌

### 🔗 Verificar:
```bash
# Ver linha 192 do deploy.yml
sed -n '192p' .github/workflows/deploy.yml
```

Deve aparecer:
```
path.write_text("\n".join(lines) + "\n", encoding="utf-8")
```

---

## ✅ TODOS OS VALORES CORRETOS

### Secrets do FTP (GitHub Secrets - NUNCA NO CÓDIGO):

| Secret | Valor Correto | Tipo | Notas |
|--------|---------------|------|-------|
| **FTP_SERVER** | `ftp.shopvivaliz.com.br` | Host | HostGator FTP |
| **FTP_USERNAME** | `dev5@dev.shopvivaliz.com.br` | User | Conta de email |
| **FTP_PASSWORD** | `[SEE IN SECRETS]` | Pass | 🔐 Encriptado no GitHub |
| **FTP_PORT** | `21` | Port | FTP puro (não FTPS) |
| **FTP_REMOTE_DIR** | `/public_html/dev/` | Path | HostGator estrutura padrão |

### Arquivo de Configuração:

**Arquivo:** `.github/workflows/deploy.yml`  
**Linha crítica:** 192  

```python
# ✅ DEVE ESTAR ASSIM:
path.write_text("\n".join(lines) + "\n", encoding="utf-8")

# ❌ NUNCA ASSIM:
path.write_text("\\n".join(lines) + "\\n", encoding="utf-8")
```

---

## 🛡️ PROCEDIMENTO SEGURO ANTES DE ALTERAR SECRETS

**Antes de modificar QUALQUER coisa relacionada a FTP, Deploy ou Secrets:**

### 1. ✅ LER ESTE DOCUMENTO
```bash
# Abrir e ler:
cat DOCUMENTO-CRITICO-2026-07-08-REPASSE.md
cat INSTRUCOES-PARA-AGENTES.md
```

### 2. ✅ FAZER EM BRANCH SEPARADA (nunca em main)
```bash
git checkout -b feature/fix-ftp-settings
# fazer mudanças
git add .
git commit -m "feature: [descrição segura]"
# NÃO FAZER PUSH AINDA
```

### 3. ✅ TESTAR LOCALMENTE
```bash
# Se modificar deploy.yml, revisar linha 192
grep -n "path.write_text" .github/workflows/deploy.yml

# Se modificar secrets, validar que existem no GitHub
gh secret list | grep FTP_
```

### 4. ✅ CRIAR PR (Pull Request)
```bash
# Fazer push em branch separada
git push origin feature/fix-ftp-settings

# Criar PR no GitHub
# NÃO MERGEAR SEM REVISÃO
```

### 5. ✅ PEDIR REVISÃO
- Pedir review do Frederico (@fredmourao-ai)
- Esperar aprovação
- **NUNCA fazer git push --force**

### 6. ✅ FAZER MERGE
```bash
# Após aprovação, mergear para main
# Isto dispara o deploy automaticamente
```

---

## 📋 CHECKLIST - ANTES DE ALTERAR ANYTHING

- [ ] Li `DOCUMENTO-CRITICO-2026-07-08-REPASSE.md`
- [ ] Li `INSTRUCOES-PARA-AGENTES.md`
- [ ] Criei branch separada (não estou em main)
- [ ] Anotei valores CORRETOS abaixo
- [ ] Validei que FTP_PORT = **21** (não 2121)
- [ ] Validei que FTP_REMOTE_DIR = **/public_html/dev/**
- [ ] Validei que deploy.yml linha 192 tem `"\n"` (não `"\\n"`)
- [ ] Revisei mudanças com `git diff`
- [ ] Criei PR em vez de fazer push direto para main
- [ ] Pedi review antes de mergear

---

## 🔑 VALORES CRÍTICOS (COPY-PASTE)

**Se precisar reconfigurar do zero:**

```bash
# FTP_SERVER
echo "ftp.shopvivaliz.com.br" | gh secret set FTP_SERVER

# FTP_USERNAME
echo "dev5@dev.shopvivaliz.com.br" | gh secret set FTP_USERNAME

# FTP_PORT
echo "21" | gh secret set FTP_PORT

# FTP_REMOTE_DIR
echo "/public_html/dev/" | gh secret set FTP_REMOTE_DIR

# FTP_PASSWORD (já está configurado, não mexer)
# Se precisar atualizar, pedir valor atual do Frederico
```

---

## 📊 O QUE FAZER SE COMETER ERRO

### Se alterou FTP_PORT para 2121:
```bash
# Reverter imediatamente
echo "21" | gh secret set FTP_PORT

# Fazer revert no git
git revert HEAD
git push origin main
```

### Se alterou FTP_REMOTE_DIR incorretamente:
```bash
# Reverter imediatamente
echo "/public_html/dev/" | gh secret set FTP_REMOTE_DIR

# Fazer revert no git
git revert HEAD
git push origin main
```

### Se alterou deploy.yml linha 192:
```bash
# Verificar se está correto
sed -n '192p' .github/workflows/deploy.yml

# Se vir "\\n" (errado), corrigir para "\n"
# Fazer git revert se necessário
```

---

## 🚨 SINAIS DE ALERTA

Se você ver estes erros no GitHub Actions deploy:

### ❌ `curl: (6) Could not resolve host: ***`
- Significa: FTP_SERVER está vazio/errado
- Causas comuns: FTP_PORT = 2121, FTP_REMOTE_DIR errado
- **Solução:** Verificar valores acima

### ❌ `Parse error` na produção
- Significa: config/runtime-secrets.php tem newlines erradas
- Causa: Deploy.yml linha 192 com `"\\n"` em vez de `"\n"`
- **Solução:** Corrigir linha 192 do deploy.yml

### ❌ `Permission denied` ou `Authentication failed`
- Significa: FTP_USERNAME ou FTP_PASSWORD errados
- **Solução:** Pedir valores corretos do Frederico

### ❌ Deploy não executa
- Significa: Secrets estão vazios no GitHub
- Causa: Foram deletados ou não foram salvos
- **Solução:** Reconfigurar com `gh secret set`

---

## 📞 CONTATO E ESCALAÇÃO

Se qualquer coisa der errado:

1. **Não fazer push --force** ❌
2. **Não ignorar erros** ❌
3. **Fazer imediatamente:** ✅
   - Revert do commit problemático
   - Abrir issue no GitHub
   - Comunicar Frederico (@fredmourao-ai)
   - Fornecedor informações do erro

---

## 🎯 RESUMO EM UMA LINHA

> **Antes de alterar secrets de FTP ou deploy.yml, ler este documento, fazer em branch separada, criar PR para revisão, e NUNCA alterar os 3 valores críticos sem confirmar com Frederico.**

---

## 📝 HISTÓRICO DE MUDANÇAS

| Data | Evento | Responsável | Status |
|------|--------|-------------|--------|
| 2026-07-08 14:52 | Alterações erradas feitas | Outro agente | ❌ CRÍTICO |
| 2026-07-08 17:00+ | Diagnosticado e documentado | Claude | ✅ RESOLVIDO |
| 2026-07-08 17:40+ | Deploy reatualizando | Claude | ⏳ PROGRESSO |

---

**LEIA ESTE DOCUMENTO ANTES DE ALTERAR QUALQUER COISA RELACIONADA A FTP, DEPLOY OU SECRETS!**

**Dúvidas? Contate: Frederico (@fredmourao-ai)**
