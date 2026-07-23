#!/usr/bin/env node
/**
 * ⚠️ SERVIDOR RCE COMPLETO - SEM RESTRIÇÕES
 *
 * AVISO DE SEGURANÇA CRÍTICO:
 * - Executa QUALQUER comando sem validação
 * - Se token vazar → Controle total do PC
 * - Use APENAS em rede local privada
 * - NÃO em redes públicas ou internet aberta
 *
 * Responsabilidade: do usuário que ativou isto
 */

const http = require('http');
const { execSync, spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const PORT = parseInt(process.env.PORT) || 5557;
const BIND_IP = process.env.BIND_IP || '127.0.0.1';
const TOKEN = process.env.COMMAND_SERVER_TOKEN || 'hBu-3gs3meFOp82AnXLzljmIvNaf-7ih';
const LOG_FILE = path.join(__dirname, 'logs', 'rce-server.log');

// Criar pasta de logs
if (!fs.existsSync(path.dirname(LOG_FILE))) {
  fs.mkdirSync(path.dirname(LOG_FILE), { recursive: true });
}

// Rate limiting mínimo
const rateLimitMap = new Map();
const RATE_LIMIT_MS = 500; // 0.5s entre reqs

function log(level, message) {
  const timestamp = new Date().toISOString();
  const logMessage = `[${timestamp}] [${level}] ${message}\n`;
  console.error(logMessage);
  fs.appendFileSync(LOG_FILE, logMessage);
}

function checkRateLimit(ip) {
  const now = Date.now();
  const lastReq = rateLimitMap.get(ip) || 0;

  if (now - lastReq < RATE_LIMIT_MS) {
    return false;
  }

  rateLimitMap.set(ip, now);
  return true;
}

const server = http.createServer((req, res) => {
  const ip = req.socket.remoteAddress;

  // Validar token
  const authHeader = req.headers.authorization;
  if (!authHeader || !authHeader.startsWith('Bearer ')) {
    log('WARN', `❌ Requisição sem token de ${ip}`);
    res.writeHead(401, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: 'Unauthorized' }));
    return;
  }

  const token = authHeader.slice(7);
  if (token !== TOKEN) {
    log('WARN', `❌ Token inválido de ${ip}`);
    res.writeHead(401, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: 'Unauthorized' }));
    return;
  }

  // Rate limiting
  if (!checkRateLimit(ip)) {
    log('WARN', `🚫 Rate limit de ${ip}`);
    res.writeHead(429, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: 'Too many requests' }));
    return;
  }

  // ==================== EXECUTE ENDPOINT ====================
  if (req.method === 'POST' && req.url === '/execute') {
    let body = '';

    req.on('data', (chunk) => {
      if (body.length > 50000) { // Max 50KB
        req.destroy();
        return;
      }
      body += chunk;
    });

    req.on('end', () => {
      try {
        const { cmd, timeout = 30, shell = 'cmd' } = JSON.parse(body);

        if (!cmd || typeof cmd !== 'string') {
          res.writeHead(400, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({ error: 'cmd é obrigatório' }));
          return;
        }

        log('EXEC', `🔴 COMANDO: ${cmd} (timeout: ${timeout}s, shell: ${shell}) de ${ip}`);

        try {
          const stdout = execSync(cmd, {
            cwd: 'c:\\site-shopvivaliz',
            timeout: Math.min(timeout, 300) * 1000, // Max 5 min
            encoding: 'utf-8',
            maxBuffer: 10 * 1024 * 1024, // 10MB max output
            shell: shell === 'powershell' ? 'powershell.exe' : true,
          });

          log('EXEC', `✅ Sucesso: ${cmd}`);
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            status: 'success',
            output: stdout,
            timestamp: new Date().toISOString(),
          }));
        } catch (execError) {
          log('ERROR', `❌ Erro: ${cmd} → ${execError.message}`);
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            status: 'error',
            message: execError.message,
            stderr: execError.stderr?.toString() || '',
            code: execError.status,
          }));
        }
      } catch (parseError) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'JSON inválido' }));
      }
    });
  }
  // ==================== OPEN TERMINAL ====================
  else if (req.method === 'POST' && req.url === '/open-terminal') {
    try {
      const { type = 'cmd' } = JSON.parse(req.headers['content-length'] ? '' : '{}');

      if (type === 'powershell') {
        spawn('powershell.exe', { detached: true, stdio: 'ignore' });
        log('OPEN', `📟 PowerShell aberto`);
      } else if (type === 'cmd') {
        spawn('cmd.exe', { detached: true, stdio: 'ignore' });
        log('OPEN', `📟 CMD aberto`);
      }

      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ status: 'Terminal aberto' }));
    } catch (e) {
      res.writeHead(500, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: e.message }));
    }
  }
  // ==================== OPEN BROWSER ====================
  else if (req.method === 'POST' && req.url === '/open-browser') {
    try {
      let body = '';
      req.on('data', chunk => body += chunk);
      req.on('end', () => {
        const { url = 'https://www.google.com' } = JSON.parse(body);
        execSync(`start "${url}"`, { stdio: 'ignore' });
        log('OPEN', `🌐 Navegador: ${url}`);
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ status: 'Navegador aberto', url }));
      });
    } catch (e) {
      res.writeHead(500, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: e.message }));
    }
  }
  // ==================== OPEN APP ====================
  else if (req.method === 'POST' && req.url === '/open-app') {
    try {
      let body = '';
      req.on('data', chunk => body += chunk);
      req.on('end', () => {
        const { app } = JSON.parse(body);
        execSync(`start "" "${app}"`, { stdio: 'ignore' });
        log('OPEN', `🖥️ App aberto: ${app}`);
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ status: 'App aberto', app }));
      });
    } catch (e) {
      res.writeHead(500, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: e.message }));
    }
  }
  // ==================== STATUS ====================
  else if (req.method === 'GET' && req.url === '/status') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      status: '🔴 RCE ATIVO - SEM RESTRIÇÕES',
      mode: 'FULL_ACCESS',
      token_required: true,
      timestamp: new Date().toISOString(),
      endpoints: {
        'POST /execute': 'Executar qualquer comando',
        'POST /open-terminal': 'Abrir terminal (cmd ou powershell)',
        'POST /open-browser': 'Abrir navegador em URL',
        'POST /open-app': 'Abrir aplicativo',
        'GET /status': 'Ver status do servidor',
      },
    }));
  }
  // ==================== 404 ====================
  else {
    res.writeHead(404, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: 'Endpoint não encontrado' }));
  }
});

