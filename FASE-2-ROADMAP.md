# 🚀 Fase 2-8: Roadmap Completo de Implementação

## Status Atual (2026-07-16 03:45 UTC)

```
✅ Fase 1: Diagnóstico Completo
   ├─ Hardware: i7-8565U, 19.88 GB RAM, MX110 2GB VRAM
   ├─ Ambiente: Python 3.12.10, Node.js 24.17.0, Git 2.55.0
   ├─ Repo: 90 workflows (REDUNDANTES), 200+ branches, 6 tarefas pendentes
   ├─ Agentes: Estrutura parcial em .ai/agents.js
   └─ Riscos: Ollama não instalado, GPU limitada, workflows desincronizados

⏳ Fase 2: IA Local (VOCÊ AQUI)
   ├─ [ ] Instalar Ollama para Windows
   ├─ [ ] Download de mistral:7b-q4_K_M (~5 GB)
   ├─ [ ] Testar servidor localhost:11434
   ├─ [ ] Validar geração de código
   └─ Bloqueador: Requer ação manual (instalar Ollama)

⏭️ Fase 2.5: Integração Roteador
   ├─ [x] Criar llm-provider-unified.js
   ├─ [ ] Testar roteamento local → Claude
   ├─ [ ] Validar orçamento diário
   └─ Status: Aguardando Ollama em funcionamento

⏭️ Fase 3: Sistema de Memória
   ├─ [ ] Indexar repositório (busca semântica)
   ├─ [ ] Criar índice vetorial (Qdrant ou SQLite + LSH)
   ├─ [ ] Persistir contexto de agentes
   └─ Tempo estimado: 3-4 horas

⏭️ Fase 4: Ferramentas e Git
   ├─ [ ] Integração Git (criar branches, commits)
   ├─ [ ] Execução de testes (pytest, npm test)
   ├─ [ ] Execução de Playwright (E2E)
   ├─ [ ] Análise de logs
   └─ Tempo estimado: 4-5 horas

⏭️ Fase 5: Modelos Pagos (Fallback)
   ├─ [ ] Integração OpenAI (GPT-4)
   ├─ [ ] Integração Anthropic (Claude Opus)
   ├─ [ ] Integração Google (Gemini)
   ├─ [ ] Cache de respostas (reduzir custo)
   └─ Tempo estimado: 2-3 horas

⏭️ Fase 6: Agentes Especializados
   ├─ [ ] Orquestrador (coordenador)
   ├─ [ ] Backend PHP (integrações Tiny/Olist)
   ├─ [ ] Frontend (React/Vue)
   ├─ [ ] DevOps (deploy, infra)
   ├─ [ ] Segurança (OWASP, credenciais)
   ├─ [ ] Testes (Playwright, unit)
   ├─ [ ] SEO/Marketing
   ├─ [ ] Marketplace (Shopee, ML, Amazon)
   └─ Tempo estimado: 8-10 horas

⏭️ Fase 7: Interface de Operações
   ├─ [ ] Painel web (React)
   ├─ [ ] WebSocket para atualizações ao vivo
   ├─ [ ] Gráficos de consumo de tokens
   ├─ [ ] Fila de tarefas
   ├─ [ ] Logs estruturados
   ├─ [ ] Aprovações pendentes
   └─ Tempo estimado: 4-5 horas

⏭️ Fase 8: Validação E2E
   ├─ [ ] Tarefa real em branch de teste
   ├─ [ ] Comparar Ollama vs Claude
   ├─ [ ] Testar escalação automática
   ├─ [ ] Validar rollback
   ├─ [ ] Testar limites de segurança
   └─ Tempo estimado: 3-4 horas
```

---

## Próximas Ações Imediatas

### 1. **AGORA: Instalar Ollama** (manual, 10-15 min)

Abra PowerShell com admin e execute:

```powershell
# Via Windows Package Manager
winget install Ollama.Ollama --accept-source-agreements --accept-package-agreements

# Ou baixe manualmente:
# https://ollama.ai/download/windows
```

