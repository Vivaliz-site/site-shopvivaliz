/**
 * 🧠 Model Router - Roteamento Inteligente de Modelos
 *
 * Decide automaticamente qual modelo usar (local vs. pago)
 * baseado em: complexidade, contexto, custo, risco
 *
 * Arquivo: .ai/model-router.js
 */

const fs = require('fs');
const path = require('path');

class TaskAnalyzer {
  /**
   * Analisa uma tarefa e retorna score de complexidade
   */
  static analyze(task) {
    const analysis = {
      task_id: task.id,
      text: task.description,
      complexity_score: 0,
      risk_level: 'low',
      model_recommendation: null,
      reason: [],
      estimated_cost_usd: 0,
      can_use_local: true
    };

    // 1. Análise de comprimento de contexto
    const contextLength = (task.description + (task.context || '')).length;
    if (contextLength > 8000) {
      analysis.complexity_score += 3;
      analysis.reason.push('Contexto longo (8K+ tokens)');
      analysis.can_use_local = false; // Qwen tem 4K contexto
    }

    // 2. Análise de tipo de tarefa
    const taskType = task.type || '';
    const keywords = {
      'search': { score: 1, reason: 'Busca simples' },
      'edit': { score: 2, reason: 'Edição de código' },
      'fix': { score: 2, reason: 'Correção localizada' },
      'review': { score: 3, reason: 'Revisão requer profundidade' },
      'architecture': { score: 4, reason: 'Decisão arquitetural' },
      'debug': { score: 3, reason: 'Depuração requer raciocínio' },
      'test': { score: 2, reason: 'Testes podem ser locais' },
      'deploy': { score: 5, reason: 'Deploy requer máxima confiança' },
      'payment': { score: 5, reason: 'Pagamento não pode errar' },
      'security': { score: 4, reason: 'Segurança requer especialista' },
      'database': { score: 4, reason: 'DDL requer cuidado' }
    };

    for (const [key, data] of Object.entries(keywords)) {
      if (taskType.toLowerCase().includes(key)) {
        analysis.complexity_score += data.score;
        analysis.reason.push(data.reason);
        break;
      }
    }

    // 3. Risco
    if (taskType.includes('deploy') || taskType.includes('payment') || taskType.includes('delete')) {
      analysis.risk_level = 'critical';
      analysis.complexity_score += 5;
      analysis.reason.push('RISCO CRÍTICO - requer humano');
    } else if (taskType.includes('security') || taskType.includes('database')) {
      analysis.risk_level = 'high';
      analysis.complexity_score += 3;
    }

    // 4. Análise de linguagem
    const languages = task.languages || [];
    const languages_score = {
      'bash': 2,
      'sql': 3,
      'php': 1,
      'javascript': 1,
      'python': 2,
      'typescript': 1,
      'rust': 3,
      'go': 3
    };

    for (const lang of languages) {
      const score = languages_score[lang.toLowerCase()] || 1;
      analysis.complexity_score += score;
    }

    // 5. Necessidades especiais
    if (task.needs_vision) {
      analysis.reason.push('Requer visão (imagens)');
      analysis.complexity_score += 3;
      analysis.can_use_local = false;
      analysis.model_recommendation = 'gemini-pro-vision';
    }

    if (task.needs_web_search) {
      analysis.reason.push('Requer busca na web');
      analysis.complexity_score += 2;
      analysis.model_recommendation = 'gpt-4-turbo';
    }

    if (task.needs_deep_reasoning) {
      analysis.reason.push('Requer raciocínio profundo');
      analysis.complexity_score += 4;
      analysis.can_use_local = false;
    }

    // 6. Cálculo de score final
    analysis.complexity_score = Math.min(10, analysis.complexity_score);

    return analysis;
  }
}

