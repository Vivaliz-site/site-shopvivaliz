#!/usr/bin/env node

const http = require('http');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const CONFIG = {
  PORT: 5557,
  HOST: '0.0.0.0',
  API_KEY: 'sk-shopvivaliz-' + Math.random().toString(36).substr(2, 32),
  REPO_PATH: 'c:\\site-shopvivaliz',
  LOG_FILE: 'c:\\site-shopvivaliz\\.rce-full-logs.json',
  MAX_REQUESTS_PER_MINUTE: 60,
  COMMAND_TIMEOUT: 120
};

const requestLog = {};

function checkRateLimit(apiKey) {
  const now = Date.now();
  const oneMinuteAgo = now - 60000;

  if (!requestLog[apiKey]) {
    requestLog[apiKey] = [];
  }

  requestLog[apiKey] = requestLog[apiKey].filter(t => t > oneMinuteAgo);

  if (requestLog[apiKey].length >= CONFIG.MAX_REQUESTS_PER_MINUTE) {
    return false;
  }

  requestLog[apiKey].push(now);
  return true;
}

function logAction(apiKey, cmd, success, result) {
  try {
    const log = {
      timestamp: new Date().toISOString(),
      apiKey: apiKey.substr(0, 10) + '***',
      command: cmd,
      success,
      result: success ? result.substring(0, 500) : result
    };

    let logs = [];
    if (fs.existsSync(CONFIG.LOG_FILE)) {
      logs = JSON.parse(fs.readFileSync(CONFIG.LOG_FILE, 'utf8'));
    }
    logs.push(log);
    logs = logs.slice(-2000);
    fs.writeFileSync(CONFIG.LOG_FILE, JSON.stringify(logs, null, 2));
  } catch (e) {
    console.error('Erro ao fazer logging:', e.message);
  }
}

function executeCommand(cmd, timeout, workdir) {
  return new Promise((resolve) => {
    try {
      const cwd = workdir || CONFIG.REPO_PATH;

      const proc = spawn('cmd.exe', ['/c', cmd], {
        cwd: cwd,
        timeout: timeout * 1000,
        maxBuffer: 1024 * 1024 * 50,
        shell: true
      });

      let stdout = '';
      let stderr = '';

      proc.stdout.on('data', (data) => {
        stdout += data.toString();
      });

      proc.stderr.on('data', (data) => {
        stderr += data.toString();
      });

      proc.on('error', (error) => {
        resolve({
          success: false,
          error: error.message,
          returncode: -1
        });
      });

      proc.on('close', (code) => {
        resolve({
          success: code === 0 || code === null,
          stdout: stdout.trim(),
          stderr: stderr.trim(),
          returncode: code
        });
      });

      setTimeout(() => {
        proc.kill();
        resolve({
          success: false,
          error: 'Command timeout',
          returncode: -1
        });
      }, timeout * 1000 + 1000);

    } catch (e) {
      resolve({
        success: false,
        error: e.message,
        returncode: -1
      });
    }
  });
}

const server = http.createServer(async (req, res) => {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', '*');
  res.setHeader('Access-Control-Allow-Headers', '*');
  res.setHeader('Content-Type', 'application/json');

  if (req.url === '/health' && req.method === 'GET') {
    res.writeHead(200);
    res.end(JSON.stringify({ status: 'ok', timestamp: new Date().toISOString() }));
    return;
  }

  if (req.url === '/execute' && req.method === 'POST') {
    let body = '';

    req.on('data', chunk => {
      body += chunk.toString();
      if (body.length > 1e6) req.connection.destroy();
    });

    req.on('end', async () => {
      try {
        const { cmd, timeout = 30, apiKey } = JSON.parse(body);

        if (!apiKey || apiKey !== CONFIG.API_KEY) {
          res.writeHead(401);
          res.end(JSON.stringify({ error: 'Unauthorized' }));
          return;
        }

        if (!checkRateLimit(apiKey)) {
          res.writeHead(429);
          res.end(JSON.stringify({ error: 'Rate limit exceeded' }));
          return;
        }

        if (!cmd) {
          res.writeHead(400);
          res.end(JSON.stringify({ error: 'cmd parameter required' }));
          return;
        }

        const result = await executeCommand(cmd, Math.min(timeout, CONFIG.COMMAND_TIMEOUT));
        logAction(apiKey, cmd, result.success, result.stdout || result.error);

        res.writeHead(200);
        res.end(JSON.stringify(result));
      } catch (e) {
        res.writeHead(500);
        res.end(JSON.stringify({ error: e.message }));
      }
    });
    return;
  }

  if (req.url === '/logs' && req.method === 'GET') {
    try {
      const apiKey = req.headers['x-api-key'];
      if (!apiKey || apiKey !== CONFIG.API_KEY) {
        res.writeHead(401);
        res.end(JSON.stringify({ error: 'Unauthorized' }));
        return;
      }

      if (fs.existsSync(CONFIG.LOG_FILE)) {
        const logs = JSON.parse(fs.readFileSync(CONFIG.LOG_FILE, 'utf8'));
        res.writeHead(200);
        res.end(JSON.stringify(logs));
      } else {
        res.writeHead(200);
        res.end(JSON.stringify([]));
      }
    } catch (e) {
      res.writeHead(500);
      res.end(JSON.stringify({ error: e.message }));
    }
    return;
  }

  res.writeHead(404);
  res.end(JSON.stringify({ error: 'Not found' }));
});

server.listen(CONFIG.PORT, CONFIG.HOST, () => {
  console.log(`
╔════════════════════════════════════════════════════╗
║         🔴 RCE FULL SERVER INICIADO                ║
╚════════════════════════════════════════════════════╝

📍 URL: http://0.0.0.0:${CONFIG.PORT}
🔑 API Key: ${CONFIG.API_KEY}

📋 ENDPOINTS:
  GET  /health           → Status do servidor
  POST /execute          → Executar comando
  GET  /logs             → Ver logs (com X-API-Key header)

🚀 EXEMPLO:
  curl -X POST http://localhost:${CONFIG.PORT}/execute \\
    -H "Content-Type: application/json" \\
    -d '{
      "cmd": "git status",
      "timeout": 30,
      "apiKey": "${CONFIG.API_KEY}"
    }'

✅ Servidor pronto!
  `);
});

process.on('SIGINT', () => {
  console.log('🛑 Servidor encerrando...');
  server.close(() => process.exit(0));
});