Após instalar:

```powershell
# Terminal 1: Servidor
ollama serve

# Terminal 2: Download do modelo
ollama pull mistral:7b-q4_K_M

# Terminal 3: Teste
ollama run mistral:7b-q4_K_M "Hello"
```

### 2. **QUANDO OLLAMA ESTIVER RODANDO**

Avise executando:

```powershell
Write-Host "✅ Ollama rodando em localhost:11434" -ForegroundColor Green
ollama list
```

Então continuaremos com:

```
Fase 2.5 → Testar roteador
Fase 3   → Memória e indexação
...
```

---

## Consolidação de Workflows (problema crítico)

Temos **90 workflows** que fazem coisas sobrepostas.  
Consolidaremos para **~10** workflows essenciais:

### Workflows Finais Recomendados

```
1. qa-lint.yml
   └─ On: push, PR
   └─ Ação: Lint PHP, bloqueia CSS wildcard

2. auto-validation.yml
   └─ Schedule: */30 * * * * (a cada 30 min)
   └─ Ação: Detecta issues, auto-commit de fixes

3. integration-tests.yml
   └─ On: push, PR
   └─ Ação: Executa testes (npm test, pytest)

4. e2e-tests.yml
   └─ On: push, PR
   └─ Ação: Playwright, validação live

5. deploy-oracle.yml
   └─ On: push main
   └─ Ação: Notifica VM Oracle para sync (já roda git-auto-sync.py)

6. marketplace-sync.yml
   └─ Schedule: */30 * * * * (a cada 30 min)
   └─ Ação: Shopee, ML, Amazon, Olist sync

7. security-scan.yml
   └─ Schedule: 0 2 * * * (noites)
   └─ Ação: SAST, dependency check, credentials scan

8. task-executor.yml
   └─ Schedule: */30 * * * * (a cada 30 min)
   └─ Ação: Processa tasks-queue.json

9. monitor-health.yml
   └─ Schedule: */10 * * * * (a cada 10 min)
   └─ Ação: Health check endpoints, alertas

10. report-daily.yml
    └─ Schedule: 0 6 * * * (6 da manhã)
    └─ Ação: Gera relatório de atividades
```

### Consolidação de 90 → 10

**Ação para Fase 4:**
1. Identificar workflows ativos vs órfãos
2. Mesclar em 10 essenciais
3. Adicionar **lock centralizado** (Redis/filesystem)
4. Testar em staging antes de aplicar em main

---

## Arquitetura Proposta (Final)

```
┌─────────────────────────────────────────────────────┐
│   IA Local (24/7)                                   │
│   ├─ Ollama (mistral 7B) — conversas, análise      │
│   ├─ Scripts Python — testes, logs, ERP            │
│   ├─ Scripts Bash — Git, terminal                   │
│   └─ Roteador → Claude/GPT se complexo             │
└──────────────┬──────────────────────────────────────┘
               │
        ┌──────▼──────────────────────────────┐
        │  Agentes Especializados              │
        │  (10 agentes, cada um com escopo)   │
        │  ├─ Orquestrador                     │
        │  ├─ Backend PHP                      │
        │  ├─ Frontend React                   │
        │  ├─ DevOps                           │
        │  ├─ Testes/Playwright                │
        │  ├─ Marketplace                      │
        │  ├─ SEO                              │
        │  ├─ Segurança                        │
        │  ├─ ERP (Tiny/Olist)                 │
        │  └─ Auditor                          │
        └──────┬──────────────────────────────┘
               │
        ┌──────▼──────────────────────────────┐
        │  Memória (vetorial)                  │
        │  ├─ Índice semântico (Qdrant)       │
        │  ├─ Base de conhecimento do projeto │
        │  ├─ Histórico de decisões           │
        │  └─ Bugs resolvidos                  │
        └──────┬──────────────────────────────┘
               │
        ┌──────▼──────────────────────────────┐
        │  Fila de Tarefas                     │
        │  ├─ tasks-queue.json                 │
        │  ├─ Executor a cada 30min           │
        │  ├─ Lock centralizado                │
        │  └─ Retry automático                 │
        └──────┬──────────────────────────────┘
               │
        ┌──────▼──────────────────────────────┐
        │  GitHub (repositório)                │
        │  ├─ 10 workflows consolidados        │
        │  ├─ Proteções de branch              │
        │  ├─ Secrets seguros                  │
        │  └─ Deploy automático                │
        └──────┬──────────────────────────────┘
               │
        ┌──────▼──────────────────────────────┐
        │  Produção (VM Oracle)                │
        │  ├─ Apache + PHP                     │
        │  ├─ Git sync a cada 30min           │
        │  ├─ Monitoring                       │
        │  └─ Rollback automático              │
        └──────────────────────────────────────┘
```

