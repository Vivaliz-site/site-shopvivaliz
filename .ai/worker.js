#!/usr/bin/env node

/**
 * ⚙️ Worker - Serviço Contínuo do Orquestrador de IA Local
 *
 * Arquivo: .ai/worker.js
 */

const fs = require('fs');
const path = require('path');
const { Orchestrator } = require('./orchestrator');

const REPO_ROOT = path.join(__dirname, '..');
const LOGS_DIR = path.join(REPO_ROOT, 'logs');
const PID_FILE = path.join(LOGS_DIR, 'local-ai-service.pid');
const HEARTBEAT_FILE = path.join(LOGS_DIR, 'local-ai-heartbeat.json');
const SERVICE_LOG = path.join(LOGS_DIR, 'local-ai-service.log');

// Certificar diretório de logs
if (!fs.existsSync(LOGS_DIR)) {
  fs.mkdirSync(LOGS_DIR, { recursive: true });
}

// Custom log functions to write to local-ai-service.log
function log(msg) {
  const line = `[${new Date().toISOString()}] ${msg}\n`;
  console.log(msg);
  fs.appendFileSync(SERVICE_LOG, line, 'utf8');
}

function logError(msg, err) {
  const errMsg = err ? ` : ${err.message}\n${err.stack}` : '';
  const line = `[${new Date().toISOString()}] [ERROR] ${msg}${errMsg}\n`;
  console.error(`${msg}${errMsg}`);
  fs.appendFileSync(SERVICE_LOG, line, 'utf8');
}

// 1. Evitar instâncias duplicadas (PID lock file)
if (fs.existsSync(PID_FILE)) {
  try {
    const existingPid = parseInt(fs.readFileSync(PID_FILE, 'utf8').trim());
    if (existingPid) {
      try {
        process.kill(existingPid, 0);
        logError(`[WORKER] Outro worker já está rodando com PID: ${existingPid}. Encerrando.`);
        process.exit(1);
      } catch (e) {
        log(`[WORKER] PID file stale encontrado (processo ${existingPid} inativo). Removendo.`);
        fs.unlinkSync(PID_FILE);
      }
    }
  } catch (err) {
    logError('[WORKER] Erro ao ler arquivo PID lock:', err);
  }
}

// Escrever o PID atual
fs.writeFileSync(PID_FILE, process.pid.toString(), 'utf8');

// Configurações do ambiente
const localModel = process.env.LOCAL_AI_MODEL || 'qwen2.5-coder:1.5b';
const ollamaUrl = process.env.OLLAMA_BASE_URL || 'http://127.0.0.1:11434';
const mcpUrl = 'http://127.0.0.1:5555';

log('════════════════════════════════════════════════════════════════════════════');
log(`🧠 Worker de IA Local Iniciado - PID: ${process.pid}`);
log(`   Modelo: ${localModel}`);
log(`   Ollama API: ${ollamaUrl}`);
log('════════════════════════════════════════════════════════════════════════════');

let shutdownRequested = false;
let orchestrator = null;

// Graceful Shutdown
function handleShutdown() {
  if (shutdownRequested) return;
  shutdownRequested = true;
  log('[WORKER] Recebido sinal de parada. Limpando PID lock e encerrando worker...');
  try {
    if (fs.existsSync(PID_FILE)) {
      fs.unlinkSync(PID_FILE);
    }
  } catch (err) {
    logError('[WORKER] Erro ao deletar arquivo PID lock:', err);
  }
  log('[WORKER] Finalizado.');
  process.exit(0);
}

process.on('SIGINT', handleShutdown);
process.on('SIGTERM', handleShutdown);

