/**
 * 🔀 Roteador Unificado de LLM
 *
 * Arquitetura Híbrida:
 * - Ollama (local, gratuito, confidencial) — primeira escolha
 * - Claude/GPT (fallback, pago, mais capaz)
 *
 * Roteamento automático baseado em:
 * - Complexidade da tarefa
 * - Tamanho do contexto
 * - Disponibilidade de modelos
 * - Budget de tokens
 */

class UnifiedLLMRouter {
  constructor(config = {}) {
    // Configurações de modelos locais
    this.ollamaUrl = config.ollamaUrl || "http://localhost:11434";
    this.ollamaModel = config.ollamaModel || "mistral:7b-q4_K_M";
    this.ollamaTimeout = config.ollamaTimeout || 120000; // 2 min

    // Configurações de fallback
    this.claudeKey = config.claudeKey || process.env.ANTHROPIC_API_KEY;
    this.gptKey = config.gptKey || process.env.OPENAI_API_KEY;

    // Limites de custo
    this.dailyBudget = config.dailyBudget || 5.0; // USD
    this.dailySpent = 0;
    this.stats = {
      ollama_calls: 0,
      claude_calls: 0,
      gpt_calls: 0,
      total_cost: 0,
      errors: 0,
    };

    this.logger = config.logger || console;
  }

  /**
   * Classificar complexidade (1-5)
   * 1 = trivial (busca, parsing)
   * 5 = crítico (segurança, arquitetura)
   */
  classifyComplexity(task) {
    const { description = "", context_size = 0, risk_level = "low" } = task;
    let score = 1;

    if (
      description.match(/architecture|design|refactor/i) ||
      description.length > 200
    ) {
      score = 4;
    }
    if (description.match(/security|critical|deploy/i)) {
      score = Math.max(score, 5);
    }
    if (description.match(/review/i) && context_size > 5000) {
      score = 3;
    }
    if (context_size > 20000) {
      score = Math.max(score, 3);
    }
    if (risk_level === "high") {
      score = Math.max(score, 4);
    }

    return Math.min(score, 5);
  }

  /**
   * Rotear para modelo apropriado
   */
  async route(task) {
    const complexity = this.classifyComplexity(task);
    const contextSize = task.context_size || 0;

    this.logger.log(
      `[Router] Task: "${(task.description || "").substring(0, 40)}..." | Complexity: ${complexity}/5 | Context: ${contextSize} chars`
    );

    // Decisão de roteamento
    if (complexity <= 2 && contextSize < 5000) {
      // Simples → Ollama
      return this.callOllama(task);
    }

    if (complexity === 3 && contextSize < 8000) {
      // Médio → Ollama com fallback
      try {
        return await this.callOllama(task);
      } catch (e) {
        this.logger.warn(`[Router] Ollama falhou, escalando para Claude...`);
        return this.callClaude(task);
      }
    }

    // Complexo → Claude/GPT
    try {
      return await this.callClaude(task);
    } catch (e) {
      this.logger.warn(
        `[Router] Claude indisponível, tentando GPT... ${e.message}`
      );
      return this.callGPT(task);
    }
  }

