#!/usr/bin/env node
/**
 * ⚠️ SERVIDOR RCE COM AUTENTICAÇÃO POR DEVICE
 *
 * Apenas UM device (iPhone) consegue acessar
 * Mesmo que roube o token, precisa do Device ID também
 */

const http = require('http');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const PORT = parseInt(process.env.PORT) || 5557;
const BIND_IP = process.env.BIND_IP || '127.0.0.1';
const TOKEN = process.env.COMMAND_SERVER_TOKEN || 'hBu-3gs3meFOp82AnXLzljmIvNaf-7ih';
const LOG_FILE = path.join(__dirname, 'logs', 'rce-server.log');

// 📱 WHITELIST DE DEVICES PERMITIDOS
// Formato: "Device Name" → "Device ID"
const ALLOWED_DEVICES = {
  "iPhone Pessoal": "iphone-3cc2c19459524e3cb79d7bdfaa1b456a",
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

// ============= AUTENTICAÇÃO 2-CAMADAS =============

function validateAuth(req) {
  const ip = req.socket.remoteAddress;

  // 1️⃣ Validar Token Bearer
  const authHeader = req.headers.authorization;
  if (!authHeader || !authHeader.startsWith('Bearer ')) {
    log('WARN', `❌ Token faltando de ${ip}`);
    return { valid: false, reason: 'Missing Bearer token' };
  }

  const token = authHeader.slice(7);
  if (token !== TOKEN) {
    log('WARN', `❌ Token inválido de ${ip}`);
    return { valid: false, reason: 'Invalid token' };
  }

  // 2️⃣ Validar Device ID
  const deviceHeader = req.headers['x-device-id'];
  if (!deviceHeader) {
    log('WARN', `❌ Device ID faltando de ${ip}`);
    return { valid: false, reason: 'Missing X-Device-ID header' };
  }

  // Verificar se device está whitelisted
  let deviceName = null;
  let allowed = false;

  for (const [name, deviceId] of Object.entries(ALLOWED_DEVICES)) {
    if (deviceId === deviceHeader) {
      allowed = true;
      deviceName = name;
      break;
    }
  }

  if (!allowed) {
    log('WARN', `⛔ Device não autorizado: ${deviceHeader} de ${ip}`);
    return { valid: false, reason: 'Device not authorized' };
  }

  log('INFO', `✅ Autenticado: ${deviceName} (${deviceHeader}) de ${ip}`);
  return { valid: true, deviceName, deviceId: deviceHeader };
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

  // Autenticação
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

        log('EXEC', `🔴 ${auth.deviceName}: ${cmd} (timeout: ${timeout}s)`);

        try {
          const stdout = execSync(cmd, {
            cwd: 'c:\\site-shopvivaliz',
            timeout: Math.min(timeout, 300) * 1000,
            encoding: 'utf-8',
            maxBuffer: 10 * 1024 * 1024,
            shell: shell === 'powershell' ? 'powershell.exe' : true,
          });

          log('EXEC', `✅ ${auth.deviceName}: Sucesso`);
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            status: 'success',
            output: stdout,
            device: auth.deviceName,
            timestamp: new Date().toISOString(),
          }));
        } catch (execError) {
          log('ERROR', `❌ ${auth.deviceName}: ${cmd} → ${execError.message}`);
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            status: 'error',
            message: execError.message,
            stderr: execError.stderr?.toString() || '',
            device: auth.deviceName,
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
        log('OPEN', `📟 ${auth.deviceName}: PowerShell aberto`);
      } else if (type === 'cmd') {
        require('child_process').spawn('cmd.exe', { detached: true, stdio: 'ignore' });
        log('OPEN', `📟 ${auth.deviceName}: CMD aberto`);
      }

      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ status: 'Terminal aberto', device: auth.deviceName }));
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
        log('OPEN', `🌐 ${auth.deviceName}: ${url}`);
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ status: 'Navegador aberto', url, device: auth.deviceName }));
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
        log('OPEN', `🖥️ ${auth.deviceName}: ${app}`);
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ status: 'App aberto', app, device: auth.deviceName }));
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
      status: '🔴 RCE ATIVO - COM DEVICE AUTH',
      mode: 'FULL_ACCESS_DEVICE_AUTH',
      device: auth.deviceName,
      timestamp: new Date().toISOString(),
    }));
  }
  // ==================== GERAR DEVICE ID ====================
  else if (req.method === 'GET' && req.url === '/generate-device-id') {
    const deviceId = `iphone-${crypto.randomBytes(16).toString('hex')}`;
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      device_id: deviceId,
      instruction: `Copie esse ID e adicione ao seu iPhone (X-Device-ID header)`
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
  log('INIT', '🔐 RCE SERVER COM DEVICE AUTHENTICATION ATIVO');
  log('INIT', '═══════════════════════════════════════════════════');
  log('INIT', `✅ Servidor rodando em http://${BIND_IP}:${PORT}`);
  log('INIT', `🔑 Token: ${TOKEN}`);
  log('INIT', '');
  log('INIT', '📱 DEVICES AUTORIZADOS:');

  for (const [name, deviceId] of Object.entries(ALLOWED_DEVICES)) {
    log('INIT', `   ✓ ${name}: ${deviceId}`);
  }

  log('INIT', '');

  console.log(`
╔══════════════════════════════════════════════════════════════════╗
║        🔐 RCE SERVER COM DEVICE AUTHENTICATION                  ║
║                                                                  ║
║  Apenas devices autorizados conseguem executar comandos         ║
╚══════════════════════════════════════════════════════════════════╝

📍 URL: http://${BIND_IP}:${PORT}
🔑 Token: ${TOKEN}

📱 DEVICES AUTORIZADOS:
${Array.from(Object.entries(ALLOWED_DEVICES))
  .map(([name, id]) => `   ✓ ${name}\n      Device ID: ${id}`)
  .join('\n')}

🚀 COMO USAR DO IPHONE:

  Header requerido:
  X-Device-ID: [seu-device-id]

  Exemplo com curl:
  curl -X POST http://localhost:5557/execute \\
    -H "Authorization: Bearer ${TOKEN}" \\
    -H "X-Device-ID: iphone-device-id-unique-8392847" \\
    -d '{"cmd": "dir", "timeout": 30}'

  Gerar novo Device ID:
  curl http://127.0.0.1:5557/generate-device-id

`);
});

process.on('SIGINT', () => {
  log('SHUTDOWN', 'Servidor encerrando...');
  server.close(() => {
    log('SHUTDOWN', 'Servidor parado');
    process.exit(0);
  });
});

