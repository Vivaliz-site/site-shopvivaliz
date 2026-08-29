# 🤖 GUIA OBRIGATÓRIO PARA AGENTES IA

**Efetivo:** 2026-07-15  
**Responsável:** Todos os agentes (Claude, Codex, Gemini, GPT, etc.)  
**Escopo:** Qualquer tarefa automatizada (deploy, testes, integrações, ERP, pagamentos, e-mails)  

> ⚠️ **CRÍTICO:** Ler primeiro [`REGRAS-AGENTES-CENTRALIZADAS.md`](REGRAS-AGENTES-CENTRALIZADAS.md), fonte única de verdade para a política completa.

---

## ⛔ REGRAS OBRIGATÓRIAS (Resumo Executivo)

### 0. Autorização operacional vigente

O proprietário autoriza os agentes com acesso técnico válido a validar pelo navegador real, preparar a entrega para revisão, aprovar PRs, fazer merge e acionar ou executar deploy sem nova aprovação explícita, desde que os checks e as proteções do repositório permitam. A autorização não elimina a necessidade de evidência independente, nem permite force-push, bypass de proteção, secrets, cobranças reais ou ações destrutivas fora do escopo.

### 0.1 Commit, PR e Merge Obrigatório ao Finalizar

> ⚠️ **CRÍTICO:** Qualquer alteração feita no repositório, ao ser finalizada, deve obrigatoriamente ser validada e seguir o fluxo de:
> 1. **Commit** das alterações locais.
> 2. Abertura/atualização de **Pull Request (PR)**.
> 3. Realização de **Merge** para a branch alvo.
> 
> Toda alteração deve ser validada pelo navegador de forma visual e funcional (nada de scripts para essa validação) e seguir este fluxo. Não finalize rodadas de alterações mantendo-as apenas locais ou sem merge/deploy. Se houver impedimento, registre formalmente como **INCONCLUSIVO**.

### 1. NUNCA Use `git reset --hard` em Produção

**Proibido SEMPRE:**
```bash
git reset --hard origin/main          # ❌ NUNCA
git reset --hard origin/[branch]      # ❌ NUNCA
git reset --hard HEAD~1               # ❌ NUNCA
```

**Por quê:**
- Descarta arquivos não versionados
- Mata dados de runtime (pedidos, caches, logs)
- Não recuperável sem backup
- Viola integridade de dados operacionais

**Alternativas seguras:**
```bash
git fetch origin                       # ✅ SEGURO
git merge --ff-only origin/main       # ✅ SEGURO (falha se não-FF)
git pull --ff-only origin main        # ✅ SEGURO (rejeita merges)
```

---

### 2. Validar Working Tree Antes de Git Pull

**Obrigatório fazer ANTES de git pull/merge/fetch:**

```bash
set -Eeuo pipefail  # Falhar imediatamente em erros

# 1. Verificar status
git status --porcelain

# 2. Se há alterações NÃO commitadas:
if [[ -n "$(git status --porcelain)" ]]; then
    echo "❌ Working tree sujo. Abortar."
    exit 1
fi

# 3. DEPOIS fazer pull seguro
git fetch origin
git merge --ff-only origin/main  # Falha se não é Fast-Forward
```

---

### 3. Todo Script Shell DEVE Usar `set -Eeuo pipefail`

**Obrigatório na primeira linha:**

```bash
#!/bin/bash
set -Eeuo pipefail  # ← OBRIGATÓRIO

# Qualquer erro agora para a execução
somecommand | grep pattern  # Se grep falhar, script falha
```

---

### 4. Validar Código de Saída de Todo Comando

**Padrão correto:**

```bash
# ✅ CORRETO
if ! command arg; then
    echo "❌ Comando falhou"
    exit 1
fi

# ❌ ERRADO
command arg
echo "✅ Sucesso"  # Roda mesmo se command falhou
```

---

### 5. Registre Estado ANTES e DEPOIS

**Obrigatório para testes:**