class ModelRouter {
  constructor(config = {}) {
    // Load native env variables just in case
    const envPath = path.join(__dirname, '../.env');
    if (fs.existsSync(envPath)) {
      const lines = fs.readFileSync(envPath, 'utf8').split(/\r?\n/);
      for (const line of lines) {
        const match = line.match(/^\s*([\w.-]+)\s*=\s*(.*)?\s*$/);
        if (match) {
          const key = match[1];
          let value = match[2] || '';
          if (value.startsWith('"') && value.endsWith('"')) value = value.substring(1, value.length - 1);
          else if (value.startsWith("'") && value.endsWith("'")) value = value.substring(1, value.length - 1);
          if (!process.env[key]) process.env[key] = value;
        }
      }
    }

    this.config = {
      local_model: process.env.LOCAL_AI_MODEL || 'qwen2.5-coder:1.5b',
      local_fallback: 'mistral:7b-instruct-q4_K_M',
      ollama_url: process.env.OLLAMA_BASE_URL || 'http://127.0.0.1:11434',
      openai_api_key: process.env.OPENAI_API_KEY,
      anthropic_api_key: process.env.ANTHROPIC_API_KEY,
      google_api_key: process.env.GOOGLE_API_KEY,
      ...config
    };

    this.cost_tracker = {
      daily_used: 0,
      daily_limit: 10.0,
      weekly_used: 0,
      weekly_limit: 50.0,
      monthly_used: 0,
      monthly_limit: 200.0,
      per_task_limit: 2.0
    };

    this.cost_log = [];
  }

  /**
   * Roteador principal: decide qual modelo usar
   */
  route(task) {
    const analysis = TaskAnalyzer.analyze(task);
    const localMode = (process.env.LOCAL_AI_MODE === 'local-only');
    const allowPaid = (process.env.ALLOW_PAID_PROVIDERS === 'true');

    // 1. Bloqueio por risco
    if (analysis.risk_level === 'critical') {
      return {
        decision: 'REQUIRE_HUMAN_APPROVAL',
        reason: analysis.reason,
        model: null,
        estimated_cost: 0
      };
    }

    // Se estiver no modo estritamente local (local-only), forçamos o uso do modelo local
    if (localMode || !allowPaid) {
      return {
        decision: 'USE_LOCAL',
        reason: [...analysis.reason, 'Política local-only ativa: forçando execução local.'],
        model: this.config.local_model,
        provider: 'ollama',
        estimated_cost: 0
      };
    }

    // 2. Se tem recomendação específica
    if (analysis.model_recommendation) {
      return {
        decision: 'ESCALATE_TO_PAID',
        reason: analysis.reason,
        model: analysis.model_recommendation,
        provider: this._modelToProvider(analysis.model_recommendation),
        estimated_cost: this._estimateCost(analysis.model_recommendation, task)
      };
    }

    // 3. Score de complexidade
    if (analysis.complexity_score <= 3 && analysis.can_use_local) {
      // IA Local
      return {
        decision: 'USE_LOCAL',
        reason: analysis.reason,
        model: this.config.local_model,
        provider: 'ollama',
        estimated_cost: 0
      };
    } else if (analysis.complexity_score <= 6) {
      // Pode ser local com fallback
      return {
        decision: 'TRY_LOCAL_FALLBACK_PAID',
        reason: analysis.reason,
        primary: {
          model: this.config.local_model,
          provider: 'ollama',
          estimated_cost: 0
        },
        fallback: {
          model: 'gpt-4-turbo',
          provider: 'openai',
          estimated_cost: this._estimateCost('gpt-4-turbo', task)
        }
      };
    } else {
      // Complexidade alta = pago
      const best_model = this._selectBestPaidModel(analysis, task);
      return {
        decision: 'ESCALATE_TO_PAID',
        reason: analysis.reason,
        model: best_model.model,
        provider: best_model.provider,
        estimated_cost: best_model.cost
      };
    }
  }

