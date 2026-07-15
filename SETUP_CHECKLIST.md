# Checklist de Configuração - Shop Vivaliz GitHub Actions

## ✅ CHECKLIST PRÉ-DEPLOY

Use este checklist para garantir que tudo está configurado corretamente antes de fazer push.

---

## 📋 Etapa 1: Preparação Local

- [ ] Fiz clone do repositório: `git clone ...`
- [ ] Estou na branch `main`: `git branch` mostra `* main`
- [ ] Direitório `.github/workflows/` existe e tem arquivos `.yml`
- [ ] Arquivo `.env.example` existe
- [ ] Diretório `api/agent/` existe
- [ ] Arquivo `api/agent/autonomous-watchdog.php` existe

**Comando para verificar tudo:**
```bash
ls -la .github/workflows/*.yml && ls -la .env.example && ls -la api/agent/autonomous-watchdog.php
```

---

## 🔐 Etapa 2: Configurar Secrets no GitHub

Acesse: `https://github.com/fredmourao-ai/site-shopvivaliz/settings/secrets/actions`

### Secrets Obrigatórios (Deploy FTP)

- [ ] **FTP_SERVER**
  - Valor: `ftp.seuhost.com` ou IP
  - Teste: `nslookup ftp.seuhost.com` ou `ping ftp.seuhost.com`

- [ ] **FTP_USERNAME**
  - Valor: seu usuário FTP

- [ ] **FTP_PASSWORD**
  - Valor: sua senha FTP
  - ⚠️ **Nunca compartilhe este valor**

- [ ] **FTP_PORT**
  - Valor: `21` (padrão) ou sua porta
  - Teste: `telnet ftp.seuhost.com 21`

- [ ] **FTP_REMOTE_DIR**
  - Valor: `/public_html` ou `/home/usuario/public_html`
  - Teste: Conecte via FTP e verifique o caminho

### Secrets Opcionais (Watchdog)

- [ ] **SHOPVIVALIZ_AGENT_KEY** (opcional, mas recomendado)
  - Valor: sua chave de autenticação
  - Se não configurar: watchdog funcionará sem autenticação

### Secrets para Debug (Opcional)

- [ ] **ACTIONS_STEP_DEBUG**
  - Valor: `true`
  - Propósito: Habilita logs detalhados dos workflows
  - ⚠️ Remova depois de resolver problemas

---

## 🧪 Etapa 3: Testar Localmente

### 3.1 Rodar Script de Diagnóstico

```bash
bash scripts/diagnose_github_actions.sh
```

**Esperado:**
- ✓ Diretórios encontrados
- ✓ Workflows válidos
- ✓ Endpoints encontrados
- ⚠ Alguns avisos sobre secrets (normal, são adicionados no GitHub)

**Se houver ✗ erros:**
- [ ] Verifique os erros acima
- [ ] Corrija antes de fazer push
- [ ] Reexecute o diagnóstico

### 3.2 Verificar Relatório de Diagnóstico

```bash
cat reports/github-actions-diagnostic-*.txt
```

**Verify:**
- [ ] Secrets encontrados: FTP_SERVER, FTP_USERNAME, FTP_PASSWORD, FTP_PORT, FTP_REMOTE_DIR
- [ ] Endpoints existem: autonomous-watchdog.php

### 3.3 Validar Arquivos Localmente

```bash
# Validar PHP
php -l api/agent/autonomous-watchdog.php

# Validar workflows YAML
python3 -c "import yaml; yaml.safe_load(open('.github/workflows/deploy.yml'))"

# Procurar por conflitos Git
git grep -n '<<<<<<<' || echo "Nenhum conflito"

# Procurar por arquivos sensíveis
git ls-files | grep -E "\.env$" && echo "AVISO: .env commitado" || echo "OK: Nenhum .env commitado"
```

---

## 📝 Etapa 4: Configurar .env Local (Opcional)

Se quiser testar localmente com variáveis de ambiente:

```bash
# Copiar template
cp .env.example .env

# Editar com seus valores (não vai ser commitado)
nano .env
```

**Não commit** o `.env` - ele está no `.gitignore`

---

## 🚀 Etapa 5: Fazer Push