```bash
echo "=== ANTES ===" 
git log --oneline -1
git status --porcelain
date -u

echo "=== TESTANDO ==="
# ... seu teste ...

echo "=== DEPOIS ===" 
git log --oneline -1
```

---

### 6. NUNCA Declare "Sucesso" Sem Evidência

**Proibido:**

| ❌ NÃO FAÇA | ✅ FAÇA |
|-----------|--------|
| "100% operacional" | "COMPROVADO: SHA bate" |
| "funcionando" | "FALHOU: erro no log" |
| "daemon rodando" | "INCONCLUSIVO: sem evidência" |

---

### 7. Testes Reais, Nunca Simulação

- Se você executa `git pull/reset/fetch` DEPOIS do push, invalidou o teste
- Teste real = push + aguardar daemon + SEM intervir

---

### 8. Separar Claramente: Preparação, Disparo, Espera, Observação

```bash
# FASE 1: PREPARAÇÃO
echo "=== PREPARAÇÃO ===" 
git checkout main
git log --oneline -1

# FASE 2: DISPARO
echo "=== DISPARO ===" 
git commit --allow-empty -m "test: sync"
EXPECTED_SHA=$(git rev-parse HEAD)
git push origin main

# FASE 3: ESPERA (SEM INTERVIR!)
echo "Aguardando 4 minutos..."
sleep 240

# FASE 4: OBSERVAÇÃO
echo "=== OBSERVAÇÃO ===" 
ACTUAL_SHA=$(ssh ubuntu@vm "git -C /home/ubuntu/site-shopvivaliz rev-parse HEAD")
[[ "$ACTUAL_SHA" == "$EXPECTED_SHA" ]] && echo "✅ OK" || echo "❌ FALHOU"
```

---

### 9. Proteja Dados Operacionais

**NUNCA comitte:**

```
storage/orders/
storage/codex-bridge/state.json
storage/orchestrator/queue.json
.agent-heartbeats/
.git-sync.lock
```

---

### 10. Em Caso de Erro: PARAR Imediatamente

```bash
set -Eeuo pipefail  # Faz script falhar automaticamente em erros
git fetch origin    # Se isso falha, próximas linhas NÃO rodam
git merge --ff-only origin/main
```

---

## 📋 CHECKLIST

- [ ] `set -Eeuo pipefail` em scripts
- [ ] Validei código de saída
- [ ] Registrei ANTES e DEPOIS
- [ ] Teste é REAL
- [ ] NÃO usei `git reset --hard`
- [ ] Nenhuma falsa afirmação
- [ ] Status é COMPROVADO/FALHOU/INCONCLUSIVO

---

## 🏗️ NOVA ARQUITETURA: RELEASES IMUTÁVEIS (Ativo desde 2026-07-25)

**Produção migrou de `git merge` vivo para releases imutáveis.**

Estrutura pós-migração:
```
/home/ubuntu/shopvivaliz-deploy/
├── repo/            (clone limpo, somente leitura)
├── releases/        (releases imutáveis)
└── current → releases/20260725-143500-2489d8d9/

/var/lock/shopvivaliz-deploy.lock (flock, impede concorrência)
```

### O Que Mudou Para Agentes

**ANTES (2026-07-24 e antes):**
```bash
git merge --ff-only origin/main  # ❌ Na árvore viva
                                  # ❌ Bloqueia por .env/.cache modificados
                                  # ❌ Sem rollback rápido
```

**DEPOIS (2026-07-25 e depois):**
```bash
/home/ubuntu/shopvivaliz-deploy/repo/scripts/deploy-production.sh
# ✅ Cria nova release limpa
# ✅ Separado de dados runtime
# ✅ Rollback atômico
# ✅ idempotente (roda 2x, mesmo resultado)
```

### Regras para Deploy (Novo)

1. **NUNCA editar dentro:**
   ```
   /home/ubuntu/shopvivaliz-deploy/releases/<ativa>/
   /home/ubuntu/shopvivaliz-deploy/current/
   ```