  /**
   * Seleciona o melhor modelo pago
   */
  _selectBestPaidModel(analysis, task) {
    const options = [
      { model: 'gpt-4-turbo', provider: 'openai', score: 0.85, cost_per_1k: 0.03 },
      { model: 'claude-opus-4', provider: 'anthropic', score: 0.9, cost_per_1k: 0.075 },
      { model: 'gemini-pro', provider: 'google', score: 0.75, cost_per_1k: 0.005 }
    ];

    // Claude é melhor para código
    if (task.languages && task.languages.length > 0) {
      return {
        model: 'claude-opus-4',
        provider: 'anthropic',
        cost: this._estimateCost('claude-opus-4', task)
      };
    }

    // GPT é bom genérico
    return {
      model: 'gpt-4-turbo',
      provider: 'openai',
      cost: this._estimateCost('gpt-4-turbo', task)
    };
  }

  /**
   * Estima custo de uma chamada
   */
  _estimateCost(model, task) {
    const costs = {
      'gpt-4-turbo': { input: 0.00003, output: 0.0006 },
      'claude-opus-4': { input: 0.000075, output: 0.00024 },
      'gemini-pro': { input: 0.00001, output: 0.00002 },
      'qwen2.5-coder:1.5b': { input: 0, output: 0 }
    };

    const rate = costs[model] || { input: 0, output: 0 };
    const contextTokens = (task.description + (task.context || '')).length / 4;
    const expectedTokens = contextTokens * 0.5; // Rough estimate

    return (contextTokens * rate.input + expectedTokens * rate.output).toFixed(4);
  }

  /**
   * Mapeia modelo para provider
   */
  _modelToProvider(model) {
    if (model.includes('gpt')) return 'openai';
    if (model.includes('claude')) return 'anthropic';
    if (model.includes('gemini')) return 'google';
    return 'ollama';
  }

  /**
   * Verifica limites de custo
   */
  canExecute(task, estimated_cost) {
    const cost = parseFloat(estimated_cost || 0);

    if (cost > this.cost_tracker.per_task_limit) {
      return {
        allowed: false,
        reason: `Custo por tarefa (${cost}) excedem o limite (${this.cost_tracker.per_task_limit})`
      };
    }

    if (this.cost_tracker.daily_used + cost > this.cost_tracker.daily_limit) {
      return {
        allowed: false,
        reason: `Limite diário atingido (${this.cost_tracker.daily_used}/${this.cost_tracker.daily_limit})`
      };
    }

    if (this.cost_tracker.weekly_used + cost > this.cost_tracker.weekly_limit) {
      return {
        allowed: false,
        reason: `Limite semanal atingido (${this.cost_tracker.weekly_used}/${this.cost_tracker.weekly_limit})`
      };
    }

    if (this.cost_tracker.monthly_used + cost > this.cost_tracker.monthly_limit) {
      return {
        allowed: false,
        reason: `Limite mensal atingido (${this.cost_tracker.monthly_used}/${this.cost_tracker.monthly_limit})`
      };
    }

    return { allowed: true };
  }

  /**
   * Registra um chamada executada
   */
  logCostUsage(task_id, provider, model, estimated_cost, actual_cost, result_quality) {
    const now = new Date();
    const entry = {
      timestamp: now.toISOString(),
      task_id,
      provider,
      model,
      estimated_cost: parseFloat(estimated_cost),
      actual_cost: parseFloat(actual_cost),
      result_quality,
      savings: parseFloat(estimated_cost) - parseFloat(actual_cost)
    };

    this.cost_log.push(entry);

    // Atualizar totais
    const cost = parseFloat(actual_cost);
    this.cost_tracker.daily_used += cost;
    this.cost_tracker.weekly_used += cost;
    this.cost_tracker.monthly_used += cost;

    return entry;
  }

  /**
   * Retorna relatório de custos
   */
  getCostReport() {
    return {
      summary: this.cost_tracker,
      log: this.cost_log,
      average_cost_per_task: (this.cost_tracker.daily_used / (this.cost_log.length || 1)).toFixed(4),
      total_saved_by_local: this.cost_log
        .filter(log => log.provider === 'ollama')
        .reduce((sum, log) => sum + log.actual_cost, 0)
    };
  }
}

module.exports = { ModelRouter, TaskAnalyzer };