server.listen(PORT, BIND_IP, () => {
  log('INIT', '═══════════════════════════════════════════════════');
  log('INIT', '⚠️  RCE SERVER - SEM PROTEÇÃO - USO LOCAL APENAS');
  log('INIT', '═══════════════════════════════════════════════════');
  log('INIT', `✅ Servidor rodando em http://${BIND_IP}:${PORT}`);
  log('INIT', `🔑 Token: ${TOKEN}`);
  log('INIT', `📝 Log: ${LOG_FILE}`);
  log('INIT', '');
  log('INIT', '📡 ENDPOINTS:');
  log('INIT', `  POST /execute        → executar qualquer comando`);
  log('INIT', `  POST /open-terminal  → abrir cmd ou powershell`);
  log('INIT', `  POST /open-browser   → abrir navegador`);
  log('INIT', `  POST /open-app       → abrir app`);
  log('INIT', `  GET  /status         → status do servidor`);
  log('INIT', '');

  console.log(`
╔══════════════════════════════════════════════════════════════╗
║                🔴 RCE SERVER ATIVADO                        ║
║                 SEM RESTRIÇÕES - ACESSO TOTAL               ║
╚══════════════════════════════════════════════════════════════╝

📍 URL: http://${BIND_IP}:${PORT}
🔑 Token: ${TOKEN}

⚠️  AVISOS:
  1. Este servidor executa QUALQUER comando
  2. Se o token vazar → Controle total do PC
  3. Use APENAS em rede WiFi local privada
  4. NUNCA em redes públicas
  5. NUNCA exponha na internet

🚀 EXEMPLOS DE USO:

  # Executar comando
  curl -X POST http://localhost:${PORT}/execute \\
    -H "Authorization: Bearer ${TOKEN}" \\
    -d '{"cmd": "dir", "timeout": 30}'

  # Abrir terminal
  curl -X POST http://localhost:${PORT}/open-terminal \\
    -H "Authorization: Bearer ${TOKEN}" \\
    -d '{"type": "powershell"}'

  # Abrir navegador
  curl -X POST http://localhost:${PORT}/open-browser \\
    -H "Authorization: Bearer ${TOKEN}" \\
    -d '{"url": "https://github.com"}'

  # Abrir app
  curl -X POST http://localhost:${PORT}/open-app \\
    -H "Authorization: Bearer ${TOKEN}" \\
    -d '{"app": "C:\\\\Program Files\\\\VSCode\\\\Code.exe"}'

📱 DO IPHONE (Shortcuts):
  POST http://192.168.x.x:${PORT}/execute
  Header: Authorization: Bearer ${TOKEN}
  Body: {"cmd": "dir", "timeout": 30}

📋 LOGS: logs/rce-server.log

`);
});

process.on('SIGINT', () => {
  log('SHUTDOWN', 'Servidor encerrando...');
  server.close(() => {
    log('SHUTDOWN', 'Servidor parado');
    process.exit(0);
  });
});
