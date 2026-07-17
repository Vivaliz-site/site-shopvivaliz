/**
 * 🤖 Agentes Especializados - Fase 6
 *
 * 10+ agentes especializados, cada um com:
 * - Objetivo específico
 * - Ferramentas permitidas
 * - Limites de custo
 * - Critérios de escalação
 *
 * Arquivo: .ai/agents.js
 */

const { UnifiedLLM } = require('./llm-provider');

class Agent {
  constructor(config) {
    this.name = config.name;
    this.role = config.role;
    this.objective = config.objective;
    this.allowed_tools = config.allowed_tools || [];
    this.forbidden_tools = config.forbidden_tools || [];
    this.preferred_model = config.preferred_model || 'ollama:qwen2.5-coder:1.5b';
    this.fallback_model = config.fallback_model || 'openai:gpt-4-turbo';
    this.cost_limit = config.cost_limit || 0.50;
    this.max_retries = config.max_retries || 2;
    this.timeout_ms = config.timeout_ms || 30000;
    this.memory = [];
    this.stats = {
      tasks_completed: 0,
      tasks_failed: 0,
      total_cost: 0
    };
  }

  async execute(task) {
    console.log(`[${this.name}] Iniciando tarefa: ${task.description.substring(0, 50)}...`);

    // Verificar se é autorizado
    for (const forbidden of this.forbidden_tools) {
      if (task.required_tools && task.required_tools.includes(forbidden)) {
        return {
          success: false,
          error: `Ferramenta proibida: ${forbidden}`,
          agent: this.name
        };
      }
    }

    // Executar com timeout
    return new Promise((resolve) => {
      const timeout = setTimeout(() => {
        this.stats.tasks_failed++;
        resolve({
          success: false,
          error: `Timeout após ${this.timeout_ms}ms`,
          agent: this.name
        });
      }, this.timeout_ms);

      this._run(task).then((result) => {
        clearTimeout(timeout);
        resolve(result);
      }).catch((error) => {
        clearTimeout(timeout);
        this.stats.tasks_failed++;
        resolve({
          success: false,
          error: error.message,
          agent: this.name
        });
      });
    });
  }

  async _run(task) {
    // Simular processamento (será substituído por chamada real de LLM)
    this.stats.tasks_completed++;
    this.stats.total_cost += 0.001; // Mínimo

    return {
      success: true,
      result: `${this.name} processou: ${task.description}`,
      agent: this.name,
      model_used: this.preferred_model,
      cost: 0.001
    };
  }

  getStats() {
    return {
      name: this.name,
      role: this.role,
      ...this.stats
    };
  }
}

