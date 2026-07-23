#!/usr/bin/env node
/**
 * iOS Command Listener - Servidor local na porta 9999
 *
 * Uso:
 *   node scripts/ios-command-listener.js
 *
 * Do iPhone:
 *   curl -X POST http://seu-pc-ip:9999/execute \
 *     -H "X-Token: seu-token" \
 *     -H "Content-Type: application/json" \
 *     -d '{"cmd":"git status"}'
 */

const http = require('http');
const { exec, spawn } = require('child_process');
const path = require('path');
const fs = require('fs');

const PORT = 9999;
const TOKEN = process.env.REMOTE_COMMAND_TOKEN || 'dev-token-change-me';
const REPO_PATH = path.resolve(__dirname, '..');

// Log file
const logFile = path.join(REPO_PATH, 'logs', 'ios-commands.log');
fs.mkdirSync(path.dirname(logFile), { recursive: true });

function log(msg) {
    const timestamp = new Date().toISOString();
    const line = `[${timestamp}] ${msg}\n`;
    console.log(line);
    fs.appendFileSync(logFile, line);
}

function executeCommand(cmd) {
    return new Promise((resolve) => {
        const start = Date.now();
        const timeout = setTimeout(() => {
            proc.kill();
            resolve({
                success: false,
                output: '',
                error: 'Command timeout (30s)',
                duration: Date.now() - start,
            });
        }, 30000);

        let output = '';
        let error = '';

        const proc = spawn('powershell.exe', ['-NoProfile', '-Command', cmd], {
            cwd: REPO_PATH,
            stdio: ['pipe', 'pipe', 'pipe'],
        });

        proc.stdout.on('data', (data) => {
            output += data.toString();
        });

        proc.stderr.on('data', (data) => {
            error += data.toString();
        });

        proc.on('close', (code) => {
            clearTimeout(timeout);
            resolve({
                success: code === 0,
                output: output.trim(),
                error: error.trim(),
                code,
                duration: Date.now() - start,
            });
        });
    });
}

const server = http.createServer(async (req, res) => {
    res.setHeader('Content-Type', 'application/json');
    res.setHeader('Access-Control-Allow-Origin', '*');

    // CORS preflight
    if (req.method === 'OPTIONS') {
        res.writeHead(200);
        res.end();
        return;
    }

    // Token verification
    const token = req.headers['x-token'] || '';
    if (token !== TOKEN) {
        res.writeHead(401);
        res.end(JSON.stringify({ success: false, error: 'Invalid token' }));
        return;
    }

    if (req.method !== 'POST' || !req.url.startsWith('/execute')) {
        res.writeHead(404);
        res.end(JSON.stringify({ success: false, error: 'Not found' }));
        return;
    }

    let body = '';
    req.on('data', (chunk) => {
        body += chunk.toString();
    });

    req.on('end', async () => {
        try {
            const data = JSON.parse(body);
            const cmd = data.cmd || data.command || '';

            if (!cmd) {
                res.writeHead(400);
                res.end(JSON.stringify({ success: false, error: 'Missing command' }));
                return;
            }

            log(`📱 iOS Command: ${cmd}`);

            const result = await executeCommand(cmd);

            log(`✅ Output: ${result.output.substring(0, 100)}...`);

            res.writeHead(200);
            res.end(JSON.stringify({
                success: result.success,
                command: cmd,
                output: result.output,
                error: result.error,
                code: result.code,
                duration: result.duration,
                timestamp: new Date().toISOString(),
            }));
        } catch (err) {
            log(`❌ Error: ${err.message}`);
            res.writeHead(500);
            res.end(JSON.stringify({
                success: false,
                error: err.message,
            }));
        }
    });
});

server.listen(PORT, '0.0.0.0', () => {
    log(`🎯 iOS Command Listener rodando na porta ${PORT}`);
    log(`📱 Acesse do iPhone: http://seu-pc-ip:${PORT}/execute`);
    log(`🔐 Token: ${TOKEN}`);
    log(`📁 Repositório: ${REPO_PATH}`);
    log(`📊 Logs: ${logFile}`);
});

server.on('error', (err) => {
    if (err.code === 'EADDRINUSE') {
        log(`❌ Porta ${PORT} já está em uso`);
        process.exit(1);
    }
    log(`❌ Erro: ${err.message}`);
});

process.on('SIGINT', () => {
    log('🛑 Listener parado');
    process.exit(0);
});
