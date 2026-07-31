# 🏗️ Arquitetura Híbrida de IA - Shop Vivaliz

**Status:** Fase 1-8 ✅ (Serviço Persistente Local Completo)
**Data:** 2026-07-17  
**Responsável:** Claude/Gemini Code Autonomous

---

## 📊 Configuração de Hardware Detectada

```
CPU:    Intel i7-8565U (4 cores / 8 threads @ 1.8-2.0 GHz)
RAM:    19.88 GB (limite: 8 GB para IA local)
GPU:    GeForce MX110 (2GB VRAM) ⚠️ MUITO LIMITADO
DISK:   81 GB livres em SSD
```

### ⚠️ Limitação Crítica
GPU com apenas 2GB VRAM restringe modelos. Solução:
- Usar quantização agressiva (Q2_K, Q3_K)
- Priorizar execução em CPU
- Fallback para GPT/Claude em tarefas complexas

---

## 🎯 Modelos Recomendados

### Primário: `qwen2.5-coder:1.5b`
- **Tamanho:** 1.5B parâmetros
- **VRAM:** 986 MB
- **Contexto:** 32K tokens
- **Especialização:** Código PHP, JS, Python
- **Velocidade:** Rápido em CPU / GPU
- **Português:** ✅ Suportado

### Fallback: `deepseek-coder:1.3b-instruct-q2_K`
- **Tamanho:** 1.3B parâmetros
- **VRAM:** 700 MB
- **Especialização:** Raciocínio em código
- **Quando usar:** Tarefas que Qwen não consegue

---

## 🏛️ Arquitetura de Agentes

### 1. Camada de Orquestração

```
┌──────────────────────────────────────────────────────┐
│           Orquestrador Central                       │
│  (roteamento, aprovações, logs, custo)              │
└──────────────┬───────────────────────────────────────┘
               │
       ┌───────┴────────┐
       │                │
    Local           Modelos Pagos
  (Ollama)       (GPT/Claude/Gemini)
```

### 2. Agentes Especializados

| Agente | Função | Modelo Preferido | Permissões |
|--------|--------|-----------------|------------|
| **Orquestrador** | Roteamento, fila, aprovações | Qwen ou GPT | Leitura total |
| **Backend PHP** | Correções/refactor PHP | Qwen primário | Edição restrita |
| **Frontend** | JS/TS/CSS/HTML | Qwen primário | Edição restrita |
| **Database** | SQL, migrações | Claude | Leitura, sem DDL destrutivo |
| **Testes** | Playwright, testes unitários | Qwen | Execução em sandbox |
| **DevOps** | Workflows, infra, Deploy | Claude | Aprovação obrigatória |
| **Segurança** | Auditoria, vulnerabilidades | Claude | Leitura-only |
| **ERP (Olist/Tiny)** | Sincronização de pedidos/produtos | Qwen | API calls apenas |
| **Pagamentos** | Mercado Pago, Pagar.me | Claude | Sem transações reais |
| **Revisor Código** | QA, lint, review | GPT | Leitura-only |
| **Auditor** | Logs, compliance, histórico | Qwen | Leitura-only |
| **Controlador Custo** | Monitoramento de gastos com APIs | Qwen | Leitura e bloqueio |

### 3. Fluxo de Decisão por Complexidade

```
Tarefa Recebida
    │
    ├─ Busca simples? → IA Local (instantâneo)
    │
    ├─ Edição localizada? → IA Local (rápido)
    │
    ├─ Teste de código? → IA Local (sandbox)
    │
    ├─ Arquitetura complexa? → GPT/Claude (pagamento)
    │
    ├─ Depuração difícil? → Claude (especialista)
    │
    ├─ Múltiplas imagens? → Gemini (visão)
    │
    └─ Risco alto? → HUMANO (aprovação)
```

---

## 💾 Sistema de Memória

### Estrutura de Camadas

```
┌─────────────────────────────────────────────┐
│ Memória de Curto Prazo (Redis)              │
│ • Conversa atual                            │
│ • Estado de tarefas                         │
│ • Variáveis de execução                     │
│ TTL: 1 hora                                 │
└─────────────────────────────────────────────┘

┌─────────────────────────────────────────────┐
│ Memória de Projeto (PostgreSQL + pgvector)  │
│ • Arquitetura do Shop Vivaliz               │
│ • Padrões de código                         │
│ • Decisões técnicas (com timestamps)        │
│ • Integrações API                           │
│ • Endpoints existentes                      │
│ TTL: Permanente (com versionamento)         │
└─────────────────────────────────────────────┘

┌─────────────────────────────────────────────┐
│ Memória de Agentes (PostgreSQL)             │
│ • Histórico de tarefas completadas          │
│ • Erros encontrados                         │
│ • Soluções que funcionaram                  │
│ • Casos de edge-case resolvidos             │
│ TTL: Permanente (para aprendizado)          │
└─────────────────────────────────────────────┘

┌─────────────────────────────────────────────┐
│ Memória de Incidentes (PostgreSQL)          │
│ • Produção: timestapm, causa, fix, impacto  │
│ • Alertas disparados                        │
│ • Padrões de falha                          │
│ TTL: Permanente (auditoria)                 │
└─────────────────────────────────────────────┘

┌─────────────────────────────────────────────┐
│ Busca Vetorial (Qdrant)                     │
│ • Embeddings de documentação                │
│ • Busca semântica por tarefa                │
│ • Recomendação de exemplos similares        │
│ TTL: Permanente                             │
└─────────────────────────────────────────────┘
```

