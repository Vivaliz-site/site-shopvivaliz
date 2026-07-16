// Node 18+ has native fetch

/**
 * 🌐 LLM Provider - Abstração Unificada para APIs Pagas
 *
 * Suporta: OpenAI (GPT), Anthropic (Claude), Google (Gemini)
 * Sem vendor lock-in, fácil trocar provedor
 *
 * Arquivo: .ai/llm-provider.js
 */
class OpenAIProvider {
  constructor(api_key) {
    this.api_key = api_key;
    this.base_url = 'https://api.openai.com/v1';
  }
  async chat(messages, options = {}) {
    const payload = {
      model: options.model || 'gpt-4-turbo',
      messages,
      temperature: options.temperature || 0.7,
      max_tokens: options.max_tokens || 2000,
      top_p: options.top_p || 0.95
    };
    const response = await fetch(`${this.base_url}/chat/completions`, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${this.api_key}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload)
    });
    if (!response.ok) {
      throw new Error(`OpenAI error: ${response.statusText}`);
    }
    const data = await response.json();
    return {
      content: data.choices[0].message.content,
      usage: {
        input_tokens: data.usage.prompt_tokens,
        output_tokens: data.usage.completion_tokens,
        total_tokens: data.usage.total_tokens
      },
      model: data.model
    };
  }
  calculateCost(usage) {
    const rates = {
      'gpt-4-turbo': { input: 0.00003, output: 0.0006 }
    };
    const rate = rates[this.model] || rates['gpt-4-turbo'];
    return (usage.input_tokens * rate.input + usage.output_tokens * rate.output).toFixed(6);
  }
}
class AnthropicProvider {
  constructor(api_key) {
    this.api_key = api_key;
    this.base_url = 'https://api.anthropic.com/v1';
  }
  async chat(messages, options = {}) {
    // Converter formato de OpenAI para Anthropic
    const system_message = messages
      .filter(m => m.role === 'system')
      .map(m => m.content)
      .join('\n');
    const user_messages = messages.filter(m => m.role !== 'system');
    const payload = {
      model: options.model || 'claude-opus-4',
      max_tokens: options.max_tokens || 2000,
      messages: user_messages,
      temperature: options.temperature || 0.7
    };
    if (system_message) {
      payload.system = system_message;
    }
    const response = await fetch(`${this.base_url}/messages`, {
      method: 'POST',
      headers: {
        'x-api-key': this.api_key,
        'anthropic-version': '2023-06-01',
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload)
    });
    if (!response.ok) {
      throw new Error(`Anthropic error: ${response.statusText}`);
    }
    const data = await response.json();
    return {
      content: data.content[0].text,
      usage: {
        input_tokens: data.usage.input_tokens,
        output_tokens: data.usage.output_tokens,
        total_tokens: data.usage.input_tokens + data.usage.output_tokens
      },
      model: data.model
    };
  }
  calculateCost(usage, model) {
    const rates = {
      'claude-opus-4': { input: 0.000075, output: 0.00024 },
      'claude-sonnet': { input: 0.000003, output: 0.000015 }
    };
    const rate = rates[model] || rates['claude-opus-4'];
    return (usage.input_tokens * rate.input + usage.output_tokens * rate.output).toFixed(6);
  }
}
class GoogleProvider {
  constructor(api_key) {
    this.api_key = api_key;
    this.base_url = 'https://generativelanguage.googleapis.com/v1beta';
  }
  async chat(messages, options = {}) {
    const contents = messages.map(m => ({
      role: m.role === 'user' ? 'user' : 'model',
      parts: [{ text: m.content }]
    }));
    const payload = {
      contents,
      generationConfig: {
        temperature: options.temperature || 0.7,
        maxOutputTokens: options.max_tokens || 2000,
        topP: options.top_p || 0.95
      }
    };
    const model = options.model || 'gemini-pro';
    const url = `${this.base_url}/models/${model}:generateContent?key=${this.api_key}`;
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload)
    });
    if (!response.ok) {
      throw new Error(`Google error: ${response.statusText}`);
    }
    const data = await response.json();
    const content = data.candidates[0].content.parts[0].text;
    return {
      content,
      usage: {
        input_tokens: data.usageMetadata?.promptTokenCount || 0,
        output_tokens: data.usageMetadata?.candidatesTokenCount || 0,
        total_tokens: (data.usageMetadata?.promptTokenCount || 0) + (data.usageMetadata?.candidatesTokenCount || 0)
      },
      model
    };
  }
  calculateCost(usage, model) {
    const rates = {
      'gemini-pro': { input: 0.00001, output: 0.00002 },
      'gemini-pro-vision': { input: 0.0001, output: 0.0004 }
    };
    const rate = rates[model] || rates['gemini-pro'];
    return (usage.input_tokens * rate.input + usage.output_tokens * rate.output).toFixed(6);
  }
}
class OllamaProvider {
  constructor(base_url = 'http://localhost:11434') {
    this.base_url = base_url;
  }
  async chat(messages, options = {}) {
    const model = options.model || 'qwen2.5-coder:1.5b-q2_K';
    // Converter array de messages para string
    const context = messages.map(m => `${m.role}: ${m.content}`).join('\n');
    const payload = {
      model,
      messages: messages.map(m => ({
        role: m.role === 'system' ? 'system' : (m.role === 'user' ? 'user' : 'assistant'),
        content: m.content
      })),
      stream: false,
      options: {
        temperature: options.temperature || 0.7,
        num_predict: options.max_tokens || 2000,
        top_p: options.top_p || 0.95
      }
    };
    const response = await fetch(`${this.base_url}/api/chat`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(payload)
    });
    if (!response.ok) {
      throw new Error(`Ollama error: ${response.statusText}`);
    }
    const data = await response.json();
    return {
      content: data.message.content,
      usage: {
        input_tokens: data.prompt_eval_count || 0,
        output_tokens: data.eval_count || 0,
        total_tokens: (data.prompt_eval_count || 0) + (data.eval_count || 0)
      },
      model,
      duration_ms: Math.round(data.total_duration / 1000000) // nanoseconds to ms
    };
  }
  calculateCost() {
    return '0.00'; // Ollama é grátis
  }
}
class LLMProviderFactory {
  static create(provider, config) {
    switch (provider.toLowerCase()) {
      case 'openai':
        return new OpenAIProvider(config.api_key || process.env.OPENAI_API_KEY);
      case 'anthropic':
        return new AnthropicProvider(config.api_key || process.env.ANTHROPIC_API_KEY);
      case 'google':
        return new GoogleProvider(config.api_key || process.env.GOOGLE_API_KEY);
      case 'ollama':
        return new OllamaProvider(config.base_url);
      default:
        throw new Error(`Unknown provider: ${provider}`);
    }
  }
}
/**
 * Classe unificada para chamar qualquer modelo
 */
class UnifiedLLM {
  constructor() {
    this.providers = new Map();
  }
  /**
   * Chamar qualquer modelo
   */
  async call(model_id, messages, options = {}) {
    const [provider, model] = model_id.split(':');
    if (!this.providers.has(provider)) {
      this.providers.set(provider, LLMProviderFactory.create(provider, {}));
    }
    const llm_provider = this.providers.get(provider);
    const result = await llm_provider.chat(messages, { model, ...options });
    return {
      ...result,
      provider,
      cost: llm_provider.calculateCost(result.usage, model)
    };
  }
}
module.exports = {
  OpenAIProvider,
  AnthropicProvider,
  GoogleProvider,
  OllamaProvider,
  LLMProviderFactory,
  UnifiedLLM
};
