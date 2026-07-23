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

// 📱 IDENTIFICADORES AUTORIZADOS (QUALQUER UM FUNCIONA)
const AUTHORIZED_IDS = {
  'imei-vivo': '356935402541129',           // IMEI Vivo (4G, WiFi)
  'imei-claro': '356935402400383',          // IMEI2 Claro (4G, WiFi)
  'mac-wifi': 'B8:01:1F:42:B1:78',          // MAC Address WiFi
  'serial': 'FR924W3X26',                   // Serial Number
  'device-id': 'iphone-3cc2c19459524e3cb79d7bdfaa1b456a' // Device ID
};

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

// ============= AUTENTICAÇÃO (QUALQUER IDENTIFICADOR AUTORIZADO) =============

function validateAuth(req) {
  const ip = req.socket.remoteAddress;

  // Tentar qualquer identificador (IMEI, MAC, Serial, Device ID)
  const headers = ['x-imei', 'x-mac', 'x-serial', 'x-device-id', 'x-identifier'];

  for (const headerName of headers) {
    const headerValue = req.headers[headerName];

    if (!headerValue) continue;

    // Verificar se está na whitelist
    for (const [type, authorizedValue] of Object.entries(AUTHORIZED_IDS)) {
      if (headerValue === authorizedValue || headerValue.toUpperCase() === authorizedValue.toUpperCase()) {
        log('INFO', `✅ iPhone autorizado (${type}): ${ip}`);
        return { valid: true, type, value: headerValue };
      }
    }
  }

  // Se chegou aqui, nenhum identificador válido
  const providedId = req.headers['x-imei'] || req.headers['x-mac'] || req.headers['x-device-id'] || 'nenhum';
  log('WARN', `⛔ Identificador não autorizado de ${ip}: ${providedId}`);
  return { valid: false, reason: 'No valid identifier. Use: X-IMEI, X-MAC, X-Serial, or X-Device-ID' };
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
