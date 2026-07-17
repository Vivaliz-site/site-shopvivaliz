const fs = require('fs');
const path = require('path');
const { v4: uuidv4 } = require('uuid');
const { ModelRouter, TaskAnalyzer } = require('./model-router');
const { UnifiedLLM } = require('./llm-provider');

class TaskQueue {
  constructor() {
    this.tasks = new Map();
    this.queue = [];
    this.dataDir = path.join(__dirname, 'data');
    this.tasksFile = path.join(this.dataDir, 'tasks.jsonl');
    this.ensureStorage();
    this.loadFromDisk();
  }

  ensureStorage() {
    if (!fs.existsSync(this.dataDir)) {
      fs.mkdirSync(this.dataDir, { recursive: true });
    }
  }

  loadFromDisk() {
    if (!fs.existsSync(this.tasksFile)) {
      return;
    }

    try {
      const content = fs.readFileSync(this.tasksFile, 'utf8');
      const lines = content.split(/\r?\n/);
      for (const line of lines) {
        if (!line.trim()) continue;
        try {
          const task = JSON.parse(line);
          if (task && task.id) {
            if (task.status === 'executing' || task.status === 'running') {
              task.status = 'pending';
              task.interrupted = true;
            }
            this.tasks.set(task.id, task);
          }
        } catch (e) {
          console.error(`[TaskQueue] Error parsing line: ${line}`, e);
        }
      }

      const sortedTasks = Array.from(this.tasks.values())
        .sort((a, b) => new Date(a.created_at) - new Date(b.created_at));

      this.queue = [];
      for (const task of sortedTasks) {
        if (task.status === 'pending') {
          this.queue.push(task.id);
        }
      }
      console.log(`[TaskQueue] Loaded ${this.tasks.size} tasks from disk. ${this.queue.length} pending.`);
    } catch (err) {
      console.error('[TaskQueue] Error reading tasks file from disk:', err);
    }
  }

  persistTask(task) {
    try {
      const line = JSON.stringify(task) + '\n';
      fs.appendFileSync(this.tasksFile, line, 'utf8');
    } catch (err) {
      console.error('[TaskQueue] Error appending task to disk:', err);
    }
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
    this.persistTask(fullTask);
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
      this.persistTask(task);
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
    return Array.from(this.tasks.values()).filter(t => t.status === 'pending').length;
  }
}

class Orchestrator {
  constructor(config = {}) {
    this.config = {
      max_concurrent_tasks: 2,
      approval_required_for_cost_above: 0.50,
      approval_timeout_ms: 3600000,
      ...config
    };

    this.router = new ModelRouter(config);
    this.queue = new TaskQueue();
    this.executing_tasks = new Map();
    this.approvals_pending = new Map();
    this.execution_log = [];
    this.error_log = [];
    this.resultsFile = path.join(__dirname, 'data', 'results.jsonl');
    this.stateFile = path.join(__dirname, 'data', 'state.json');
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
  /**
   * Persistir resultado no disco e atualizar state.json
   */
  _persistResult(logEntry) {
    try {
      fs.appendFileSync(this.resultsFile, JSON.stringify(logEntry) + '\n', 'utf8');
      const statusSummary = this.getStatus();
      fs.writeFileSync(this.stateFile, JSON.stringify({
        last_updated: new Date().toISOString(),
        summary: statusSummary
      }, null, 2), 'utf8');
    } catch (err) {
      console.error('[Orchestrator] Error persisting result to disk:', err);
    }
  }

  /**
   * Executar uma tarefa
   */
  async _execute(task, route) {
    this.executing_tasks.set(task.id, {
      started_at: new Date().toISOString(),
      route,
      status: 'executing'
    });

    this.queue.update(task.id, { 
      status: 'executing',
      started_at: new Date().toISOString()
    });

    console.log(`[ORCHESTRATOR] Executing task ${task.id} with ${route.model}`);

    const start = Date.now();
    let result;
    try {
      const llm = new UnifiedLLM();
      const modelId = `${route.provider}:${route.model}`;
      const messages = [
        { role: 'system', content: 'Você é um assistente de IA focado no desenvolvimento do Shop Vivaliz.' },
        { role: 'user', content: `Tarefa: ${task.description}\nContexto: ${task.context || ''}` }
      ];

      const llmResult = await llm.call(modelId, messages);
      const execution_time_ms = Date.now() - start;

      result = {
        success: true,
        output: llmResult.content,
        actual_cost: parseFloat(llmResult.cost || 0),
        execution_time_ms,
        provider: llmResult.provider,
        model: llmResult.model,
        simulated: false
      };
    } catch (error) {
      const execution_time_ms = Date.now() - start;
      const isPaidConfigError = error.message.includes('paid_provider_not_configured');

      result = {
        success: false,
        error: error.message,
        actual_cost: 0,
        execution_time_ms,
        simulated: false,
        blocked: isPaidConfigError,
        reason: isPaidConfigError ? 'paid_provider_not_configured' : 'execution_failed'
      };
    }

    const log_entry = {
      task_id: task.id,
      executed_at: new Date().toISOString(),
      provider: route.provider,
      model: route.model,
      estimated_cost: route.estimated_cost,
      actual_cost: result.actual_cost,
      success: result.success,
      result: result.success ? result.output : (result.error || result.reason),
      execution_time_ms: result.execution_time_ms,
      simulated: false
    };

    this.execution_log.push(log_entry);
    this._persistResult(log_entry);

    if (route.provider && route.provider !== 'ollama' && !result.blocked) {
      this.router.logCostUsage(
        task.id,
        route.provider,
        route.model,
        route.estimated_cost,
        result.actual_cost,
        result.success ? 'high' : 'failed'
      );
    }

    const finalStatus = result.success ? 'completed' : (result.blocked ? 'blocked' : 'failed');
    this.queue.update(task.id, {
      status: finalStatus,
      result: log_entry,
      finished_at: new Date().toISOString(),
      provider: route.provider,
      model: route.model,
      cost: result.actual_cost,
      simulated: false,
      error: result.success ? null : result.error
    });

    this.executing_tasks.delete(task.id);

    console.log(`[ORCHESTRATOR] Task ${task.id} ${finalStatus}`);

    return {
      type: 'EXECUTION_COMPLETE',
      task_id: task.id,
      success: result.success,
      status: finalStatus,
      ...log_entry
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