  /**
   * Chamar Ollama (servidor local)
   */
  async callOllama(task) {
    try {
      this.logger.log(`[Ollama] → ${this.ollamaModel}`);

      const controller = new AbortController();
      const timeoutId = setTimeout(
        () => controller.abort(),
        this.ollamaTimeout
      );

      const response = await fetch(`${this.ollamaUrl}/api/generate`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          model: this.ollamaModel,
          prompt: task.prompt || task.description,
          stream: false,
          options: {
            temperature: 0.7,
            top_k: 40,
            top_p: 0.9,
            repeat_penalty: 1.1,
            num_predict: 1000,
          },
        }),
        signal: controller.signal,
      });

      clearTimeout(timeoutId);

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const data = await response.json();
      this.stats.ollama_calls++;
      this.logger.log(
        `[Ollama] ✅ ${data.eval_count} tokens | custo: $0`
      );

      return {
        model: `ollama:${this.ollamaModel}`,
        response: data.response,
        tokens: data.eval_count || 0,
        cost: 0,
        provider: "ollama",
      };
    } catch (error) {
      this.logger.error(`[Ollama] ❌ ${error.message}`);
      this.stats.errors++;
      throw error;
    }
  }

  /**
   * Chamar Claude (fallback pago)
   */
  async callClaude(task) {
    if (!this.claudeKey) {
      throw new Error("Claude: ANTHROPIC_API_KEY não configurada");
    }

    if (this.dailySpent >= this.dailyBudget) {
      throw new Error(
        `Budget diário esgotado: $${this.dailySpent.toFixed(2)} / $${this.dailyBudget}`
      );
    }

    try {
      this.logger.log(`[Claude] → claude-3-sonnet`);

      const response = await fetch("https://api.anthropic.com/v1/messages", {
        method: "POST",
        headers: {
          "x-api-key": this.claudeKey,
          "anthropic-version": "2023-06-01",
          "content-type": "application/json",
        },
        body: JSON.stringify({
          model: "claude-3-sonnet-20240229",
          max_tokens: 2048,
          messages: [
            {
              role: "user",
              content: task.prompt || task.description,
            },
          ],
        }),
      });

      if (!response.ok) {
        const error = await response.json();
        throw new Error(
          error.error?.message || `HTTP ${response.status}`
        );
      }

      const data = await response.json();
      const inputCost = (data.usage.input_tokens * 0.003) / 1000;
      const outputCost = (data.usage.output_tokens * 0.015) / 1000;
      const cost = inputCost + outputCost;

      this.dailySpent += cost;
      this.stats.claude_calls++;
      this.stats.total_cost += cost;

      this.logger.log(
        `[Claude] ✅ ${data.usage.output_tokens} tokens | custo: $${cost.toFixed(4)}`
      );

      return {
        model: "claude-3-sonnet",
        response: data.content[0].text,
        tokens: data.usage.output_tokens,
        cost: cost,
        provider: "anthropic",
      };
    } catch (error) {
      this.logger.error(`[Claude] ❌ ${error.message}`);
      this.stats.errors++;
      throw error;
    }
  }

  /**
   * Chamar GPT-4 (última opção)
   */
  async callGPT(task) {
    if (!this.gptKey) {
      throw new Error("GPT: OPENAI_API_KEY não configurada");
    }

    try {
      this.logger.log(`[GPT] → gpt-4-turbo-preview`);

      const response = await fetch(
        "https://api.openai.com/v1/chat/completions",
        {
          method: "POST",
          headers: {
            Authorization: `Bearer ${this.gptKey}`,
            "Content-Type": "application/json",
          },
          body: JSON.stringify({
            model: "gpt-4-turbo-preview",
            messages: [
              {
                role: "user",
                content: task.prompt || task.description,
              },
            ],
            max_tokens: 2048,
            temperature: 0.7,
          }),
        }
      );

      if (!response.ok) {
        const error = await response.json();
        throw new Error(
          error.error?.message || error.message || `HTTP ${response.status}`
        );
      }

      const data = await response.json();
      const inputCost = (data.usage.prompt_tokens * 0.01) / 1000;
      const outputCost = (data.usage.completion_tokens * 0.03) / 1000;
      const cost = inputCost + outputCost;

      this.dailySpent += cost;
      this.stats.gpt_calls++;
      this.stats.total_cost += cost;

      this.logger.log(
        `[GPT] ✅ ${data.usage.completion_tokens} tokens | custo: $${cost.toFixed(4)}`
      );

      return {
        model: "gpt-4-turbo",
        response: data.choices[0].message.content,
        tokens: data.usage.completion_tokens,
        cost: cost,
        provider: "openai",
      };
    } catch (error) {
      this.logger.error(`[GPT] ❌ ${error.message}`);
      this.stats.errors++;
      throw error;
    }
  }

  /**
   * Estatísticas de uso
   */
  getStats() {
    return {
      ollama_calls: this.stats.ollama_calls,
      claude_calls: this.stats.claude_calls,
      gpt_calls: this.stats.gpt_calls,
      total_cost: `$${this.stats.total_cost.toFixed(2)}`,
      budget_used: `$${this.dailySpent.toFixed(2)} / $${this.dailyBudget}`,
      budget_percent: `${((this.dailySpent / this.dailyBudget) * 100).toFixed(1)}%`,
      errors: this.stats.errors,
    };
  }
}

module.exports = { UnifiedLLMRouter };