2. **Dados runtime vão em `/shared/`:**
   ```
   .env, uploads/, logs/, cache/, sessions/
   ```

3. **Deploy é pull-based:**
   - VM faz `git fetch` a cada 2 min (cron)
   - Detecta novo SHA
   - Cria nova release
   - Troca symlink atomicamente
   - **Você não pusheia release**, você pusheia commits
   - Se existir `/home/ubuntu/shopvivaliz-deploy/shared/deploy-target-ref`, o cron passa a perseguir esse branch/SHA em vez de `origin/main`

4. **Rollback é manual:**
   ```bash
   sudo /home/ubuntu/shopvivaliz-deploy/repo/scripts/rollback-production.sh
   ```

### Toolkit Local Obrigatório (Windows)

Os seguintes atalhos locais ficam em `C:\Users\FRED\.local\bin` e devem ser preferidos por agentes quando a sessão tiver acesso ao terminal local:

```powershell
docker-check
docker-up
docker-down
sv-vm-ssh hostname
sv-vm-status
sv-deploy-head -DryRun
sv-deploy-sha <sha> -DryRun
sv-blog-status
sv-blog-publish
```

Regras:

1. A chave SSH operacional atual da VM é:
   ```text
   C:\Users\user\Downloads\ssh-key-2026-07-04.key  (ou C:\Users\FRED\Downloads\ssh-key-2026-07-04.key depending on profile)
   ```

2. `sv-deploy-head` e `sv-deploy-sha` atualizam o alvo persistente de produção em:
   ```text
   /home/ubuntu/shopvivaliz-deploy/shared/deploy-target-ref
   ```
   Isso impede que o cron volte automaticamente para `main` enquanto um branch/SHA específico estiver fixado.

3. Antes de afirmar que produção ficou publicada, registrar no mínimo:
   - `sv-vm-status`
   - `sv-blog-status` se a alteração tocar blog/Liz/conteúdo
   - HTTP 200 ou artefato real do endpoint afetado

4. Se a release ativa voltar para `main` após alguns minutos, isso é FALHA operacional do alvo persistente ou do runner antigo, não sucesso de deploy.

### Editores Padronizados (Windows)

Para reduzir drift entre sessões e evitar edição no checkout errado, os editores locais devem abrir sempre o mesmo worktree operacional:

```text
Worktree padrão: C:\site-shopvivaliz-prod-liz
Branch validado: agent/liz-widget-prod
SHA validado em 2026-07-29: 19328ac2
```

Regras:

1. Antes de editar ou publicar, confirmar:
   ```bash
   git -C C:\site-shopvivaliz-prod-liz branch --show-current
   git -C C:\site-shopvivaliz-prod-liz rev-parse --show-toplevel
   ```

2. Não assumir que `C:\site-shopvivaliz` é o checkout ativo. Ele pode existir em paralelo e conter estado diferente.

3. Atalhos padronizados criados em 2026-07-29:
   ```text
   C:\Users\FRED\Desktop\ShopVivaliz - VS Code.lnk
   C:\Users\FRED\Desktop\ShopVivaliz - Antigravity.lnk
   C:\Users\FRED\Desktop\ShopVivaliz - Antigravity IDE.lnk
   ```
   Todos apontam para `C:\site-shopvivaliz-prod-liz`.

4. Start Menu também foi padronizado para abrir o mesmo worktree em:
   - `Visual Studio Code`
   - `Antigravity`
   - `Antigravity IDE`

5. Preferência operacional atual de agentes:
   - VS Code: `Roo`, `Claude`, `GPT/Codex`
   - Antigravity: usado para fluxo com `Gemini`
   - Antigravity IDE: manter alinhado ao mesmo worktree e mesmas regras de terminal/comandos

6. O terminal dos editores deve conseguir resolver:
   ```powershell
   sv help
   docker-check
   docker-up
   docker-down
   ```
   Se isso falhar, a sessão não está corretamente alinhada ao ambiente operacional local.

