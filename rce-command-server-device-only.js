#!/usr/bin/env node
/**
 * 🔓 RCE SERVER - ACESSO COMPLETO
 *
 * Apenas SEU iPhone consegue acessar (via Device ID)
 * Sem token, sem restrições de comando
 * Device ID é a ÚNICA proteção
 */

const http = require('http');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const PORT = parseInt(process.env.PORT) || 5557;
const BIND_IP = process.env.BIND_IP || '127.0.0.1';
const LOG_FILE = path.join(__dirname, 'logs', 'rce-server.log');

// 📱 DEVICE ID DO SEU IPHONE (ÚNICA PROTEÇÃO)
const YOUR_DEVICE_ID = 'iphone-3cc2c19459524e3cb79d7bdfaa1b456a';

// Criar pasta de logs
if (!fs.existsSync(path.dirname(LOG_FILE))) {
  fs.mkdirSync(path.dirname(LOG_FILE), { recursive: true });
}

// Rate limiting
const rateLimitMap = new Map();
const RATE_LIMIT_MS = 500;

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

// ============= AUTENTICAÇÃO (DEVICE ID APENAS) =============

function validateAuth(req) {
  const ip = req.socket.remoteAddress;

  // Validar Device ID (ÚNICA AUTENTICAÇÃO)
  const deviceHeader = req.headers['x-device-id'];
  if (!deviceHeader) {
    log('WARN', `❌ Device ID faltando de ${ip}`);
    return { valid: false, reason: 'Missing X-Device-ID header' };
  }

  if (deviceHeader !== YOUR_DEVICE_ID) {
    log('WARN', `⛔ Device ID incorreto de ${ip}: ${deviceHeader}`);
    return { valid: false, reason: 'Device ID not authorized' };
  }

  log('INFO', `✅ iPhone autorizado de ${ip}`);
  return { valid: true };
}

// ============= SERVIDOR =============

const server = http.createServer((req, res) => {
  const ip = req.socket.remoteAddress;

  // Rate limiting
  if (!checkRateLimit(ip)) {
    res.writeHead(429, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: 'Too many requests' }));
    return;
  }

  // Autenticação (Device ID apenas)
  const auth = validateAuth(req);
  if (!auth.valid) {
    res.writeHead(401, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: auth.reason }));
    return;
  }

  // ==================== EXECUTE ====================
  if (req.method === 'POST' && req.url === '/execute') {
    let body = '';

    req.on('data', (chunk) => {
      if (body.length > 50000) {
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

        log('EXEC', `🔴 iPhone: ${cmd} (timeout: ${timeout}s)`);

        try {
          const stdout = execSync(cmd, {
            cwd: 'c:\\site-shopvivaliz',
            timeout: Math.min(timeout, 300) * 1000,
            encoding: 'utf-8',
            maxBuffer: 10 * 1024 * 1024,
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
        require('child_process').spawn('powershell.exe', { detached: true, stdio: 'ignore' });
        log('OPEN', `📟 PowerShell aberto`);
      } else if (type === 'cmd') {
        require('child_process').spawn('cmd.exe', { detached: true, stdio: 'ignore' });
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
        log('OPEN', `🖥️ App: ${app}`);
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
      status: '🔓 RCE COMPLETO - APENAS DEVICE ID',
      mode: 'FULL_ACCESS_DEVICE_ONLY',
      timestamp: new Date().toISOString(),
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
  log('INIT', '🔓 RCE SERVER - DEVICE ID ONLY - SEM RESTRIÇÕES');
  log('INIT', '═══════════════════════════════════════════════════');
  log('INIT', `✅ Servidor rodando em http://${BIND_IP}:${PORT}`);
  log('INIT', `📱 Device ID Autorizado: ${YOUR_DEVICE_ID}`);
  log('INIT', '');

  console.log(`
╔══════════════════════════════════════════════════════════════════╗
║          🔓 RCE SERVER - ACESSO COMPLETO                        ║
║              Apenas seu iPhone consegue acessar                  ║
║                   (via Device ID único)                          ║
╚══════════════════════════════════════════════════════════════════╝

📍 URL: http://${BIND_IP}:${PORT}
📱 Device ID Autorizado: ${YOUR_DEVICE_ID}

🚀 COMO USAR DO IPHONE:

  Header ÚNICO requerido:
  X-Device-ID: ${YOUR_DEVICE_ID}

  Exemplo com curl:
  curl -X POST https://rce-shopvivaliz.trycloudflare.com/execute \\
    -H "X-Device-ID: ${YOUR_DEVICE_ID}" \\
    -H "Content-Type: application/json" \\
    -d '{"cmd": "dir", "timeout": 30}'

📋 ENDPOINTS:
  POST /execute → Executar qualquer comando
  POST /open-terminal → Abrir Terminal
  POST /open-browser → Abrir Navegador
  POST /open-app → Abrir App
  GET /status → Ver status

`);
});

process.on('SIGINT', () => {
  log('SHUTDOWN', 'Servidor encerrando...');
  server.close(() => {
    log('SHUTDOWN', 'Servidor parado');
    process.exit(0);
  });
});
