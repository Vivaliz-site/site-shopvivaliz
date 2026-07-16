/**
 * 🎭 Orchestrator - Núcleo de Execução de Tarefas
 *
 * Gerencia: fila, roteamento, execução, aprovações, logs
 *
 * Arquivo: .ai/orchestrator.js
 */

const { v4: uuidv4 } = require('uuid');
const { ModelRouter, TaskAnalyzer } = require('./model-router');

class TaskQueue {
  constructor() {
    this.tasks = new Map();
    this.queue = [];
  }

  add(task) {
    const id = uuidv4();
    const fullTask = {
      id,
      created_at: new Date().toISOString(),
      status: 'pending',
      ...task
    };
    this.tasks.set(id, fullTask);
    this.queue.push(id);
    return id;
  }

  get(id) {
    return this.tasks.get(id);
  }

  update(id, updates) {
    const task = this.tasks.get(id);
    if (task) {
      Object.assign(task, updates);
      task.updated_at = new Date().toISOString();
    }
    return task;
  }

  next() {
    while (this.queue.length > 0) {
      const id = this.queue[0];
      const task = this.tasks.get(id);
      if (task && task.status === 'pending') {
        return task;
      }
      this.queue.shift();
    }
    return null;
  }

  size() {
    return this.queue.length;
  }
}

class Orchestrator {
  constructor(config = {}) {
    this.config = {
      max_concurrent_tasks: 2,
      approval_required_for_cost_above: 0.50,
      approval_timeout_ms: 3600000, // 1 hora
      ...config
    };

    this.router = new ModelRouter(config);
    this.queue = new TaskQueue();
    this.executing_tasks = new Map();
    this.approvals_pending = new Map();
    this.execution_log = [];
    this.error_log = [];
  }

  /**
   * Submeter uma tarefa
   */
  submit(task) {
    // Validar entrada
    if (!task.description || !task.type) {
      throw new Error('Task must have description and type');
    }

    // Gerar ID
    const id = this.queue.add({
      description: task.description,
      type: task.type,
      context: task.context || '',
      languages: task.languages || [],
      priority: task.priority || 'normal',
      needs_vision: task.needs_vision || false,
      needs_web_search: task.needs_web_search || false,
      needs_deep_reasoning: task.needs_deep_reasoning || false,
      requested_by: task.requested_by || 'system',
      deadline: task.deadline || null
    });

    console.log(`[ORCHESTRATOR] Task ${id} submitted`);
    return id;
  }

  /**
   * Processar próxima tarefa da fila
   */
  async process() {
    // Verificar se há espaço
    if (this.executing_tasks.size >= this.config.max_concurrent_tasks) {
      return null;
    }

    // Obter próxima tarefa
    const task = this.queue.next();
    if (!task) {
      return null;
    }

    try {
      // Fase 1: Análise
      const analysis = TaskAnalyzer.analyze(task);
      this.queue.update(task.id, { analysis });

      console.log(`[ORCHESTRATOR] Task ${task.id} analyzed (score: ${analysis.complexity_score})`);

      // Fase 2: Roteamento
      const route = this.router.route(task);
      this.queue.update(task.id, { route });

      console.log(`[ORCHESTRATOR] Task ${task.id} routed to: ${route.decision}`);

      // Fase 3: Validação de custo e aprovações
      if (route.provider && route.provider !== 'ollama') {
        const estimated_cost = route.estimated_cost || 0;

        // Checar limites
        const cost_check = this.router.canExecute(task, estimated_cost);
        if (!cost_check.allowed) {
          return this._reject(task.id, cost_check.reason);
        }

        // Solicitar aprovação se custo alto
        if (parseFloat(estimated_cost) > this.config.approval_required_for_cost_above) {
          return this._request_approval(task.id, route, estimated_cost);
        }
      }

      // Fase 4: Executar
      return this._execute(task, route);

    } catch (error) {
      return this._error(task.id, error.message);
    }
  }

  /**
   * Solicitar aprovação humana
   */
  _request_approval(task_id, route, cost) {
    const task = this.queue.get(task_id);
    const approval_id = uuidv4();

    const approval = {
      id: approval_id,
      task_id,
      route,
      estimated_cost: cost,
      requested_at: new Date().toISOString(),
      timeout_at: new Date(Date.now() + this.config.approval_timeout_ms).toISOString(),
      status: 'pending'
    };

    this.approvals_pending.set(approval_id, approval);
    this.queue.update(task_id, { status: 'awaiting_approval', approval_id });

    console.log(`[ORCHESTRATOR] Task ${task_id} awaiting approval (cost: ${cost})`);

    return {
      type: 'AWAITING_APPROVAL',
      approval_id,
      details: {
        task_description: task.description,
        model: route.model,
        provider: route.provider,
        estimated_cost: cost,
        reason: route.reason
      }
    };
  }

