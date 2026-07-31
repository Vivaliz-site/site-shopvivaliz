# 🧠 Hybrid AI System - Shop Vivaliz

Sistema híbrido que combina **IA local (Ollama)** com **modelos pagos (GPT, Claude, Gemini)** para automação inteligente do Shop Vivaliz.

## 📊 Arquitetura

```
┌──────────────────────────────────────────────────────┐
│           Orquestrador Central                       │
│  • Fila de tarefas                                   │
│  • Roteamento inteligente                            │
│  • Controle de aprovações                            │
│  • Monitoramento de custos                           │
└──────────────┬───────────────────────────────────────┘
               │
       ┌───────┴────────┐
       │                │
    Ollama          APIs Pagas
  (IA Local)      (GPT/Claude/Gemini)
       │                │
  • Qwen 1.5B      • OpenAI GPT-4
  • DeepSeek 1.3B  • Anthropic Claude
  • Instant 0 custo • Google Gemini
  • Sem API calls   • Precisão máxima
```

## 🚀 Quick Start

### 1. Instalar Dependências

```bash
cd C:\site-shopvivaliz\.ai
npm install
```

### 2. Instalar Ollama (IA Local)

**Windows:**
- Baixar: https://ollama.ai/download
- Executar instalador
- Verificar: `ollama --version`

**Puxar modelo recomendado:**
```bash
ollama pull qwen2.5-coder:1.5b
ollama serve
```

### 3. Configurar APIs (Opcional)

Criar `.env` na raiz do projeto:

```env
OPENAI_API_KEY=sk-...
ANTHROPIC_API_KEY=sk-ant-...
GOOGLE_API_KEY=...
```

### 4. Executar

```bash
# Teste simples
npm start

# Modo desenvolvimento (hot reload)
npm run dev
```

## 📋 Como Funciona

### 1. Submeter Tarefa

```javascript
const orchestrator = new Orchestrator();

const task_id = orchestrator.submit({
  description: 'Otimizar query de produtos',
  type: 'fix',
  languages: ['php', 'sql'],
  priority: 'high'
});
```

### 2. Sistema Decide

**Análise:**
- Complexidade
- Tipo de tarefa
- Necessidades
- Custo

**Roteamento:**
- Simples? → IA Local (instantâneo, grátis)
- Médio? → IA Local com fallback
- Complexo? → GPT/Claude (preciso, pago)
- Crítico? → Requer aprovação humana

### 3. Execução

```
Tarefa → Análise → Roteamento → Custo? → Aprovação? → Execução → Log
```

### 4. Resultado

Cada execução retorna:
```json
{
  "task_id": "...",
  "status": "completed",
  "model": "qwen2.5-coder:1.5b",
  "provider": "ollama",
  "cost": 0.00,
  "execution_time_ms": 1234,
  "result": "..."
}
```

## 💰 Controle de Custos

Limites automáticos:
- **Diário:** $10
- **Semanal:** $50
- **Mensal:** $200
- **Por tarefa:** $2

```javascript
const status = orchestrator.getStatus();
console.log(status.cost_report);
// {
//   daily_used: 1.25,
//   daily_limit: 10.00,
//   total_saved_by_local: 15.60
// }
```

## 📊 Modelos Disponíveis

### IA Local (Grátis)

| Modelo | Parâmetros | VRAM | CPU | Contexto | Especialização |
|--------|-----------|------|-----|---------|-----------------|
| **qwen2.5-coder** | 1.5B | 986MB | 2GB | 32K | Código (recomendado) |
| deepseek-coder | 1.3B | 700MB | 1.8GB | 4K | Raciocínio em código |
| phi | 2.7B | 1GB | 2.3GB | 2K | Chat leve |

### APIs Pagas

| Modelo | Custo/1k tokens | Contexto | Especialização |
|--------|-----------------|---------|-----------------|
| gpt-4-turbo | $0.03/$0.06 | 128K | Genérico, versátil |
| claude-opus-4 | $0.075/$0.24 | 100K | Código, raciocínio |
| gemini-pro | $0.01/$0.02 | 30K | Visão, multimodal |

## 🔐 Segurança

**Bloqueios automáticos:**
- Deploy produção → Requer humano
- Transações financeiras → Requer humano
- Dados de clientes → Requer humano
- Comandos destrutivos → Proibido sempre

**Sandbox:**
- Testes em container isolado
- Sem acesso a produção
- Timeout automático
- Rollback disponível

## 📈 Monitoramento

```javascript
const status = orchestrator.getStatus();
console.log({
  queue_size: status.queue_size,
  executing: status.executing,
  approvals_pending: status.approvals_pending,
  total_processed: status.total_tasks_processed,
  errors: status.total_errors,
  cost_report: status.cost_report
});
```

## 🛠️ Estrutura de Arquivos

```
.ai/
├── orchestrator.js        # Orquestrador central
├── model-router.js        # Roteador de modelos
├── llm-provider.js        # Abstração de APIs
├── main.js               # Exemplo de uso
├── package.json
├── README.md
├── .env.example
└── tests/
    ├── test-router.js
    ├── test-orchestrator.js
    └── test-integration.js
```

## 📝 Exemplos de Uso

### Busca Simples

```javascript
orchestrator.submit({
  description: 'Encontrar definição de função em PHP',
  type: 'search',
  languages: ['php'],
  priority: 'low'
});
// → IA Local (instantâneo, grátis)
```

### Refatoração Complexa

```javascript
orchestrator.submit({
  description: 'Refatorar sistema de pagamento para ter retry exponencial',
  type: 'architecture',
  languages: ['php', 'javascript'],
  needs_deep_reasoning: true,
  priority: 'high'
});
// → GPT/Claude (pago, preciso)
```

### Teste Crítico

```javascript
orchestrator.submit({
  description: 'Deploy em produção do novo checkout',
  type: 'deploy',
  priority: 'critical'
});
// → Bloqueado: Requer aprovação humana
```

## 🚨 Troubleshooting

### Ollama não conecta

```bash
# Verificar se está rodando
ollama list

# Restartá-lo
ollama serve
```

### Erro "Cannot write headers after they are sent"

Problema corrigido! Veja HYBRID_AI_ARCHITECTURE.md para detalhes.

### Custo alto

Verificar cost report e ajustar `approval_required_for_cost_above`.

## 📞 Suporte

Verifique: `HYBRID_AI_ARCHITECTURE.md` para arquitetura completa

## 📄 Licença

MIT

---

**Status:** ✅ Fase 1-8 Completo  
**Próximos:** Manutenção e Expansão de Features  
**Último Update:** 2026-07-17