```bash
# Ver status
git status

# Adicionar mudanças
git add .

# Verificar o que vai ser commitado
git diff --cached

# Commit com mensagem descritiva
git commit -m "fix: adicionar diagnostico seguro para deploy, watchdog e CI

- Implementar validação segura de secrets com fallbacks
- Adicionar testes de conectividade FTP
- Melhorar tratamento de erros HTTP no watchdog
- Transformar CI em validações reais (PHP, Python, YAML, etc)
- Adicionar script de diagnóstico local
- Adicionar documentação completa"

# Push para main
git push origin main
```

---

## 📊 Etapa 6: Monitorar Workflows

Após o push, verifique em: `https://github.com/fredmourao-ai/site-shopvivaliz/actions`

### 6.1 Verificar Deploy

- [ ] Workflow "Deploy Automático Seguro - Shop Vivaliz" foi disparado
- [ ] Job "validate-secrets" passou (✓)
- [ ] Job "web-deploy" passou (✓) ou foi skipped
- [ ] Status final: `Success` ou `Warning`

**Se falhar:**
- [ ] Clique no job para ver logs
- [ ] Procure por erro específico
- [ ] Consulte `docs/github-actions-diagnostico.md`
- [ ] Use `bash scripts/diagnose_github_actions.sh` para debug

### 6.2 Verificar CI

- [ ] Workflow "CI AUTONOMO CONTINUO" foi disparado
- [ ] Todos os jobs passaram (✓)
- [ ] Status final: `Success`

**Se falhar:**
- [ ] Verifique qual job falhou
- [ ] Corrija o erro localmente
- [ ] Faça novo commit e push

### 6.3 Verificar Watchdog (Próximas 6 horas)

- [ ] Workflow "Autonomous Watchdog Monitor" rodará automaticamente
- [ ] Verificará saúde da aplicação
- [ ] Gerará relatório em artifacts

---

## 🔍 Etapa 7: Validação Final

Após tudo passar, verifique:

- [ ] Deploy executou com sucesso
- [ ] CI passou todas as validações
- [ ] Nenhum erro de secret
- [ ] Site respondeu ao smoke test
- [ ] Watchdog conseguiu executar

**Teste manualmente:**

```bash
# Testar deploy
curl -v https://dev.shopvivaliz.com.br/

# Testar watchdog
curl -v https://dev.shopvivaliz.com.br/api/agent/autonomous-watchdog.php

# Testar health
curl -v https://dev.shopvivaliz.com.br/api/health.php
```

---

## ⚠️ Troubleshooting Rápido

### "secret obrigatório ausente"

**Solução:**
1. Vá para Settings → Secrets and variables → Actions
2. Verifique se o secret existe
3. Verifique se o nome está exato (maiúsculas/minúsculas)
4. Reexecute o workflow

### "Falha ao conectar ao servidor FTP"

**Solução:**
1. Verifique se FTP_SERVER é acessível: `ping ftp.seuhost.com`
2. Verifique se FTP_PORT está correto: `telnet ftp.seuhost.com 21`
3. Verifique se FTP_USERNAME e FTP_PASSWORD estão corretos
4. Teste manualmente: `curl -v ftp://user:pass@ftp.seuhost.com:21/`

### "Watchdog retorna 404"

**Solução:**
1. Verifique se `api/agent/autonomous-watchdog.php` foi deployado
2. Teste: `curl -v https://dev.shopvivaliz.com.br/api/agent/autonomous-watchdog.php`
3. Se retorna 404, o deploy pode ter falhado
4. Verifique FTP_REMOTE_DIR

### "CI falha por sintaxe"

**Solução:**
1. Verifique o erro específico no output do workflow
2. Corrija o arquivo localmente
3. Valide: `php -l arquivo.php`
4. Faça novo commit e push

---

## 📞 Precisa de Ajuda?

**Referência completa:** `docs/github-actions-diagnostico.md`

**Script de diagnóstico:** `bash scripts/diagnose_github_actions.sh`

**Arquivo de relatório:** `GITHUB_ACTIONS_FIXES.md`

---

## ✨ Pronto!

Parabéns! Se você passou por todas as etapas acima, seus workflows estão configurados corretamente! 🎉

**Proximas execuções:**
- Deploy: Automático após push na `main`
- CI: A cada 4 horas
- Watchdog: A cada 6 horas

**Monitorar em:** https://github.com/fredmourao-ai/site-shopvivaliz/actions
