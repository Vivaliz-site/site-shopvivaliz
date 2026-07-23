#!/usr/bin/env node
/**
 * MCP Server seguro para executar comandos
 * Roda em Stdio (sem exposição de rede)
 * Valida contra whitelist de comandos
 */

const { execSync } = require('child_process');
const readline = require('readline');

// Whitelist de comandos permitidos
const ALLOWED_COMMANDS = {
  'git': ['status', 'log', 'add', 'commit', 'push', 'pull', 'fetch', 'reset'],
  'npm': ['install', 'run', 'start', 'test', 'list'],
  'node': ['--version'],
  'php': ['--version', '-l'], // lint
  'dir': ['*'],
  'ls': ['*'],
  'cat': ['*'],
  'type': ['*'],
  'curl': ['*'],
  'python': ['--version'],
};

const rl = readline.createInterface({
  input: process.stdin,
  output: process.stdout,
  terminal: false,
});

// Procotolo MCP simples
const protocol = {
  handle_call: (jsonrpc) => {
    const { method, params, id } = jsonrpc;

    if (method === 'execute_command') {
      const { cmd, timeout = 30 } = params;

      // Validar comando contra whitelist
      const [basecmd, ...args] = cmd.split(/\s+/);

      if (!isCommandAllowed(basecmd, args)) {
        return {
          jsonrpc: '2.0',
          id,
          error: {
            code: -32600,
            message: `Comando não permitido: ${basecmd}. Permitidos: ${Object.keys(ALLOWED_COMMANDS).join(', ')}`
          }
        };
      }

      try {
        const result = execSync(cmd, {
          cwd: 'c:\\site-shopvivaliz',
          timeout: timeout * 1000,
          encoding: 'utf-8',
          stdio: ['pipe', 'pipe', 'pipe'],
        });

        console.error(`[MCP] Executado: ${cmd}`);

        return {
          jsonrpc: '2.0',
          id,
          result: {
            status: 'success',
            output: result,
            timestamp: new Date().toISOString(),
          }
        };
      } catch (error) {
        return {
          jsonrpc: '2.0',
          id,
          error: {
            code: -32000,
            message: error.message,
            stderr: error.stderr?.toString() || error.message,
          }
        };
      }
    }

    if (method === 'get_allowed_commands') {
      return {
        jsonrpc: '2.0',
        id,
        result: ALLOWED_COMMANDS
      };
    }

    return {
      jsonrpc: '2.0',
      id,
      error: {
        code: -32601,
        message: `Método desconhecido: ${method}`
      }
    };
  }
};

function isCommandAllowed(basecmd, args) {
  if (!ALLOWED_COMMANDS[basecmd]) {
    return false;
  }

  const allowed = ALLOWED_COMMANDS[basecmd];
  if (allowed.includes('*')) {
    return true; // Qualquer argumento OK
  }

  // Se o primeiro argumento está na whitelist, OK
  return allowed.includes(args[0]);
}

console.error(`🟢 MCP Server iniciado em modo Stdio`);
console.error(`Comandos permitidos:`, Object.keys(ALLOWED_COMMANDS).join(', '));

rl.on('line', (line) => {
  try {
    const request = JSON.parse(line);
    const response = protocol.handle_call(request);
    console.log(JSON.stringify(response));
  } catch (error) {
    console.error(`Erro ao processar: ${error.message}`);
  }
});

rl.on('close', () => {
  console.error(`MCP Server fechado`);
  process.exit(0);
});