---

## 💰 Controle de Custos

### Limites por Tarefa

```json
{
  "costs": {
    "daily_limit_usd": 10.0,
    "weekly_limit_usd": 50.0,
    "monthly_limit_usd": 200.0,
    "per_task_limit_usd": 2.0,
    "hard_blocks": [
      "production_deploy",
      "database_migration",
      "payment_modification"
    ]
  },
  "providers": {
    "openai": {
      "model": "gpt-4-turbo",
      "max_tokens_per_call": 8000,
      "cost_limit_per_call_usd": 0.50
    },
    "anthropic": {
      "model": "claude-opus-4",
      "max_tokens_per_call": 8000,
      "cost_limit_per_call_usd": 0.75
    },
    "google": {
      "model": "gemini-pro-vision",
      "max_tokens_per_call": 4000,
      "cost_limit_per_call_usd": 0.25
    }
  }
}
```

### Logging Obrigatório

Toda chamada a API paga deve registrar:
```json
{
  "timestamp": "2026-07-16T02:16:00Z",
  "task_id": "ARCH-001",
  "reason_for_escalation": "Complex dependency analysis required",
  "provider": "openai",
  "model": "gpt-4-turbo",
  "prompt_tokens": 2500,
  "completion_tokens": 800,
  "estimated_cost": 0.12,
  "actual_cost": 0.12,
  "result_quality": "high",
  "result_saved_to_memory": true,
  "approved_by": "human" | "auto"
}
```

---

## 🔐 Segurança e Sandbox

### Comandos Proibidos (sem exceção)

```
- git push --force
- git reset --hard
- rm -rf /
- truncate [critical_tables]
- UPDATE orders SET paid=true
- DELETE FROM users
- DROP TABLE
- ALTER DATABASE
- chmod 000
- Modificar DNS
- Alterar Cloudflare
- Desativar 2FA
```

### Sandbox para Testes

```
┌─────────────────────────────────────┐
│ Container Docker Isolado            │
│ • Cópia do repositório              │
│ • Banco de dados de teste           │
│ • Sem acesso à produção             │
│ • Sem acesso a credenciais reais    │
│ • Timeout: 5 minutos                │
└─────────────────────────────────────┘
```

### Aprovações Obrigatórias

- ✅ Edições não críticas: Auto
- ⏳ Edições críticas: Revisão de humano
- 🔴 Deploys: Sempre humano
- 🔴 Dados de clientes: Sempre humano
- 🔴 Pagamentos: Sempre humano

---

## 📈 Fases de Implementação

### Fase 1: Diagnóstico ✅
- [x] Detectar hardware
- [x] Validar ambiente
- [x] Inspecionar repositório

### Fase 2: IA Local ✅
- [x] Instalar Ollama
- [x] Baixar modelo Qwen
- [x] Testar velocidade/qualidade (Real Ollama HTTP API)
- [x] Benchmark vs GPT

### Fase 3: Memória 📋
- [ ] Instalar PostgreSQL + pgvector
- [ ] Indexar repositório
- [ ] Criar busca vetorial
- [ ] Implementar cache

### Fase 4: Ferramentas 📋
- [ ] Git wrapper
- [ ] Arquivo wrapper
- [ ] Terminal wrapper
- [ ] MCP tools

### Fase 5: Modelos Pagos ✅
- [x] Abstração de OpenAI/Anthropic/Google
- [x] Roteador de modelos (ModelRouter)
- [x] Bloqueio estrito para modo local-only

### Fase 6: Agentes ✅
- [x] Orquestrador central (Orchestrator)
- [x] Fila de tarefas persistente (`tasks.jsonl`)
- [x] Processamento em background resiliente

### Fase 7: Interface ✅
- [x] Dashboard web (servido na porta 3000)
- [x] API local de controle de tarefas (`server.js`)
- [x] Logs e status em tempo real

### Fase 8: Validação ✅
- [x] Teste end-to-end (`local-ai-test.ps1`)
- [x] Integração com tarefa agendada Windows
- [x] Documentação final e logs de auditoria

---

## 📦 Tecnologias Selecionadas

| Componente | Tecnologia | Razão |
|-----------|-----------|-------|
| IA Local | Ollama + Qwen | Sem instalação complexa, modelos otimizados |
| Memória Curto Prazo | Redis | Rápido, em memória, TTL automático |
| Memória Projeto | PostgreSQL + pgvector | Persistência, busca vetorial, ACID |
| Busca Vetorial | Qdrant | Especializado, rápido, container-ready |
| API Wrapper | LiteLLM | Abstração de múltiplos provedores |
| Orquestração | Celery + Redis | Fila, distribuição, retry automático |
| Observabilidade | OpenTelemetry + Prometheus | Padrão aberto, não vendor-lock |
| Dashboard | SvelteKit | Leve, reativo, sem dependências pesadas |
| Container | Docker Compose | Simplicidade, reprodutibilidade |
| Versionamento | Git + Temporal | Histórico, rollback, auditoria |

---

## 🚀 Próximos Passos

1. **Instalar Ollama** (manual, GUI)
2. **Começar Fase 3** com PostgreSQL + Qdrant
3. **Implementar roteador de modelos**
4. **Criar agentes centrais**
5. **Dashboard de monitoramento**

---

**Documento gerado por:** Claude Code Autonomous  
**Última atualização:** 2026-07-16 02:30:00 UTC  
**Versão:** 0.1 (Draft)
