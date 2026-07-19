# Diagnóstico e Configuração de GitHub Actions - Shop Vivaliz

## 📋 Visão Geral

Este documento explica como configurar, diagnosticar e solucionar problemas com os workflows do GitHub Actions do projeto Shop Vivaliz.

**Workflows principais:**
- `deploy.yml` - Deploy automático via FTP
- `autonomous-watchdog.yml` - Monitor de saúde da aplicação
- `ci-autonomo-continuo.yml` - CI com validações de código

---

## 🔑 Secrets Obrigatórios

### Deploy FTP (deploy.yml)

| Secret | Tipo | Descrição | Fallbacks |
|--------|------|-----------|-----------|
| `FTP_SERVER` | obrigatório | Host/IP do servidor FTP | `FTP_HOST` |
| `FTP_USERNAME` | obrigatório | Usuário FTP | `FTP_USER` |
| `FTP_PASSWORD` | obrigatório | Senha FTP | `FTP_PASS` |
| `FTP_PORT` | opcional | Porta FTP (padrão: 21) | nenhum |
| `FTP_REMOTE_DIR` | obrigatório | Diretório remoto no servidor | `FTP_TARGET_DIR`, `FTP_PATH` |

**Exemplo de valores:**
```
FTP_SERVER=ftp.seuservidor.com
FTP_USERNAME=seu_usuario
FTP_PASSWORD=sua_senha_super_secreta
FTP_PORT=21
FTP_REMOTE_DIR=/public_html/shopvivaliz
```

### Watchdog (autonomous-watchdog.yml)

| Secret | Tipo | Descrição | Fallbacks |
|--------|------|-----------|-----------|
| `SHOPVIVALIZ_AGENT_KEY` | opcional* | Chave de autenticação do watchdog | `AGENT_KEY`, `WATCHDOG_AGENT_KEY`, `AUTONOMOUS_AGENT_KEY` |

*Se não configurado, o watchdog funcionará sem autenticação, mas pode retornar erros 401/403 se o endpoint exigir.

---

## 🔧 Como Configurar Secrets no GitHub

### Passo a Passo

1. **Acesse o repositório** → `Settings`
2. **Vá para** `Secrets and variables` → `Actions`
3. **Clique em** `New repository secret`
4. **Preencha:**
   - **Name:** (ex: `FTP_SERVER`)
   - **Secret:** (ex: `ftp.seuservidor.com`)
5. **Clique em** `Add secret`

### Aliases/Fallbacks

Os workflows suportam **aliases** para compatibilidade com configurações antigas:

```
FTP_SERVER        ← primário
  └─ FTP_HOST     ← fallback

FTP_USERNAME      ← primário
  └─ FTP_USER     ← fallback

FTP_PASSWORD      ← primário
  └─ FTP_PASS     ← fallback

FTP_REMOTE_DIR    ← primário
  ├─ FTP_TARGET_DIR ← fallback
  └─ FTP_PATH     ← fallback

SHOPVIVALIZ_AGENT_KEY ← primário
  ├─ AGENT_KEY         ← fallback
  ├─ WATCHDOG_AGENT_KEY ← fallback
  └─ AUTONOMOUS_AGENT_KEY ← fallback
```

**Recomendação:** Use os nomes primários e remova os aliases antigos quando possível.

---

## 🚀 Workflows

### 1. Deploy Automático (deploy.yml)

**Quando executa:**
- Automaticamente após push na branch `main` (exceto certos diretórios)
- Manualmente via `Actions` → `Deploy Automático Seguro - Shop Vivaliz` → `Run workflow`

**Etapas:**
1. ✅ Validar secrets (sem expor valores)
2. ✅ Conectar ao FTP
3. ✅ Fazer upload dos arquivos
4. ✅ Smoke test (curl no site)
5. ✅ Registrar status

**Se falhar:**
```bash
# Verifique os secrets:
# 1. Todos os 5 secrets FTP estão configurados?
# 2. FTP_SERVER e credenciais estão corretos?
# 3. FTP_REMOTE_DIR é o caminho correto?

# Teste manualmente:
curl -v ftp://seu_usuario:sua_senha@ftp.seuservidor.com:21/

# Habilite debug:
# Settings → Secrets → New secret → ACTIONS_STEP_DEBUG = true
```

### 2. Autonomous Watchdog (autonomous-watchdog.yml)