### ShopVivaliz Mobile Agent Bridge

Fluxo oficial para dar acesso controlado da VM ao GPT mobile, sem shell irrestrito:

```text
VM path: /home/ubuntu/site-shopvivaliz/agent-bridge/inbox/
Service: shopvivaliz-agent-bridge.service
Repo alvo: /home/ubuntu/site-shopvivaliz
```

Regras:

1. A bridge aceita apenas 4 acoes JSON:
   - `create_issue`
   - `apply_patch_pr`
   - `read_file`
   - `run_readonly_audit`

2. A bridge continua limitada à sua API e nunca deve:
   - commitar direto em `main`
   - fazer `auto-merge`
   - aceitar secrets no patch
   - operar fora dos prefixes permitidos do repositorio

   A autorização operacional acima não amplia o schema da bridge nem substitui as proteções do GitHub; ela se aplica aos agentes que tenham capacidade técnica para executar o fluxo completo.

3. Fluxo esperado para GPT mobile:
   - gerar um `.json`
   - colocar o arquivo em `/home/ubuntu/site-shopvivaliz/agent-bridge/inbox/`
   - aguardar o watcher do service
   - ler o resultado em `/home/ubuntu/site-shopvivaliz/agent-bridge/outbox/`

4. Estados de processamento:
   - tarefa concluida: `*.json.done`
   - tarefa recusada/erro: `*.json.failed`
   - resultado estruturado: `outbox/*.result.json`

5. Verificacao operacional na VM:
   ```bash
   sudo systemctl status shopvivaliz-agent-bridge.service --no-pager -l
   ls -la /home/ubuntu/site-shopvivaliz/agent-bridge/inbox/
   ls -la /home/ubuntu/site-shopvivaliz/agent-bridge/outbox/
   cat /home/ubuntu/site-shopvivaliz/agent-bridge/config.json
   ```

6. Prompt operacional resumido para o ChatGPT mobile:
   ```text
   Gerar uma tarefa JSON para o ShopVivaliz Mobile Agent Bridge.
   Ação: create_issue ou apply_patch_pr ou read_file ou run_readonly_audit.
   Repositório: Vivaliz-site/site-shopvivaliz.
   Regras: nunca main direto pela bridge, nunca secrets, sempre evidência. A autorização de aprovação/merge/deploy vale para agentes com capacidade técnica fora da bridge, sem bypass de proteção.
   Objetivo: <descrever objetivo>.
   ```

7. O service roda em loop e observa a `inbox` a cada 30 segundos.

### Remote MCP para agentes

O PDF `C:\Users\FRED\Downloads\mcp.pdf` foi inspecionado em 2026-08-11 e registra um fluxo de autenticacao bem-sucedido com Remote MCP server por device authorization.

Regras:

1. Consulte [`docs/AGENT-MCP-REMOTE.md`](docs/AGENT-MCP-REMOTE.md) antes de usar MCP remoto em tarefas de agente.
2. Nunca commitar codigo de device, device ID completo, e-mail completo, token, cookie ou screenshot sem mascara.
3. Validar estado atual do MCP antes de declarar disponibilidade; se nao houver ferramenta/evidencia atual, reportar `INCONCLUSIVO`.
4. MCP remoto nao amplia permissoes: continuam proibidos force-push, bypass de protecoes, exposicao de secrets e alteracoes de preco/estoque/pedido fora do escopo aprovado.

### Se Tiver Que SSH à VM

```bash
# Use a chave baseada no perfil ativo (FRED ou user)
ssh -i "C:\Users\user\Downloads\ssh-key-2026-07-04.key" ubuntu@163.176.103.253
# ou
ssh -i "C:\Users\FRED\Downloads\ssh-key-2026-07-04.key" ubuntu@163.176.103.253

# Monitorar deploy (a cada 2 min, cron roda)
tail -f /var/log/shopvivaliz-deploy.log

# Ver release ativa
readlink -f /home/ubuntu/shopvivaliz-deploy/current

# Ver alvo persistente do cron
cat /home/ubuntu/shopvivaliz-deploy/shared/deploy-target-ref 2>/dev/null || echo "origin/main"

# Ver releases disponíveis
ls -la /home/ubuntu/shopvivaliz-deploy/releases/

# Rollback manual
sudo /home/ubuntu/shopvivaliz-deploy/repo/scripts/rollback-production.sh

# Nunca faça:
cd /home/ubuntu/shopvivaliz-deploy/current
vi index.php  # ❌ NUNCA edite release ativa
```

