#!/usr/bin/env node

/**
 * 🌐 Server - API e Dashboard
 *
 * Arquivo: .ai/server.js
 */

const http = require('http');
const fs = require('fs');
const path = require('path');
const url = require('url');

// Custom .env loader
function loadEnv() {
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
}
loadEnv();

const { Orchestrator } = require('./orchestrator');

const PORT = process.env.PORT || 3000;
const HOST = '127.0.0.1'; // Escuta estritamente local
const LOGS_DIR = path.join(__dirname, '../logs');
const PID_FILE = path.join(LOGS_DIR, 'local-ai-server.pid');
const SERVICE_LOG = path.join(LOGS_DIR, 'local-ai-service.log');

// Certificar diretório de logs
if (!fs.existsSync(LOGS_DIR)) {
  fs.mkdirSync(LOGS_DIR, { recursive: true });
}

// PID Lock para o servidor
if (fs.existsSync(PID_FILE)) {
  try {
    const existingPid = parseInt(fs.readFileSync(PID_FILE, 'utf8').trim());
    if (existingPid) {
      try {
        process.kill(existingPid, 0);
        console.error(`[SERVER] Outro servidor já está rodando com PID: ${existingPid}. Encerrando.`);
        process.exit(1);
      } catch (e) {
        fs.unlinkSync(PID_FILE);
      }
    }
  } catch (_) {}
}
fs.writeFileSync(PID_FILE, process.pid.toString(), 'utf8');

// Inicializar orquestrador (irá carregar a fila persistente de tasks.jsonl automaticamente)
const orchestrator = new Orchestrator();

