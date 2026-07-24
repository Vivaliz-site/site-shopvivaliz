# ⚠️ INSTRUÇÕES CRÍTICAS PARA AGENTES AUTÔNOMOS

## Histórico de Bugs Causados por Alterações Conflitantes

**Data:** 2026-07-08 às 14:52+  
**Causa:** Outro agente/PC fez mudanças conflitantes em secrets de FTP e deploy.yml  
**Resultado:** Site ficou FORA DO AR com HTTP 500 (6+ horas)

---

## ❌ MUDANÇAS QUE FORAM FEITAS INCORRETAMENTE

### 1. FTP_PORT alterado para 2121 (FTPS)
**Arquivo:** GitHub Secrets → `FTP_PORT`  
**Alteração incorreta:** `21` → `2121`  
**Problema:** 
- Porta 2121 é FTPS (FTP + TLS)
- Deploy.yml usa `protocol: ftp` (FTP puro, não FTPS)
- GitHub Actions não consegue conectar
- Resultado: deploy falha imediatamente

**Valores CORRETOS:**
```
FTP_PORT = 21  (FTP padrão, não FTPS)
```

---

### 2. FTP_REMOTE_DIR alterado para caminho incorreto
**Arquivo:** GitHub Secrets → `FTP_REMOTE_DIR`  
**Alteração incorreta:** `/public_html/dev/` → `/home1/shop506/public_html/dev`  
**Problema:**
- `shop506` está ERRADO - deveria ser `shopv506` (falta o "v")
- GitHub Actions não consegue fazer login
- Resultado: deploy falha

**Valores CORRETOS:**
```
FTP_REMOTE_DIR = /public_html/dev/  (HostGator estrutura padrão)
```

---

### 3. Bug de escaping de newline no deploy.yml
**Arquivo:** `.github/workflows/deploy.yml` linha 192  
**Alteração incorreta:**
```python
path.write_text("\n".join(lines) + "\n", encoding="utf-8")
```
**Mudou para:**
```python
path.write_text("\\n".join(lines) + "\\n", encoding="utf-8")
```
**Problema:**
- Escrita literal de `\n` (dois caracteres) em vez de quebra de linha
- Gera arquivo PHP com sintaxe quebrada:
```php
<?php\nreturn [\n    'APP_ENV' => 'production',\n];
```
- Resultado: `Parse error` ao executar PHP

**Valores CORRETOS:**
```python
path.write_text("\n".join(lines) + "\n", encoding="utf-8")  # Quebra de linha real
```

---

### 4. Removeu paso crítico de upload do config/runtime-secrets.php
**Arquivo:** `.github/workflows/deploy.yml`  
**O que foi removido:** Step "Subir config/runtime-secrets.php via FTP direto"  
**Problema:**
- `config/runtime-secrets.php` é gerado dinamicamente no job (linha 149-193)
- Nunca está no git (no .gitignore)
- FTP-Deploy-Action sincroniza do estado do git
- Arquivo gerado nunca chegava ao servidor
- Resultado: `config/runtime-secrets.php` faltava no servidor

**Ação necessária:**
- RE-ADICIONAR o step de upload manual (era 50 linhas, procure no git history)
- OU implementar alternativa segura

---

## ✅ O QUE FOI CORRIGIDO

| Secret | Incorreto | Correto | Status |
|--------|----------|---------|--------|
| FTP_PORT | 2121 | 21 | ✅ Corrigido |
| FTP_REMOTE_DIR | /home1/shop506/.../dev | /public_html/dev/ | ✅ Corrigido |
| FTP_SERVER | (não alterado) | ftp.shopvivaliz.com.br | ✅ OK |
| FTP_USERNAME | (não alterado) | dev5@dev.shopvivaliz.com.br | ✅ OK |
| deploy.yml linha 192 | `\\n` (errado) | `\n` (correto) | ✅ Corrigido |

---

## 🚨 PROCEDIMENTO PARA EVITAR NO FUTURO

**Antes de modificar qualquer desses arquivos/secrets:**

1. ✅ Ler esta documentação
2. ✅ Testar mudanças em branch feature (não em main)
3. ✅ Executar o workflow (`.github/workflows/deploy.yml`) localmente
4. ✅ Validar que FTP consegue conectar:
   ```bash
   curl -v ftp://ftp.shopvivaliz.com.br:21/ \
     --user dev5@dev.shopvivaliz.com.br:[senha]
   ```
5. ✅ Fazer review do diff antes de merge
6. ✅ **Validar visualmente no browser:** Antes de declarar sucesso, abra as páginas web alteradas no browser e tire prints/gravações para comprovar que o visual e a interação estão 100% corretos.

---

## 🔗 Referências

- Secrets do GitHub: https://github.com/Vivaliz-site/site-shopvivaliz/settings/secrets/actions
- Deploy workflow: `.github/workflows/deploy.yml`
- FTP Credentials: `dev5@dev.shopvivaliz.com.br` @ `ftp.shopvivaliz.com.br:21`
- Remote Dir: `/public_html/dev/` (não inclua domínio/username)

---

## 📋 IMPLEMENTAÇÕES DE SECRETS HOJE (2026-07-08)

### ✅ O QUE FOI CRIADO/IMPLEMENTADO

