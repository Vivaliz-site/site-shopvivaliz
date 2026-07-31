#!/usr/bin/env node

/**
 * 🚀 Main - Teste e Demonstração do Sistema Híbrido
 *
 * Inicializa:
 * - Orquestrador
 * - Roteador de modelos
 * - Fila de tarefas
 * - Monitoramento
 *
 * Arquivo: .ai/main.js
 */

const { Orchestrator } = require('./orchestrator');
const { UnifiedLLM } = require('./llm-provider');

async function main() {
  console.log('════════════════════════════════════════════════════════════════════════════');
  console.log('🧠 Hybrid AI System - Shop Vivaliz');
  console.log('════════════════════════════════════════════════════════════════════════════\n');

  // Inicializar
  const orchestrator = new Orchestrator({
    max_concurrent_tasks: 2,
    approval_required_for_cost_above: 0.50
  });

  const llm = new UnifiedLLM();

  // Tarefas de teste
  const test_tasks = [
    {
      description: 'Otimizar query de produtos ativos no PHP',
      type: 'fix',
      languages: ['php', 'sql'],
      priority: 'high'
    },
    {
      description: 'Encontrar CSS wildcard problemático no header',
      type: 'debug',
      languages: ['css', 'javascript'],
      priority: 'high'
    },
    {
      description: 'Refatorar integração Olist com retry exponencial',
      type: 'architecture',
      languages: ['php', 'javascript'],
      needs_deep_reasoning: true,
      priority: 'normal'
    },
    {
      description: 'Implementar testes Playwright para fluxo de checkout',
      type: 'test',
      languages: ['javascript', 'typescript'],
      priority: 'normal'
    },
    {
      description: 'Encontrar vulnerabilidade XSS no formulário de contato',
      type: 'security',
      languages: ['javascript', 'php'],
      priority: 'critical'
    }
  ];

  console.log('📋 Submetendo tarefas...\n');

  // Submeter tarefas
  const task_ids = [];
  for (const task of test_tasks) {
    const id = orchestrator.submit(task);
    task_ids.push(id);
    console.log(`   ✓ Task ${id.substring(0, 8)}... (${task.type})`);
  }

  console.log('\n════════════════════════════════════════════════════════════════════════════\n');

  // Processar fila
  console.log('⚙️ Processando fila...\n');

  let processed = 0;
  let max_iterations = 15;

  while (orchestrator.queue.size() > 0 && max_iterations > 0) {
    const result = await orchestrator.process();
    if (result) {
      processed++;

      if (result.type === 'AWAITING_APPROVAL') {
        console.log(`   ⏳ Task ${result.approval_id.substring(0, 8)}... aguardando aprovação (custo: $${result.details.estimated_cost})`);

        // Auto-aprovar para teste
        setTimeout(() => {
          orchestrator.approve(result.approval_id, 'automated_test');
        }, 1000);

      } else if (result.type === 'EXECUTION_COMPLETE') {
        console.log(`   ✓ Task ${result.task_id.substring(0, 8)}... completo (${result.model})`);

      } else if (result.type === 'REJECTED') {
        console.log(`   ✗ Task ${result.task_id.substring(0, 8)}... rejeitado (${result.reason})`);

      } else if (result.type === 'ERROR') {
        console.log(`   ✗ Task ${result.task_id.substring(0, 8)}... erro (${result.error})`);
      }
    }

    max_iterations--;
    await new Promise(r => setTimeout(r, 500));
  }

  console.log('\n════════════════════════════════════════════════════════════════════════════\n');

  // Status final
  console.log('📊 Status Final\n');
  const status = orchestrator.getStatus();

  console.log(`   Tarefas na fila: ${status.queue_size}`);
  console.log(`   Tarefas em execução: ${status.executing}`);
  console.log(`   Aprovações pendentes: ${status.approvals_pending}`);
  console.log(`   Total processadas: ${status.total_tasks_processed}`);
  console.log(`   Erros: ${status.total_errors}`);

  console.log('\n💰 Relatório de Custos\n');
  const cost_report = status.cost_report;
  console.log(`   Custo diário: $${cost_report.summary.daily_used.toFixed(4)}`);
  console.log(`   Limite diário: $${cost_report.summary.daily_limit}`);
  console.log(`   Economia com IA local: $${(cost_report.total_saved_by_local || 0).toFixed(4)}`);

  console.log('\n📝 Histórico de Execução\n');
  const history = orchestrator.getExecutionHistory(5);
  for (const entry of history) {
    const cost_str = entry.actual_cost > 0 ? ` ($${entry.actual_cost})` : ' (grátis)';
    console.log(`   • ${entry.task_id.substring(0, 8)}... ${entry.model}${cost_str}`);
  }

  console.log('\n════════════════════════════════════════════════════════════════════════════\n');

  // Análise das decisões de roteamento
  console.log('🔀 Análise de Roteamento\n');

  const local_tasks = history.filter(t => t.provider === 'ollama').length;
  const paid_tasks = history.filter(t => t.provider !== 'ollama').length;

  console.log(`   Tarefas em IA local: ${local_tasks}`);
  console.log(`   Tarefas em APIs pagas: ${paid_tasks}`);
  console.log(`   Taxa de escalação: ${((paid_tasks / (local_tasks + paid_tasks)) * 100).toFixed(1)}%`);

  console.log('\n════════════════════════════════════════════════════════════════════════════\n');

  console.log('✅ Teste Completo!\n');
  console.log('📌 Próximos Passos:');
  console.log('   1. Instalar Ollama (https://ollama.ai/download)');
  console.log('   2. ollama pull qwen2.5-coder:1.5b');
  console.log('   3. Configurar OPENAI_API_KEY, ANTHROPIC_API_KEY, GOOGLE_API_KEY');
  console.log('   4. Rodar: node .ai/main.js\n');
}

// Executar
main().catch(error => {
  console.error('❌ Erro:', error);
  process.exit(1);
});
