'use strict';

/**
 * Fail-closed agent registry.
 *
 * The previous implementation incremented completion counters and returned
 * success without calling an LLM, changing files, running tests, or producing
 * evidence. This compatibility layer preserves the exported API while refusing
 * to report fictional completion.
 */

class Agent {
  constructor(config = {}) {
    this.name = config.name || 'Unconfigured agent';
    this.role = config.role || 'unconfigured';
    this.objective = config.objective || '';
    this.allowed_tools = config.allowed_tools || [];
    this.forbidden_tools = config.forbidden_tools || [];
    this.stats = { tasks_completed: 0, tasks_failed: 0, total_cost: 0 };
  }

  async execute(task = {}) {
    this.stats.tasks_failed += 1;
    return {
      success: false,
      agent: this.name,
      task_id: task.id || null,
      error:
        'Agent execution is disabled until a real provider, tool authorization, ' +
        'test evidence, and reviewed persistence workflow are configured.',
    };
  }

  getStats() {
    return { name: this.name, role: this.role, ...this.stats };
  }
}

const AGENTS = {
  orchestrator: new Agent({ name: 'Orchestrator', role: 'triage only' }),
};

class AgentManager {
  constructor() {
    this.agents = AGENTS;
    this.execution_log = [];
  }

  selectAgent() {
    return this.agents.orchestrator;
  }

  async execute(task) {
    const agent = this.selectAgent(task);
    const result = await agent.execute(task);
    this.execution_log.push({
      timestamp: new Date().toISOString(),
      task_id: task && task.id ? task.id : null,
      agent: agent.name,
      ...result,
    });
    return result;
  }

  getAgentsStatus() {
    return Object.fromEntries(
      Object.entries(this.agents).map(([key, agent]) => [key, agent.getStats()]),
    );
  }

  getExecutionLog(limit = 100) {
    return this.execution_log.slice(-limit).reverse();
  }

  getTotalCost() {
    return 0;
  }
}

module.exports = { Agent, AgentManager, AGENTS };