#### 1. **Sistema Centralizado de Secrets** 
**Arquivo:** `config/secrets.py` (800+ linhas)
- Centraliza 150+ variáveis de configuração
- Implementa `load_env_file()` para parsing manual de .env
- Função `get_all_secrets()` exporta todas as configurações
- Função `validate_secrets()` valida que secrets obrigatórios existem
- Função `mask_secret()` mascara valores em logs
- 13 seções organizadas: IA APIs, Shopee, Amazon, Olist, TikTok, FTP, Email, Payment, etc

**Como usar:**
```python
from config.secrets import SHOPEE_API_KEY, FTP_PASSWORD
```

#### 2. **Bootstrap Environment**
**Arquivo:** `config/bootstrap-env.php`
- Carrega secrets do `config/runtime-secrets.php` (gerado em deploy)
- Fallback para `config/runtime-secrets-default.php` se arquivo real não existe
- Função `sv_bootstrap_env_assign()` atribui variáveis com segurança
- Função `sv_bootstrap_env()` inicializa um única vez

**Como usar:**
```php
require_once __DIR__ . '/config/bootstrap-env.php';
sv_bootstrap_env();
echo getenv('FTP_PASSWORD'); // Carregado automaticamente
```

#### 3. **Geração Dinâmica de Secrets em Deploy**
**Arquivo:** `.github/workflows/deploy.yml` (linhas 149-193)
- Gera `config/runtime-secrets.php` no job com valores dos GitHub Secrets
- Arquivo nunca é commitado (no .gitignore)
- Gerado apenas em tempo de deploy
- **BUG CORRIGIDO HOJE:** linha 192 tinha `\\n` em vez de `\n`

#### 4. **Sincronização de Secrets do GitHub**
**Arquivos criados:**
- `scripts/sincronizar_secrets_github.py` - Script Python cross-platform
- `scripts/sincronizar_secrets_github.sh` - Script Bash para Linux/Cloud
- Gera `.env.local` local com secrets do GitHub Secrets

#### 5. **Validação Automática de Secrets**
**Arquivo:** `scripts/validar_secrets.py`
- Valida que TODOS os secrets obrigatórios estão configurados
- Mascara valores sensíveis em output
- Executa em cada deploy

#### 6. **Auto-Sync de Secrets**
**Para Windows:**
- `scripts/setup_auto_sync.ps1` - Cria Task Scheduler
- `scripts/auto_sync_git.ps1` - Sync a cada 5 minutos
- Sincroniza secrets + git pull/push

**Para Linux/Cloud:**
- `scripts/setup-auto-sync-linux.sh` - Instala systemd service
- `scripts/auto-sincronizar.sh` - Daemon que sincroniza a cada 5 minutos

### ⚠️ O QUE FOI TENTADO (E FALHOU)

#### ❌ Adicionar Meta Tags/SEO
- Criado `api/seo/meta-tags.php`
- Adicionado `sitemap-generator.php`
- **PROBLEMA:** Causou HTTP 500 no servidor (arquivo não deployou corretamente)
- **SOLUÇÃO:** Revertido por enquanto até FTP funcionar

#### ❌ API Olist Sincronização
- Criado `api/olist/sync-orders.php`
- **PROBLEMA:** Mesma questão de deploy
- **SOLUÇÃO:** Revertido por enquanto

### 🔑 SECRETS CRÍTICOS HOJE CORRIGIDOS

| Secret | Valor Correto | Notas |
|--------|---------------|-------|
| FTP_SERVER | ftp.shopvivaliz.com.br | Host HostGator |
| FTP_USERNAME | dev5@dev.shopvivaliz.com.br | Usuário FTP |
| FTP_PORT | 21 | FTP puro (não FTPS 2121) |
| FTP_REMOTE_DIR | /public_html/dev/ | Não inclua /home1/shop506 |
| FTP_PASSWORD | *** | Sincronizado com GitHub Secrets |

### 📚 ARQUIVOS CRIADOS PARA DOCUMENTAÇÃO

- `PLANO-IMPLEMENTACAO-SEGURA.md` - Estratégia incremental
- `RELATORIO-FINAL-TAREFAS-2026-07-08.md` - Resumo técnico
- `RESUMO-EXECUCAO-HOJE.md` - O que foi feito
- `STATUS-FINAL-DIAGNOSTICO.md` - Diagnóstico do problema
- `INSTRUCOES-PARA-AGENTES.md` - Este arquivo
- `PLANO-FINALIZACAO-2026-07-08.md` - Plano de fechamento

### ✅ LIÇÕES APRENDIDAS

1. **Secrets devem estar APENAS no GitHub Secrets** - nunca hardcoded
2. **config/runtime-secrets.php é gerado em deploy** - não deve estar no git
3. **FTP_PORT deve ser 21** - não 2121 (protocol: ftp não suporta FTPS)
4. **FTP_REMOTE_DIR é simples** - /public_html/dev/, não caminhos complexos
5. **Bug de escaping newline** - `\n` não `\\n` na geração de PHP

---

## 📝 Contato

Se precisar alterar esses valores:
- Confirme com Frederico (@fredmourao-ai) antes
- Teste em branch separada primeiro
- Abra uma PR para revisão antes de mergear
- Use **SEMPRE** GitHub Secrets, nunca hardcode no código

**Nunca committar secrets reais no git!** Usar sempre GitHub Secrets.