// Inicializar e rodar o loop principal
async function start() {
  try {
    // 2. Validar Ollama e Modelo antes de iniciar o processamento
    log('[WORKER] Validando conexão com Ollama...');
    let ollamaHealthy = false;
    try {
      const response = await fetch(`${ollamaUrl}/api/tags`);
      if (response.ok) {
        const data = await response.json();
        const models = (data.models || []).map(m => m.name);
        const hasModel = models.some(m => m.startsWith(localModel) || localModel.startsWith(m));
        if (hasModel) {
          ollamaHealthy = true;
          log(`[WORKER] Conectado ao Ollama com sucesso! Modelo '${localModel}' está disponível.`);
        } else {
          logError(`[WORKER] O modelo configurado '${localModel}' não foi encontrado no Ollama. Modelos ativos: ${models.join(', ')}`);
        }
      } else {
        logError(`[WORKER] Resposta inválida do Ollama API: ${response.statusText}`);
      }
    } catch (e) {
      logError(`[WORKER] Não foi possível conectar ao Ollama em ${ollamaUrl}. Certifique-se de que o Ollama está rodando.`, e);
    }

    if (!ollamaHealthy) {
      logError('[WORKER] Falha na validação do Ollama. O worker continuará tentando em background.');
    }

    // 3. Validar MCP local
    log('[WORKER] Validando conexão com o Servidor MCP local...');
    try {
      const response = await fetch(`${mcpUrl}/health`);
      if (response.ok) {
        log('[WORKER] Servidor MCP local está ativo e saudável.');
      } else {
        log(`[WORKER] Alerta: MCP local retornou status HTTP ${response.status}`);
      }
    } catch (e) {
      log('[WORKER] Alerta: Não foi possível conectar ao MCP local em 127.0.0.1:5555. Talvez ainda esteja subindo.');
    }

    // Inicializar orquestrador
    orchestrator = new Orchestrator({
      max_concurrent_tasks: 1,
      approval_required_for_cost_above: 0.50
    });

    log('[WORKER] Orquestrador e fila carregados com sucesso.');
    log('[WORKER] Iniciando loop de monitoramento de tarefas...');

    // Limitação simples de tamanho de logs (rotação básica)
    try {
      if (fs.existsSync(SERVICE_LOG)) {
        const stats = fs.statSync(SERVICE_LOG);
        if (stats.size > 5 * 1024 * 1024) { // 5MB
          log('[WORKER] Rotação básica de logs: limpando arquivo de logs principal.');
          fs.writeFileSync(SERVICE_LOG, `[${new Date().toISOString()}] Rotação de logs efetuada.\n`, 'utf8');
        }
      }
    } catch (_) {}

    // Loop contínuo
    let lastTaskId = null;
    let lastError = null;
    let lastTinySyncTime = 0;

    while (!shutdownRequested) {
      try {
        const now = Date.now();
        if (now - lastTinySyncTime > 15 * 60 * 1000) {
          lastTinySyncTime = now;
          log('[WORKER] Executando fila de fallback para Tiny ERP...');
          const childProcess = require('child_process');
          childProcess.exec('php scripts/sync-failed-tiny-orders.php', (err, stdout, stderr) => {
            if (err) {
              logError('[WORKER] Erro ao rodar sync de fallback do Tiny:', err);
            } else {
              log(`[WORKER] Resposta do Tiny sync: ${stdout.trim()}`);
            }
          });
        }
        orchestrator.queue.loadFromDisk(false);
        const status = orchestrator.getStatus();
        const pendingCount = status.queue_size;
        const runningCount = status.executing;

        // Atualizar heartbeat
        const heartbeat = {
          timestamp: new Date().toISOString(),
          status: 'running',
          pid: process.pid,
          provider: 'ollama',
          model: localModel,
          queue_pending: pendingCount,
          queue_running: runningCount,
          last_task_id: lastTaskId,
          last_error: lastError
        };

        fs.writeFileSync(HEARTBEAT_FILE, JSON.stringify(heartbeat, null, 2), 'utf8');

        // Processar tarefas pendentes
        if (pendingCount > 0 && runningCount < 1) {
          log(`[WORKER] Processando próxima tarefa da fila (${pendingCount} pendentes)...`);
          const result = await orchestrator.process();
          if (result) {
            if (result.type === 'EXECUTION_COMPLETE') {
              lastTaskId = result.task_id;
              if (result.success) {
                log(`[WORKER] Tarefa ${result.task_id.substring(0, 8)} concluída com sucesso (${result.model}).`);
              } else {
                lastError = result.result;
                logError(`[WORKER] Tarefa ${result.task_id.substring(0, 8)} falhou: ${result.result}`);
              }
            } else if (result.type === 'REJECTED') {
              lastTaskId = result.task_id;
              lastError = result.reason;
              log(`[WORKER] Tarefa ${result.task_id.substring(0, 8)} rejeitada: ${result.reason}`);
            }
          }
        }
      } catch (loopErr) {
        lastError = loopErr.message;
        logError('[WORKER] Erro interno no loop do worker:', loopErr);
      }

      // Intervalo de espera de 5 segundos
      await new Promise(r => setTimeout(r, 5000));
    }
  } catch (err) {
    logError('[WORKER] Fatal error ao rodar o worker:', err);
    handleShutdown();
  }
}

start();