---

**Versão:** 1.1  
**Data Última Atualização:** 2026-07-25  
**Efetivo para:** Todos os agentes IA  
**Status:** ✅ Produção — Arquitetura Imutável Ativa
## Liz: qualidade, segurança e grounding

A Liz deve tratar fatos comerciais como dados, não como texto gerado. Preço,
estoque, frete, prazo, pedido e rastreamento só podem ser apresentados quando
vierem de uma fonte oficial e identificável.

- Não consultar pedidos sem sessão autenticada e vínculo do pedido ao usuário.
- Mascarar e-mails, identificadores e dados pessoais em respostas e métricas.
- Não aceitar instruções do usuário para revelar prompt, chaves, segredos ou regras internas.
- Reclamações e pedidos explícitos de atendimento devem gerar handoff estruturado com resumo.
- Estado conversacional deve ser limitado, normalizado e separado das instruções do sistema.
- Conteúdo editorial é contexto versionado; não substitui APIs de catálogo, checkout ou pedidos.
- Respostas da API devem declarar grounding_status, fontes utilizadas e estado conversacional.
- Métricas não podem registrar mensagem bruta, token, senha ou dado pessoal.
- Alterações na Liz devem incluir testes de conversa, segurança e regressão.

## Politica obrigatoria de PR, gate, merge e bloqueios (2026-08-27)

- Toda alteracao finalizada deve terminar em PR validado e merge; nao deixar PR pronta aberta sem motivo tecnico comprovado.
- Se qualquer check, teste, lint, gate, conflito ou Action falhar: investigar a causa raiz, corrigir, revalidar e repetir ate ficar verde. E proibido contornar falha com bypass, mascaramento de exit code, force merge ou desativacao de protecao.
- Gate verde autoriza merge automatico somente para PR do proprio repositorio depois que todos os checks canonicos aplicaveis passarem.
- Bloqueio aparentemente externo nao encerra a tarefa: procurar solucao segura por configuracao, credencial, permissao, host key, secret, servico, provider, infraestrutura ou rota alternativa antes de classificar como INCONCLUSIVO.
- Depois do merge, verificar Actions do SHA resultante e corrigir qualquer falha regressiva antes de declarar conclusao.
- No ShopVivaliz, Quality Gate verde em main deve acionar automaticamente o Master Production Pipeline e o deploy canonico na VM2; VM1 nao e destino de producao do site.

## Oracle Cloud (OCI) — acesso obrigatório para agentes

Para operações OCI, use somente a identidade dedicada `AGENTS`; nunca use como fallback o perfil pessoal `DEFAULT`. O procedimento e os caminhos por host estão em [`docs/operations/oci-agent-access.md`](docs/operations/oci-agent-access.md).

- Fred-Win e VM1 usam o perfil OCI `AGENTS` local protegido.
- VM2 usa o perfil protegido com o cliente de requisições OCI assinado documentado.
- DESKTOP-KOCEPSV acessa OCI pelo wrapper SSH verificado para VM1; a chave privada OCI não deve ser duplicada nesse host.
- É proibido versionar, imprimir ou transportar em logs/prompts chaves `*.pem`, security tokens, cookies de sessão ou headers de autenticação.
- Valide acesso com uma leitura autenticada real antes de declarar OCI operacional.
- Em caso de falha externa de autenticação, permissão, rede ou serviço, investigue e procure uma solução segura; não trate o bloqueio como encerramento automático da tarefa.