---

## Tarefas por Fase (Breakdown)

### Fase 2 (IA Local) — 2-4 horas
- [x] Diagnóstico
- [ ] Instalar Ollama
- [ ] Download modelo
- [ ] Testar servidor
- [ ] Medir RAM/VRAM

### Fase 2.5 (Roteador) — 2-3 horas
- [x] Criar llm-provider-unified.js
- [ ] Integrar com agents.js
- [ ] Testar fallback
- [ ] Logging de tokens
- [ ] Validar orçamento

### Fase 3 (Memória) — 4-5 horas
- [ ] Escolher storage (Qdrant vs SQLite)
- [ ] Indexar repo (embeddings)
- [ ] API de busca
- [ ] Teste de recall
- [ ] Persistência

### Fase 4 (Ferramentas) — 5-6 horas
- [ ] Git (branch, commit, push)
- [ ] Terminal (Bash/PS)
- [ ] Tests (npm/pytest)
- [ ] Playwright (E2E)
- [ ] Logs (tail, grep)
- [ ] MCP bridge

### Fase 5 (Fallback Pago) — 2-3 horas
- [ ] API Claude
- [ ] API GPT-4
- [ ] API Gemini (opcional)
- [ ] Cache de respostas
- [ ] Preço estimado

### Fase 6 (Agentes) — 8-10 horas
- [ ] Reescrever agents.js com padrão modular
- [ ] 10 agentes especializados
- [ ] Comunicação entre agentes
- [ ] Escalação automática
- [ ] Logs e métricas

### Fase 7 (Interface) — 4-5 horas
- [ ] React dashboard
- [ ] WebSocket real-time
- [ ] Gráficos
- [ ] Aprovações manuais
- [ ] Deploy de teste

### Fase 8 (Validação) — 3-4 horas
- [ ] Tarefa real (não-crítica)
- [ ] Comparar modelos
- [ ] Validar rollback
- [ ] Security review
- [ ] Performance test

---

## Timeline Total

```
Fase 1:  ✅ Concluída
Fase 2:  1-2 dias (manual)
Fase 2.5: 1 dia
Fase 3:  1-2 dias
Fase 4:  1-2 dias
Fase 5:  0.5-1 dia
Fase 6:  2-3 dias
Fase 7:  1 dia
Fase 8:  0.5-1 dia

Total: ~10-15 dias até sistema completo funcional
```

---

## Próximos Passos

1. **AGORA:** Instale Ollama
2. **QUANDO RODANDO:** Confirme que ollama serve está no port 11434
3. **DEPOIS:** Executaremos testes do roteador
4. **ENTÃO:** Continuaremos com Fase 3

Salve este documento em:  
`C:\Users\FRED\.claude\projects\C--site-shopvivaliz\FASE-2-ROADMAP.md`

---

**Status:** Fase 2 aguardando instalação manual  
**Bloqueador:** Ollama não instalado  
**Ação:** Execute `winget install Ollama.Ollama` em PowerShell admin  
**Tempo:** ~15 minutos
