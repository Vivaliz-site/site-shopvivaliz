#!/usr/bin/env node

/**
 * ✅ Validação Completa - Fase 8
 *
 * Testa:
 * 1. Roteador de modelos
 * 2. Orquestrador
 * 3. Agentes
 * 4. Custos
 * 5. Segurança
 *
 * Arquivo: .ai/validate.js
 */

const { Orchestrator } = require('./orchestrator');
const { TaskAnalyzer } = require('./model-router');
const { AgentManager } = require('./agents');

class Validator {
  constructor() {
    this.tests = [];
    this.passed = 0;
    this.failed = 0;
  }

  async test(name, fn) {
    try {
      await fn();
      this.passed++;
      console.log(`✅ ${name}`);
      this.tests.push({ name, status: 'pass' });
    } catch (error) {
      this.failed++;
      console.log(`❌ ${name}`);
      console.log(`   Erro: ${error.message}`);
      this.tests.push({ name, status: 'fail', error: error.message });
    }
  }

  assert(condition, message) {
    if (!condition) {
      throw new Error(message);
    }
  }

  assertEqual(actual, expected, message) {
    if (actual !== expected) {
      throw new Error(`${message} (esperado: ${expected}, recebido: ${actual})`);
    }
  }

  assertGreaterThan(actual, threshold, message) {
    if (actual <= threshold) {
      throw new Error(`${message} (esperado > ${threshold}, recebido: ${actual})`);
    }
  }

  report() {
    console.log('\n════════════════════════════════════════════════════════════════════════════');
    console.log(`✅ TESTES PASSARAM: ${this.passed}/${this.passed + this.failed}`);
    console.log('════════════════════════════════════════════════════════════════════════════\n');
    return this.failed === 0;
  }
}