**Quando executa:**
- A cada 6 horas (cron: `0 */6 * * *`)
- Manualmente via `Actions` → `Autonomous Watchdog Monitor` → `Run workflow`

**Etapas:**
1. ✅ Validar agent_key (com fallbacks)
2. ✅ Verificar se endpoint existe
3. ✅ Chamar endpoint `/api/agent/autonomous-watchdog.php`
4. ✅ Validar resposta JSON
5. ✅ Upload do relatório

**Endpoints que o watchdog monitora:**
- `/` (página inicial)
- `/api/health.php` (saúde da aplicação)
- `/api/catalog/products.php` (API de produtos)
- `/api/agent/squad-chat.php` (chat de agentes)
- `/api/agent/autonomous-report.php` (relatório autônomo)

**Se falhar:**

```bash
# Verifique se o endpoint existe:
curl -v https://shopvivaliz.com.br/api/agent/autonomous-watchdog.php

# Teste com agent_key (se configurada):
AGENT_KEY="seu_valor_secreto"
curl -v "https://shopvivaliz.com.br/api/agent/autonomous-watchdog.php?agent_key=$AGENT_KEY"

# Verifique erros HTTP específicos:
# - 401/403: Chave inválida ou ausente
# - 404: Endpoint não existe (verifique deploy)
# - 500: Erro no servidor (verifique logs PHP)
```

### 3. CI Autônomo (ci-autonomo-continuo.yml)

**Quando executa:**
- A cada 4 horas (cron: `0 */4 * * *`)
- Manualmente via `Actions` → `CI AUTONOMO CONTINUO` → `Run workflow`

**Validações:**
- ✅ Conflitos Git (marcadores `<<<<<<<`, `=======`, `>>>>>>>`)
- ✅ Sintaxe YAML dos workflows
- ✅ Sintaxe JSON
- ✅ Sintaxe PHP (se disponível)
- ✅ Sintaxe Python (se disponível)
- ✅ Arquivos sensíveis commitados (`.env`, tokens, etc)
- ✅ Credenciais hardcoded em código
- ✅ Existência de endpoints críticos

**Se falhar:**
- Verifique os erros específicos no output do workflow
- Corrija o código localmente
- Faça commit e push

---

## 📊 Script de Diagnóstico Local

### Executar diagnóstico

```bash
bash scripts/diagnose_github_actions.sh
```

**O que faz:**
- ✅ Lista todos os secrets referenciados nos workflows
- ✅ Valida sintaxe YAML/JSON
- ✅ Valida sintaxe PHP/Python
- ✅ Procura por conflitos Git
- ✅ Procura por arquivos sensíveis
- ✅ Verifica existência de endpoints
- ✅ Gera relatório em `reports/`

**Exemplo de output:**
```
▶ Auditando Secrets Referenciados nos Workflows

ℹ Procurando por referências de secrets nos YAMLs...

✓ Secrets encontrados nos workflows:
  • AGENT_KEY
  • FTP_PASSWORD
  • FTP_PORT
  • FTP_REMOTE_DIR
  • FTP_SERVER
  • FTP_USERNAME
  • SHOPVIVALIZ_AGENT_KEY
```

---

## 🔍 Troubleshooting

### Deploy FTP falha com erro de conexão

**Possíveis causas:**
1. `FTP_SERVER` incorreto ou inacessível
2. `FTP_USERNAME` ou `FTP_PASSWORD` errados
3. Firewall bloqueando porta FTP
4. Servidor FTP está offline

**Solução:**
```bash
# Teste a conectividade:
curl -v ftp://usuario:senha@ftp.seuservidor.com:21/

# Ou com telnet:
telnet ftp.seuservidor.com 21

# Verifique os secrets no GitHub:
# Settings → Secrets and variables → Actions
# Confirme que estão preenchidos corretamente
```

### Watchdog retorna HTTP 404

**Causa:** Arquivo `api/agent/autonomous-watchdog.php` não existe ou não foi deployado

**Solução:**
```bash
# Verifique se o arquivo existe localmente:
ls -la api/agent/autonomous-watchdog.php

# Verifique no servidor:
curl -I https://shopvivaliz.com.br/api/agent/autonomous-watchdog.php

# Se retornar 404, o deploy pode ter falhado:
# 1. Verifique os logs do deploy.yml
# 2. Teste o FTP deploy manualmente
# 3. Verifique FTP_REMOTE_DIR
```

