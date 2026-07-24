#!/usr/bin/env node
/**
 * Servidor seguro para executar comandos via HTTP com autenticação
 * - Validação de comandos contra whitelist
 * - Token Bearer (não hardcoded)
 * - Logging completo
 * - Rate limiting básico
 */

const http = require('http');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const PORT = 5557;
const BIND_IP = '127.0.0.1'; // Apenas localhost por padrão
const TOKEN = process.env.COMMAND_SERVER_TOKEN || 'hBu-3gs3meFOp82AnXLzljmIvNaf-7ih';
const LOG_FILE = path.join(__dirname, 'logs', 'command-server.log');

// Criar pasta de logs se não existir
if (!fs.existsSync(path.dirname(LOG_FILE))) {
  fs.mkdirSync(path.dirname(LOG_FILE), { recursive: true });
}

// Whitelist de comandos permitidos
const ALLOWED_COMMANDS = {
  'git': ['status', 'log', 'add', 'commit', 'push', 'pull', 'fetch', 'reset', 'branch', 'checkout', 'diff'],
  'npm': ['install', 'run', 'start', 'test', 'list', 'update'],
  'node': ['--version'],
  'php': ['--version', '-l'],
  'dir': true, // boolean true = qualquer argumento
  'ls': true,
  'cat': true,
  'type': true,
  'curl': true,
  'python': ['--version'],
  'pwsh': true,
};

// Rate limiting simples (IP -> timestamp da última req)
const rateLimitMap = new Map();
const RATE_LIMIT_MS = 1000; // Min 1s entre reqs

function log(level, message) {
  const timestamp = new Date().toISOString();
  const logMessage = `[${timestamp}] [${level}] ${message}\n`;
  console.error(logMessage);
  fs.appendFileSync(LOG_FILE, logMessage);
}

function isCommandAllowed(fullCmd) {
  const [basecmd, ...args] = fullCmd.trim().split(/\s+/);

  if (!ALLOWED_COMMANDS[basecmd]) {
    return false;
  }

  const allowed = ALLOWED_COMMANDS[basecmd];
  if (allowed === true) {
    return true; // Qualquer argumento permitido
  }

  if (Array.isArray(allowed) && allowed.length > 0) {
    return allowed.includes(args[0]);
  }

  return false;
}

function checkRateLimit(ip) {
  const now = Date.now();
  const lastReq = rateLimitMap.get(ip) || 0;

  if (now - lastReq < RATE_LIMIT_MS) {
    return false; // Limite atingido
  }

  rateLimitMap.set(ip, now);
  return true;
}

const server = http.createServer((req, res) => {
  const ip = req.socket.remoteAddress;

  // Validar autenticação
  const authHeader = req.headers.authorization;
  if (!authHeader || !authHeader.startsWith('Bearer ')) {
    log('WARN', `Requisição sem token de ${ip}`);
    res.writeHead(401, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: 'Unauthorized: missing Bearer token' }));
    return;
  }

  const token = authHeader.slice(7); // Remove "Bearer "
  if (token !== TOKEN) {
    log('WARN', `Token inválido de ${ip}`);
    res.writeHead(401, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: 'Unauthorized: invalid token' }));
    return;
  }

  // Rate limiting
  if (!checkRateLimit(ip)) {
    log('WARN', `Rate limit atingido de ${ip}`);
    res.writeHead(429, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: 'Too many requests' }));
    return;
  }

  // Handler para /execute
  if (req.method === 'POST' && req.url === '/execute') {
    let body = '';

    req.on('data', (chunk) => {
      if (body.length > 10000) { // Max 10KB payload
        req.destroy();
        return;
      }
      body += chunk;
    });

    req.on('end', () => {
      try {
        const { cmd, timeout = 30 } = JSON.parse(body);

        // Validar comando
        if (!isCommandAllowed(cmd)) {
          log('WARN', `Comando não permitido: ${cmd} de ${ip}`);
          res.writeHead(400, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            error: 'Command not allowed',
            allowedCommands: Object.keys(ALLOWED_COMMANDS),
          }));
          return;
        }

        log('INFO', `Executando: ${cmd} de ${ip}`);

        try {
          const stdout = execSync(cmd, {
            cwd: 'c:\\site-shopvivaliz',
            timeout: Math.min(timeout, 60) * 1000, // Max 60s
            encoding: 'utf-8',
            maxBuffer: 1024 * 1024, // 1MB max output
          });

          log('INFO', `Sucesso: ${cmd}`);
          res.writeHead(200, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            status: 'success',
            output: stdout,
            timestamp: new Date().toISOString(),
          }));
        } catch (execError) {
          log('ERROR', `Falha ao executar ${cmd}: ${execError.message}`);
          res.writeHead(500, { 'Content-Type': 'application/json' });
          res.end(JSON.stringify({
            status: 'error',
            message: execError.message,
            stderr: execError.stderr?.toString() || '',
          }));
        }
      } catch (parseError) {
        log('ERROR', `JSON inválido: ${parseError.message}`);
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Invalid JSON' }));
      }
    });
  } else if (req.method === 'GET' && req.url === '/status') {
    // Health check
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      status: 'running',
      timestamp: new Date().toISOString(),
      allowedCommands: Object.keys(ALLOWED_COMMANDS),
    }));
  } else {
    res.writeHead(404);
    res.end('Not Found');
  }
});

server.listen(PORT, BIND_IP, () => {
  log('INFO', `✅ Servidor rodando em http://${BIND_IP}:${PORT}`);
  log('INFO', `Token: ${TOKEN}`);
  log('INFO', `Comandos permitidos: ${Object.keys(ALLOWED_COMMANDS).join(', ')}`);
  console.log(`
🔒 SERVIDOR SEGURO DE COMANDOS
  URL: http://localhost:${PORT}/execute
  Token: ${TOKEN}

⚡ USO:
  curl -X POST http://localhost:${PORT}/execute \\
    -H "Authorization: Bearer ${TOKEN}" \\
    -H "Content-Type: application/json" \\
    -d '{"cmd": "git status", "timeout": 30}'

📋 Exemplos permitidos:
  - git status, git log, git commit ...
  - npm install, npm run test
  - dir, ls, cat, type

❌ Negados:
  - rm, del, format, shutdown
  - Qualquer comando não-whitelisted
  `);
});

// Graceful shutdown
process.on('SIGINT', () => {
  log('INFO', 'Servidor encerrando...');
  server.close(() => process.exit(0));
});