// Definir agentes
const AGENTS = {
  // ========== CORE ==========
  orchestrator: new Agent({
    name: '🎭 Orquestrador',
    role: 'Roteamento e supervisão',
    objective: 'Direcionar tarefas ao agente correto',
    allowed_tools: ['git', 'logs', 'memory'],
    preferred_model: 'openai:gpt-4-turbo',
    cost_limit: 1.0
  }),

  // ========== DESENVOLVIMENTO ==========
  backend: new Agent({
    name: '⚙️ Backend PHP',
    role: 'Correções PHP/SQL',
    objective: 'Editar, debugar, refatorar código PHP',
    allowed_tools: ['git', 'file_read', 'file_write', 'test'],
    forbidden_tools: ['deploy', 'delete_production'],
    preferred_model: 'ollama:qwen2.5-coder:1.5b',
    fallback_model: 'anthropic:claude-opus-4',
    cost_limit: 0.5
  }),

  frontend: new Agent({
    name: '🎨 Frontend',
    role: 'JS/TS/CSS/HTML',
    objective: 'Editar componentes frontend',
    allowed_tools: ['git', 'file_read', 'file_write', 'test', 'playwright'],
    forbidden_tools: ['deploy', 'delete_production'],
    preferred_model: 'ollama:qwen2.5-coder:1.5b',
    fallback_model: 'openai:gpt-4-turbo',
    cost_limit: 0.4
  }),

  database: new Agent({
    name: '🗄️ Database',
    role: 'SQL/Migrações',
    objective: 'Análise e design de banco de dados',
    allowed_tools: ['git', 'file_read', 'logs'],
    forbidden_tools: ['file_write', 'execute_sql', 'delete_data'],
    preferred_model: 'ollama:qwen2.5-coder:1.5b',
    fallback_model: 'anthropic:claude-opus-4',
    cost_limit: 0.5
  }),

  // ========== QUALIDADE ==========
  tester: new Agent({
    name: '✅ Tester',
    role: 'Playwright/Testes Unitários',
    objective: 'Criar e executar testes',
    allowed_tools: ['git', 'file_read', 'file_write', 'playwright', 'terminal'],
    forbidden_tools: ['deploy', 'delete_files'],
    preferred_model: 'ollama:qwen2.5-coder:1.5b',
    cost_limit: 0.3
  }),

  security: new Agent({
    name: '🔒 Security',
    role: 'Auditoria de segurança',
    objective: 'Encontrar vulnerabilidades',
    allowed_tools: ['git', 'file_read', 'logs', 'memory'],
    forbidden_tools: ['file_write', 'deploy', 'delete_files'],
    preferred_model: 'anthropic:claude-opus-4',
    fallback_model: 'openai:gpt-4-turbo',
    cost_limit: 0.75
  }),

  // ========== OPERAÇÕES ==========
  devops: new Agent({
    name: '🔧 DevOps',
    role: 'Workflows/Infra/Deploy',
    objective: 'Gerenciar CI/CD e infraestrutura',
    allowed_tools: ['git', 'file_read', 'logs', 'memory'],
    forbidden_tools: ['file_write', 'deploy_production', 'delete_infra'],
    preferred_model: 'anthropic:claude-opus-4',
    cost_limit: 1.0
  }),

  // ========== INTEGRAÇÕES ==========
  olist_agent: new Agent({
    name: '📦 Olist/ERP',
    role: 'Sincronização de pedidos/produtos',
    objective: 'Integrar com Olist e Tiny ERP',
    allowed_tools: ['git', 'file_read', 'api_call', 'logs'],
    forbidden_tools: ['file_write', 'delete_data', 'modify_orders'],
    preferred_model: 'ollama:qwen2.5-coder:1.5b',
    cost_limit: 0.4
  }),

  payments: new Agent({
    name: '💳 Pagamentos',
    role: 'Mercado Pago, Pagar.me',
    objective: 'Integração com gateways de pagamento',
    allowed_tools: ['git', 'file_read', 'api_call', 'logs'],
    forbidden_tools: ['file_write', 'execute_payment', 'modify_balance'],
    preferred_model: 'anthropic:claude-opus-4',
    cost_limit: 0.8
  }),

  // ========== OBSERVABILIDADE ==========
  auditor: new Agent({
    name: '📊 Auditor',
    role: 'Logs e histórico',
    objective: 'Monitorar logs e gerar relatórios',
    allowed_tools: ['logs', 'memory', 'file_read'],
    forbidden_tools: ['file_write', 'delete_logs', 'modify_data'],
    preferred_model: 'ollama:qwen2.5-coder:1.5b',
    cost_limit: 0.2
  }),

  cost_controller: new Agent({
    name: '💰 Controlador de Custo',
    role: 'Monitorar gastos com APIs',
    objective: 'Garantir que custos estejam dentro do limite',
    allowed_tools: ['memory', 'logs'],
    forbidden_tools: ['file_write', 'execute', 'delete_data'],
    preferred_model: 'ollama:qwen2.5-coder:1.5b',
    cost_limit: 0.1
  }),

  code_reviewer: new Agent({
    name: '👀 Code Reviewer',
    role: 'QA e revisão de código',
    objective: 'Revisar código antes de merge',
    allowed_tools: ['git', 'file_read', 'logs'],
    forbidden_tools: ['file_write', 'deploy', 'delete_files'],
    preferred_model: 'openai:gpt-4-turbo',
    fallback_model: 'anthropic:claude-opus-4',
    cost_limit: 0.6
  })
};

/**
 * Gerenciador de agentes
 */
class AgentManager {
  constructor() {
    this.agents = AGENTS;
    this.execution_log = [];
  }

  /**
   * Obter agente para tarefa
   */
  selectAgent(task) {
    // Lógica de seleção baseada no tipo de tarefa
    const task_type = task.type || '';

    const mappings = {
      'fix': this.agents.backend,
      'edit_backend': this.agents.backend,
      'edit_frontend': this.agents.frontend,
      'database': this.agents.database,
      'test': this.agents.tester,
      'security': this.agents.security,
      'deploy': this.agents.devops,
      'olist': this.agents.olist_agent,
      'payment': this.agents.payments,
      'audit': this.agents.auditor,
      'review': this.agents.code_reviewer,
      'cost': this.agents.cost_controller
    };

    return mappings[task_type] || this.agents.orchestrator;
  }

  /**
   * Executar tarefa com agente apropriado
   */
  async execute(task) {
    const agent = this.selectAgent(task);
    const result = await agent.execute(task);

    this.execution_log.push({
      timestamp: new Date().toISOString(),
      task_id: task.id,
      agent: agent.name,
      ...result
    });

    return result;
  }

  /**
   * Status de todos os agentes
   */
  getAgentsStatus() {
    const status = {};
    for (const [key, agent] of Object.entries(this.agents)) {
      status[key] = agent.getStats();
    }
    return status;
  }

  /**
   * Histórico de execução
   */
  getExecutionLog(limit = 100) {
    return this.execution_log.slice(-limit).reverse();
  }

  /**
   * Total de custo de todos os agentes
   */
  getTotalCost() {
    let total = 0;
    for (const agent of Object.values(this.agents)) {
      total += agent.stats.total_cost;
    }
    return total;
  }
}

module.exports = { Agent, AgentManager, AGENTS };