const server = http.createServer((req, res) => {
  const parsedUrl = url.parse(req.url, true);
  const pathname = parsedUrl.pathname;
  const query = parsedUrl.query;

  // CORS (Apenas para loopback local)
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    res.end();
    return;
  }

  // Dashboard
  if (pathname === '/' && req.method === 'GET') {
    const dashboardPath = path.join(__dirname, 'dashboard.html');
    fs.readFile(dashboardPath, (err, data) => {
      if (err) {
        res.writeHead(500, { 'Content-Type': 'text/plain' });
        res.end('Erro ao carregar dashboard');
        return;
      }
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      res.end(data);
    });
    return;
  }

  // GET /api/health
  if (pathname === '/api/health' && req.method === 'GET') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      status: 'ok',
      timestamp: new Date().toISOString(),
      uptime: process.uptime(),
      memory: process.memoryUsage(),
      pid: process.pid
    }, null, 2));
    return;
  }

  // GET /api/status
  if (pathname === '/api/status' && req.method === 'GET') {
    orchestrator.queue.loadFromDisk(false);
    const status = orchestrator.getStatus();
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(status, null, 2));
    return;
  }

  // GET /api/tasks
  if (pathname === '/api/tasks' && req.method === 'GET') {
    orchestrator.queue.loadFromDisk(false);
    const tasks = Array.from(orchestrator.queue.tasks.values());
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(tasks, null, 2));
    return;
  }

  // POST /api/tasks (Submeter tarefa)
  if (pathname === '/api/tasks' && req.method === 'POST') {
    let body = '';
    req.on('data', chunk => {
      body += chunk.toString();
    });

    req.on('end', () => {
      try {
        orchestrator.queue.loadFromDisk(false);
        const task = JSON.parse(body);
        const task_id = orchestrator.submit(task);

        res.writeHead(201, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ task_id, status: 'submitted' }));
      } catch (error) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: error.message }));
      }
    });
    return;
  }

  // GET /api/tasks/:id
  const taskMatch = pathname.match(/^\/api\/tasks\/([a-f0-9-]+)$/);
  if (taskMatch && req.method === 'GET') {
    const taskId = taskMatch[1];
    orchestrator.queue.loadFromDisk(false);
    const task = orchestrator.queue.get(taskId);
    if (!task) {
      res.writeHead(404, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: `Task ${taskId} not found` }));
      return;
    }
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(task, null, 2));
    return;
  }

  // POST /api/tasks/:id/cancel
  const cancelMatch = pathname.match(/^\/api\/tasks\/([a-f0-9-]+)\/cancel$/);
  if (cancelMatch && req.method === 'POST') {
    const taskId = cancelMatch[1];
    orchestrator.queue.loadFromDisk(false);
    const task = orchestrator.queue.get(taskId);
    if (!task) {
      res.writeHead(404, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: `Task ${taskId} not found` }));
      return;
    }

    if (task.status === 'completed' || task.status === 'failed' || task.status === 'cancelled') {
      res.writeHead(400, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: `Task is already in final state: ${task.status}` }));
      return;
    }

    orchestrator.queue.update(taskId, {
      status: 'cancelled',
      finished_at: new Date().toISOString(),
      error: 'Cancelled by user command'
    });

    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ task_id: taskId, status: 'cancelled' }));
    return;
  }

  // GET /api/tasks/history
  if (pathname === '/api/tasks/history' && req.method === 'GET') {
    const limit = parseInt(query.limit) || 50;
    const history = orchestrator.getExecutionHistory(limit);
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(history, null, 2));
    return;
  }

  // GET /api/logs
  if (pathname === '/api/logs' && req.method === 'GET') {
    const lines = parseInt(query.lines) || 50;
    try {
      if (fs.existsSync(SERVICE_LOG)) {
        const content = fs.readFileSync(SERVICE_LOG, 'utf8');
        const allLines = content.split(/\r?\n/);
        const sliced = allLines.slice(-lines).join('\n');
        res.writeHead(200, { 'Content-Type': 'text/plain; charset=utf-8' });
        res.end(sliced);
      } else {
        res.writeHead(404, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Service log not found' }));
      }
    } catch (err) {
      res.writeHead(500, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: err.message }));
    }
    return;
  }

  // Legacy POST /api/process
  if (pathname === '/api/process' && req.method === 'POST') {
    orchestrator.process().then(result => {
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify(result || { message: 'Nenhuma tarefa na fila' }));
    }).catch(error => {
      res.writeHead(500, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: error.message }));
    });
    return;
  }

  // Legacy POST /api/approve
  if (pathname === '/api/approve' && req.method === 'POST') {
    let body = '';
    req.on('data', chunk => {
      body += chunk.toString();
    });

    req.on('end', () => {
      try {
        const { approval_id, approved_by } = JSON.parse(body);
        orchestrator.approve(approval_id, approved_by).then(result => {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify(result));
        });
      } catch (error) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: error.message }));
      }
    });
    return;
  }

  // 404
  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ error: 'Endpoint não encontrado' }));
});

server.listen(PORT, HOST, () => {
  console.log('════════════════════════════════════════════════════════════════════════════');
  console.log(`🌐 Hybrid AI Dashboard Server - PID: ${process.pid}`);
  console.log('════════════════════════════════════════════════════════════════════════════');
  console.log('');
  console.log(`   Escutando estritamente em: http://${HOST}:${PORT}`);
  console.log(`   Dashboard:  http://${HOST}:${PORT}`);
  console.log(`   API Status: http://${HOST}:${PORT}/api/status`);
  console.log('');
  console.log('════════════════════════════════════════════════════════════════════════════');
});

// Graceful shutdown
process.on('SIGINT', () => {
  console.log('\n✓ Servidor encerrado');
  try {
    if (fs.existsSync(PID_FILE)) {
      fs.unlinkSync(PID_FILE);
    }
  } catch (_) {}
  process.exit(0);
});
process.on('SIGTERM', () => {
  try {
    if (fs.existsSync(PID_FILE)) {
      fs.unlinkSync(PID_FILE);
    }
  } catch (_) {}
  process.exit(0);
});