  /**
   * Aprovar uma tarefa
   */
  approve(approval_id, approved_by) {
    const approval = this.approvals_pending.get(approval_id);
    if (!approval) {
      throw new Error(`Approval ${approval_id} not found`);
    }

    approval.status = 'approved';
    approval.approved_by = approved_by;
    approval.approved_at = new Date().toISOString();

    const task = this.queue.get(approval.task_id);
    this.queue.update(task.id, { status: 'approved' });

    console.log(`[ORCHESTRATOR] Task ${task.id} approved by ${approved_by}`);

    // Retornar para processamento
    return this.process();
  }

  /**
   * Rejeitar uma tarefa
   */
  _reject(task_id, reason) {
    const task = this.queue.update(task_id, {
      status: 'rejected',
      rejection_reason: reason
    });

    console.log(`[ORCHESTRATOR] Task ${task_id} rejected: ${reason}`);

    return {
      type: 'REJECTED',
      reason,
      task_id
    };
  }

  /**
   * Executar uma tarefa
   */
  async _execute(task, route) {
    this.executing_tasks.set(task.id, {
      started_at: new Date(),
      route,
      status: 'executing'
    });

    this.queue.update(task.id, { status: 'executing' });

    console.log(`[ORCHESTRATOR] Executing task ${task.id} with ${route.model}`);

    // Simular execução (será substituído por chamada real)
    const result = await this._simulateExecution(task, route);

    // Registrar resultado
    const log_entry = {
      task_id: task.id,
      executed_at: new Date().toISOString(),
      provider: route.provider,
      model: route.model,
      estimated_cost: route.estimated_cost,
      actual_cost: result.actual_cost,
      success: result.success,
      result: result.success ? result.output : result.error,
      execution_time_ms: result.execution_time_ms
    };

    this.execution_log.push(log_entry);

    // Atualizar custo
    if (route.provider && route.provider !== 'ollama') {
      this.router.logCostUsage(
        task.id,
        route.provider,
        route.model,
        route.estimated_cost,
        result.actual_cost,
        result.success ? 'high' : 'failed'
      );
    }

    // Atualizar status
    this.queue.update(task.id, {
      status: result.success ? 'completed' : 'failed',
      result: log_entry
    });

    this.executing_tasks.delete(task.id);

    console.log(`[ORCHESTRATOR] Task ${task.id} ${result.success ? 'completed' : 'failed'}`);

    return {
      type: 'EXECUTION_COMPLETE',
      task_id: task.id,
      success: result.success,
      ...log_entry
    };
  }

  /**
   * Simular execução (placeholder)
   */
  async _simulateExecution(task, route) {
    const start = Date.now();

    // Simular delay
    const delay = route.provider === 'ollama' ? 1000 : 3000;
    await new Promise(r => setTimeout(r, delay));

    const execution_time_ms = Date.now() - start;

    // Simular resultado (sempre sucesso por agora)
    return {
      success: true,
      output: `Result for task ${task.id}`,
      actual_cost: route.estimated_cost || 0,
      execution_time_ms
    };
  }

  /**
   * Registrar erro
   */
  _error(task_id, error_message) {
    const error_entry = {
      task_id,
      error: error_message,
      timestamp: new Date().toISOString()
    };

    this.error_log.push(error_entry);
    this.queue.update(task_id, { status: 'error', error: error_message });

    console.error(`[ORCHESTRATOR] Task ${task_id} error: ${error_message}`);

    return {
      type: 'ERROR',
      task_id,
      error: error_message
    };
  }

  /**
   * Status da fila
   */
  getStatus() {
    return {
      queue_size: this.queue.size(),
      executing: this.executing_tasks.size,
      approvals_pending: this.approvals_pending.size,
      total_tasks_processed: this.execution_log.length,
      total_errors: this.error_log.length,
      cost_report: this.router.getCostReport()
    };
  }

  /**
   * Histórico de execução
   */
  getExecutionHistory(limit = 50) {
    return this.execution_log.slice(-limit).reverse();
  }
}

module.exports = { Orchestrator, TaskQueue };
