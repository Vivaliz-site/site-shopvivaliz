#!/usr/bin/env node

/**
 * 🌐 Server - API e Dashboard
 *
 * Serve:
 * - Dashboard HTML
 * - API REST para orchestrator
 * - WebSocket para tempo real
 *
 * Arquivo: .ai/server.js
 */

const http = require('http');
const fs = require('fs');
const path = require('path');
const url = require('url');
const { Orchestrator } = require('./orchestrator');
const { AgentManager } = require('./agents');

const PORT = process.env.PORT || 3000;

// Inicializar
const orchestrator = new Orchestrator();
const agent_manager = new AgentManager();

const server = http.createServer((req, res) => {
  const parsedUrl = url.parse(req.url, true);
  const pathname = parsedUrl.pathname;
  const query = parsedUrl.query;

  // CORS
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    res.end();
    return;
  }

  // Roteamento
  if (pathname === '/' && req.method === 'GET') {
    // Dashboard
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

  // API: Status
  if (pathname === '/api/status' && req.method === 'GET') {
    const status = orchestrator.getStatus();
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(status, null, 2));
    return;
  }

  // API: Submeter tarefa
  if (pathname === '/api/tasks' && req.method === 'POST') {
    let body = '';
    req.on('data', chunk => {
      body += chunk.toString();
    });

    req.on('end', () => {
      try {
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

  // API: Histórico de tarefas
  if (pathname === '/api/tasks/history' && req.method === 'GET') {
    const limit = parseInt(query.limit) || 50;
    const history = orchestrator.getExecutionHistory(limit);
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(history, null, 2));
    return;
  }

  // API: Status dos agentes
  if (pathname === '/api/agents' && req.method === 'GET') {
    const agents_status = agent_manager.getAgentsStatus();
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(agents_status, null, 2));
    return;
  }

  // API: Custo
  if (pathname === '/api/costs' && req.method === 'GET') {
    const cost_report = orchestrator.router.getCostReport();
    const agents_cost = agent_manager.getTotalCost();
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      orchestrator: cost_report,
      agents_total: agents_cost,
      combined_total: cost_report.summary.daily_used + agents_cost
    }, null, 2));
    return;
  }

  // API: Processar próxima tarefa
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

  // API: Aprovar tarefa
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

  // API: Health check
  if (pathname === '/api/health' && req.method === 'GET') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      status: 'ok',
      timestamp: new Date().toISOString(),
      uptime: process.uptime(),
      memory: process.memoryUsage()
    }));
    return;
  }

  // 404
  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ error: 'Endpoint não encontrado' }));
});

server.listen(PORT, () => {
  console.log('════════════════════════════════════════════════════════════════════════════');
  console.log(`🌐 Hybrid AI Dashboard Server`);
  console.log('════════════════════════════════════════════════════════════════════════════');
  console.log('');
  console.log(`   Dashboard:  http://localhost:${PORT}`);
  console.log(`   API Status: http://localhost:${PORT}/api/status`);
  console.log(`   API Docs:   GET  /api/status`);
  console.log(`              GET  /api/agents`);
  console.log(`              GET  /api/tasks/history`);
  console.log(`              GET  /api/costs`);
  console.log(`              POST /api/tasks (submit)`);
  console.log(`              POST /api/process`);
  console.log(`              POST /api/approve`);
  console.log('');
  console.log('════════════════════════════════════════════════════════════════════════════');
  console.log('');
});

// Graceful shutdown
process.on('SIGINT', () => {
  console.log('\n✓ Servidor encerrado');
  process.exit(0);
});