async function validate() {
  const validator = new Validator();

  console.log('════════════════════════════════════════════════════════════════════════════');
  console.log('🧪 FASE 8 - VALIDAÇÃO COMPLETA END-TO-END');
  console.log('════════════════════════════════════════════════════════════════════════════\n');

  // ========== TESTE 1: Analisador de Tarefas ==========
  console.log('📊 TESTE 1: Analisador de Tarefas\n');

  await validator.test('Análise de tarefa simples', () => {
    const analysis = TaskAnalyzer.analyze({
      description: 'Encontrar função no PHP',
      type: 'search',
      languages: ['php']
    });

    validator.assertLessOrEqual(analysis.complexity_score, 3);
    validator.assertEqual(analysis.risk_level, 'low');
  });

  await validator.test('Análise de tarefa complexa', () => {
    const analysis = TaskAnalyzer.analyze({
      description: 'Refatorar sistema de pagamento',
      type: 'architecture',
      languages: ['php', 'javascript'],
      needs_deep_reasoning: true
    });

    validator.assertGreaterThan(analysis.complexity_score, 4);
  });

  await validator.test('Tarefa crítica bloqueada', () => {
    const analysis = TaskAnalyzer.analyze({
      description: 'Deploy em produção',
      type: 'deploy'
    });

    validator.assertEqual(analysis.risk_level, 'critical');
  });

  // ========== TESTE 2: Roteador de Modelos ==========
  console.log('\n🔀 TESTE 2: Roteador de Modelos\n');

  await validator.test('Tarefa simples → IA Local', () => {
    const orchestrator = new Orchestrator();
    const task = {
      description: 'Busca simples',
      type: 'search'
    };
    const analysis = TaskAnalyzer.analyze(task);
    const route = orchestrator.router.route(task);

    validator.assertEqual(route.decision, 'USE_LOCAL');
    validator.assertEqual(route.provider, 'ollama');
  });

  await validator.test('Tarefa média → Fallback permitido', () => {
    const orchestrator = new Orchestrator();
    const task = {
      description: 'Debug de código',
      type: 'debug',
      languages: ['javascript']
    };
    const route = orchestrator.router.route(task);

    validator.assert(
      route.decision === 'USE_LOCAL' || route.decision === 'TRY_LOCAL_FALLBACK_PAID',
      'Decision deve ser local ou fallback'
    );
  });

  await validator.test('Tarefa crítica → Requer humano', () => {
    const orchestrator = new Orchestrator();
    const task = {
      description: 'Deploy produção',
      type: 'deploy'
    };
    const route = orchestrator.router.route(task);

    validator.assertEqual(route.decision, 'REQUIRE_HUMAN_APPROVAL');
  });

  // ========== TESTE 3: Orquestrador ==========
  console.log('\n🎭 TESTE 3: Orquestrador\n');

  await validator.test('Submeter tarefa', () => {
    const orchestrator = new Orchestrator();
    const task_id = orchestrator.submit({
      description: 'Teste',
      type: 'test'
    });

    validator.assert(task_id, 'Task ID deve ser gerado');
    validator.assertGreaterThan(task_id.length, 20, 'Task ID deve ter UUID');
  });

  await validator.test('Fila de tarefas funciona', () => {
    const orchestrator = new Orchestrator();
    orchestrator.submit({ description: 'Task 1', type: 'test' });
    orchestrator.submit({ description: 'Task 2', type: 'test' });

    validator.assertGreaterThan(orchestrator.queue.size(), 1);
  });

  await validator.test('Status do orquestrador', () => {
    const orchestrator = new Orchestrator();
    orchestrator.submit({ description: 'Task 1', type: 'test' });

    const status = orchestrator.getStatus();
    validator.assert(status.queue_size > 0, 'Queue deve ter tarefas');
    validator.assert(status.cost_report, 'Cost report deve existir');
  });

  // ========== TESTE 4: Agentes ==========
  console.log('\n🤖 TESTE 4: Agentes\n');

  await validator.test('AgentManager inicializa', () => {
    const manager = new AgentManager();
    validator.assert(manager.agents, 'Agentes devem existir');
    validator.assertGreaterThan(Object.keys(manager.agents).length, 8);
  });

  await validator.test('Seleção de agente por tipo', () => {
    const manager = new AgentManager();
    const backend_agent = manager.selectAgent({ type: 'fix' });
    const frontend_agent = manager.selectAgent({ type: 'edit_frontend' });

    validator.assert(backend_agent.name.includes('Backend'), 'Backend para tarefa fix');
    validator.assert(frontend_agent.name.includes('Frontend'), 'Frontend para edit_frontend');
  });

  // ========== TESTE 5: Controle de Custos ==========
  console.log('\n💰 TESTE 5: Controle de Custos\n');

  await validator.test('Limite de custo por tarefa', () => {
    const orchestrator = new Orchestrator();
    const can_execute = orchestrator.router.canExecute(
      { id: 'test' },
      10.0 // $10
    );

    validator.assertEqual(can_execute.allowed, false);
  });

  await validator.test('Custo dentro do limite', () => {
    const orchestrator = new Orchestrator();
    const can_execute = orchestrator.router.canExecute(
      { id: 'test' },
      0.10 // $0.10
    );

    validator.assertEqual(can_execute.allowed, true);
  });

  await validator.test('Logging de custos', () => {
    const orchestrator = new Orchestrator();
    orchestrator.router.logCostUsage(
      'task-123',
      'openai',
      'gpt-4-turbo',
      '0.12',
      '0.11',
      'high'
    );

    const report = orchestrator.router.getCostReport();
    validator.assertGreaterThan(report.summary.daily_used, 0);
  });

  // ========== TESTE 6: Segurança ==========
  console.log('\n🔒 TESTE 6: Segurança\n');

  await validator.test('Ferramenta proibida bloqueada', async () => {
    const manager = new AgentManager();
    const result = await manager.agents.backend.execute({
      description: 'Deletar banco',
      required_tools: ['delete_production']
    });

    validator.assertEqual(result.success, false);
  });

  await validator.test('Ferramentas permitidas', async () => {
    const manager = new AgentManager();
    const agent = manager.selectAgent({ type: 'fix' });
    validator.assert(agent.allowed_tools.includes('git'), 'Backend deve ter git');
  });

  // ========== TESTE 7: Fluxo End-to-End ==========
  console.log('\n🔄 TESTE 7: Fluxo End-to-End\n');

  await validator.test('Fluxo completo: submit → analyze → route', () => {
    const orchestrator = new Orchestrator();
    const task_id = orchestrator.submit({
      description: 'Otimizar query SQL',
      type: 'fix',
      languages: ['sql']
    });

    const task = orchestrator.queue.get(task_id);
    const analysis = TaskAnalyzer.analyze(task);
    const route = orchestrator.router.route(task);

    validator.assert(task, 'Tarefa deve existir na fila');
    validator.assert(analysis, 'Análise deve ser criada');
    validator.assert(route, 'Roteamento deve ser decidido');
  });

  // ========== TESTE 8: Escalação Inteligente ==========
  console.log('\n📈 TESTE 8: Escalação Inteligente\n');

  await validator.test('Escalação por custo', () => {
    const orchestrator = new Orchestrator();
    orchestrator.router.cost_tracker.daily_used = 9.5;

    const can_execute = orchestrator.router.canExecute(
      { id: 'test' },
      1.0 // $1.0
    );

    validator.assertEqual(can_execute.allowed, false);
  });

  await validator.test('IA Local economiza', () => {
    const orchestrator = new Orchestrator();
    const task_local = {
      description: 'Tarefa simples',
      type: 'search'
    };

    const route = orchestrator.router.route(task_local);
    const estimated_cost = route.estimated_cost || 0;

    validator.assertEqual(estimated_cost, 0);
  });

  // ========== TESTE 9: Integração com Agentes ==========
  console.log('\n🔗 TESTE 9: Integração com Agentes\n');

  await validator.test('AgentManager executa tarefa', async () => {
    const manager = new AgentManager();
    const result = await manager.execute({
      id: 'test-001',
      type: 'fix',
      description: 'Teste'
    });

    validator.assert(result, 'Resultado deve existir');
    validator.assert(result.agent, 'Agent deve ser registrado');
  });

  // ========== TESTE 10: Histórico e Logs ==========
  console.log('\n📝 TESTE 10: Histórico e Logs\n');

  await validator.test('Histórico de execução', () => {
    const orchestrator = new Orchestrator();

    // Simular execuções
    orchestrator.execution_log.push({
      task_id: 'test-001',
      status: 'completed',
      timestamp: new Date().toISOString()
    });

    const history = orchestrator.getExecutionHistory(10);
    validator.assert(history.length > 0, 'Histórico deve ter entradas');
  });

  // Resultado final
  console.log('');
  const all_passed = validator.report();

  if (all_passed) {
    console.log('🎉 VALIDAÇÃO COMPLETA - TODOS OS TESTES PASSARAM!\n');
    console.log('📊 Resumo:\n');
    console.log('   ✅ Analisador de tarefas: funcional');
    console.log('   ✅ Roteador de modelos: funcional');
    console.log('   ✅ Orquestrador: funcional');
    console.log('   ✅ Agentes especializados: funcional');
    console.log('   ✅ Controle de custos: funcional');
    console.log('   ✅ Segurança: funcional');
    console.log('   ✅ Fluxo end-to-end: funcional');
    console.log('   ✅ Escalação inteligente: funcional');
    console.log('   ✅ Integração: funcional');
    console.log('   ✅ Histórico/logs: funcional\n');
    console.log('📈 Taxa de sucesso: 100%\n');
    console.log('🚀 Sistema pronto para operação!\n');
    process.exit(0);
  } else {
    console.log(`🚨 VALIDAÇÃO FALHOU - ${validator.failed} teste(s) falharam\n`);
    process.exit(1);
  }
}

// Helpers
Validator.prototype.assertLessOrEqual = function(actual, threshold, message = '') {
  if (actual > threshold) {
    throw new Error(`${message} (esperado <= ${threshold}, recebido: ${actual})`);
  }
};

// Executar validação
validate().catch(error => {
  console.error('❌ Erro na validação:', error);
  process.exit(1);
});