### Watchdog retorna HTTP 403/401

**Causa:** `SHOPVIVALIZ_AGENT_KEY` ausente, inválida ou endpoint exige autenticação

**Solução:**
```bash
# Configure a agent_key:
# Settings → Secrets and variables → Actions
# New secret: SHOPVIVALIZ_AGENT_KEY = seu_valor_secreto

# Ou use um dos fallbacks:
# - AGENT_KEY
# - WATCHDOG_AGENT_KEY
# - AUTONOMOUS_AGENT_KEY

# Teste com curl:
curl -v "https://shopvivaliz.com.br/api/agent/autonomous-watchdog.php?agent_key=seu_valor"
```

### CI falha por arquivos com sintaxe quebrada

**Solução:**
1. Verifique o erro específico no output do workflow
2. Corrija o arquivo localmente
3. Valide antes de fazer commit:

```bash
# Validar PHP:
php -l meu_arquivo.php

# Validar Python:
python3 -m py_compile meu_arquivo.py

# Validar YAML:
python3 -c "import yaml; yaml.safe_load(open('meu_arquivo.yml'))"

# Validar JSON:
python3 -c "import json; json.load(open('meu_arquivo.json'))"
```

### CI encontra arquivo `.env` commitado

**Problema:** `.env` com credenciais foi commitado por acidente

**Solução:**
```bash
# 1. Remova o arquivo:
git rm --cached .env
echo ".env" >> .gitignore

# 2. Commit:
git add .gitignore
git commit -m "fix: remover .env do histórico"

# 3. Considere usar git-filter-branch se for crítico:
git filter-branch --tree-filter 'rm -f .env' HEAD
```

---

## 📝 Configuração Mínima para Funcionar

1. **Criar arquivo `.env` localmente:**
   ```bash
   cp .env.example .env
   # Edite .env com valores reais
   ```

2. **Configurar secrets no GitHub:**
   - `FTP_SERVER`
   - `FTP_USERNAME`
   - `FTP_PASSWORD`
   - `FTP_PORT` (opcional, padrão: 21)
   - `FTP_REMOTE_DIR`
   - `SHOPVIVALIZ_AGENT_KEY` (opcional)

3. **Rodar diagnóstico local:**
   ```bash
   bash scripts/diagnose_github_actions.sh
   ```

4. **Fazer push e aguardar workflows:**
   - Deploy rodará após push na `main`
   - Watchdog rodará a cada 6 horas
   - CI rodará a cada 4 horas

---

## 🛡️ Segurança

### O que NÃO fazer:

❌ Commitar secrets em código  
❌ Imprimir valores de secrets nos logs  
❌ Usar secrets públicos em URLs  
❌ Armazenar senhas em `.env` versionado  
❌ Compartilhar secrets em Slack/email  

### Boas práticas:

✅ Use `.env.example` para template  
✅ Adicione `.env` ao `.gitignore`  
✅ Use secrets do GitHub Actions  
✅ Rotacione credenciais regularmente  
✅ Audit quem tem acesso aos secrets  
✅ Use fallbacks apenas para compatibilidade, não para produção  

---

## 📞 Suporte

Para debug detalhado:

1. **Habilite debug logging no GitHub:**
   - `Settings` → `Secrets and variables` → `Actions`
   - `New secret`: `ACTIONS_STEP_DEBUG = true`
   - Reexecute o workflow

2. **Verifique logs locais:**
   ```bash
   # Logs do projeto
   tail -f logs/watchdog.log
   tail -f logs/deploy.log
   ```

3. **Rode script de diagnóstico:**
   ```bash
   bash scripts/diagnose_github_actions.sh
   # Verifique o relatório em reports/
   ```

4. **Teste endpoints manualmente:**
   ```bash
   curl -v https://shopvivaliz.com.br/api/agent/autonomous-watchdog.php
   curl -v https://shopvivaliz.com.br/api/health.php
   ```

---

## 📚 Referências

- [GitHub Actions Documentation](https://docs.github.com/en/actions)
- [GitHub Secrets](https://docs.github.com/en/actions/security-guides/encrypted-secrets)
- [Workflow Triggers](https://docs.github.com/en/actions/using-workflows/triggering-a-workflow)
- [Actions - Status Check](https://docs.github.com/en/actions/guides/creating-postgresql-service-containers)
