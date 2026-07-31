import fs from 'fs';
import path from 'path';

const targetPath = path.resolve('../.mcp/shopvivaliz-admin/lib/http-server.js');

const code = `/**
 * HTTP/HTTPS MCP server using official SSE transport with stateless fallback
 * Implements Server-Sent Events (SSE) for real MCP protocol
 * AND stateless HTTP fallback for ChatGPT Custom Actions compatibility.
 *
 * Endpoints:
 * - GET  /mcp       : Establish SSE stream (stateful) or return JSON status (stateless)
 * - POST /messages  : Receive client messages (stateful or stateless)
 * - GET  /tools     : List available tools (stateless diagnostic)
 * - GET  /health    : Health check
 */
import http from 'http';
import https from 'https';
import fs from 'fs';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { SSEServerTransport } from '@modelcontextprotocol/sdk/server/sse.js';
import { TOOLS } from './tools.js';
import { handleToolCall } from './handlers.js';

function createMCPServer() {
  console.log('[MCP] Initializing new McpServer instance...');
  const server = new McpServer({
    name: 'shopvivaliz-admin-mcp',
    version: '1.0.0'
  }, {
    capabilities: { tools: {} }
  });

  // Register each tool with the MCP server
  for (const tool of TOOLS) {
    console.log(\`[MCP] Registering tool: \${tool.name}\`);
    server.registerTool(
      tool.name,
      {
        description: tool.description
      },
      async (args = {}) => {
        try {
          console.log(\`[MCP] Tool call received: \${tool.name} with args:\`, JSON.stringify(args));
          const result = await handleToolCall(tool.name, args);
          console.log(\`[MCP] Tool call success: \${tool.name}\`);
          return {
            content: [{
              type: 'text',
              text: typeof result === 'string' ? result : JSON.stringify(result, null, 2)
            }]
          };
        } catch (error) {
          console.error(\`[MCP] Tool call error: \${tool.name}:\`, error);
          return {
            content: [{
              type: 'text',
              text: \`ERROR: \${error.message}\`
            }],
            isError: true
          };
        }
      }
    );
  }
  return server;
}

async function handleStatelessMessage(message, res) {
  const method = message.method;
  const id = message.id;
  console.log(\`[MCP-Stateless] Processing method: \${method}, ID: \${id}\`);

  if (method === 'initialize') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      jsonrpc: '2.0',
      id: id,
      result: {
        protocolVersion: '2024-11-05',
        capabilities: {
          tools: {}
        },
        serverInfo: {
          name: 'shopvivaliz-admin-mcp',
          version: '1.0.0'
        }
      }
    }));
    return;
  }

  if (method === 'notifications/initialized') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ jsonrpc: '2.0', result: { ok: true } }));
    return;
  }

  if (method === 'tools/list') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      jsonrpc: '2.0',
      id: id,
      result: {
        tools: TOOLS.map(t => ({
          name: t.name,
          description: t.description,
          inputSchema: t.inputSchema
        }))
      }
    }));
    return;
  }

  if (method === 'tools/call') {
    const toolName = message.params?.name;
    const toolArgs = message.params?.arguments || {};
    console.log(\`[MCP-Stateless] Calling tool \${toolName} with args:\`, JSON.stringify(toolArgs));

    try {
      const result = await handleToolCall(toolName, toolArgs);
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({
        jsonrpc: '2.0',
        id: id,
        result: {
          content: [{
            type: 'text',
            text: typeof result === 'string' ? result : JSON.stringify(result, null, 2)
          }]
        }
      }));
    } catch (error) {
      console.error(\`[MCP-Stateless] Error executing tool \${toolName}:\`, error);
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({
        jsonrpc: '2.0',
        id: id,
        result: {
          content: [{
            type: 'text',
            text: \`ERROR: \${error.message}\`
          }],
          isError: true
        }
      }));
    }
    return;
  }

  // Default JSON-RPC Method Not Found
  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({
    jsonrpc: '2.0',
    id: id,
    error: {
      code: -32601,
      message: \`Method not found: \${method}\`
    }
  }));
}

function handleRequest(req, res, transports, createMCPServerInstance) {
  // Set CORS headers for all requests
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, DELETE');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, Mcp-Session-Id');

  // Handle OPTIONS preflight request
  if (req.method === 'OPTIONS') {
    console.log(\`[MCP] OPTIONS preflight request received for \${req.url}\`);
    res.writeHead(204);
    res.end();
    return;
  }

  const parsedUrl = new URL(req.url, \`http://\${req.headers.host || 'localhost'}\`);
  const pathname = parsedUrl.pathname;

  // Log every request
  const timestamp = new Date().toISOString();
  console.log(\`[\${timestamp}] \${req.method} \${req.url}\`);
  console.log(\`  Headers: \${JSON.stringify({
    'content-type': req.headers['content-type'],
    'accept': req.headers['accept'],
    'user-agent': req.headers['user-agent'],
    'authorization': req.headers['authorization'] ? 'Bearer ***' : undefined,
    'mcp-session-id': req.headers['mcp-session-id']
  })}\`);

  // Health check endpoint (for diagnostics)
  if (pathname === '/health' && req.method === 'GET') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ status: 'ok', version: '1.0.0', transport: 'sse' }));
    return;
  }

  // Tools list endpoint (for ChatGPT Custom Actions diagnostics)
  if (pathname === '/tools' && req.method === 'GET') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      tools: TOOLS.map(t => ({
        name: t.name,
        description: t.description,
        inputSchema: t.inputSchema
      }))
    }));
    return;
  }

  // Bearer Token Authentication
  const authHeader = req.headers['authorization'];
  const expectedToken = process.env.SHOPVIVALIZ_MCP_TOKEN || process.env.MCP_BEARER_TOKEN;
  
  if (expectedToken) {
    if (!authHeader || !authHeader.startsWith('Bearer ')) {
      console.warn(\`[MCP] Unauthorized request to \${req.url}: Missing or invalid token format\`);
      res.writeHead(401, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'Unauthorized: Missing or invalid token format' }));
      return;
    }
    const token = authHeader.substring(7);
    if (token !== expectedToken) {
      console.warn(\`[MCP] Forbidden request to \${req.url}: Invalid token\`);
      res.writeHead(403, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ error: 'Forbidden: Invalid token' }));
      return;
    }
  }

  // SSE Connection Endpoint
  if (pathname === '/mcp' && req.method === 'GET') {
    const acceptHeader = req.headers['accept'] || '';
    
    // If the request is not asking for SSE, return a standard JSON response to prevent hanging
    if (!acceptHeader.includes('text/event-stream')) {
      console.log('[MCP] GET /mcp requested without text/event-stream Accept header. Returning standard JSON response.');
      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({
        status: 'active',
        message: 'MCP SSE Endpoint. Please connect using an SSE client (Accept: text/event-stream).',
        transport: 'sse'
      }));
      return;
    }

    console.log('[MCP] New SSE connection request received');
    
    // Disable Nagle's algorithm to send small packets immediately
    req.socket.setNoDelay(true);

    // Set custom SSE headers (they will be sent when transport.start() calls writeHead)
    res.setHeader('Content-Type', 'text/event-stream');
    res.setHeader('Cache-Control', 'no-cache, no-transform');
    res.setHeader('Connection', 'keep-alive');
    res.setHeader('X-Accel-Buffering', 'no');
    res.setHeader('Content-Encoding', 'identity'); // Disable compression buffering

    // Create a new MCP Server instance for this connection
    const mcpServer = createMCPServerInstance();
    console.log('[MCP] Created new McpServer instance for connection');

    // Create SSE transport
    const transport = new SSEServerTransport('/messages', res);
    console.log(\`[MCP] Created SSEServerTransport with sessionId: \${transport.sessionId}\`);

    // Store transport in map
    transports.set(transport.sessionId, transport);

    // Connect the server to the transport
    mcpServer.connect(transport).then(() => {
      console.log(\`[MCP] Connected McpServer to transport \${transport.sessionId}\`);
      // Force flush headers and data to the socket immediately
      if (typeof res.flushHeaders === 'function') {
        res.flushHeaders();
      }
    }).catch(err => {
      console.error(\`[MCP] Error connecting McpServer to transport \${transport.sessionId}:\`, err);
    });

    // Handle connection close
    req.on('close', () => {
      console.log(\`[MCP] Connection closed for sessionId: \${transport.sessionId}\`);
      transports.delete(transport.sessionId);
      // Close the transport to clean up
      transport.close().catch(err => {
        console.error(\`[MCP] Error closing transport \${transport.sessionId}:\`, err);
      });
    });
    return;
  }

  // Receive client messages
  if (pathname === '/messages' && req.method === 'POST') {
    let body = '';
    req.on('data', chunk => {
      body += chunk;
    });

    req.on('end', async () => {
      try {
        console.log(\`[MCP] Received message body: \${body}\`);
        const message = JSON.parse(body);
        
        // Extract sessionId from query parameters
        const sessionId = parsedUrl.searchParams.get('sessionId');
        console.log(\`[MCP] Looking for transport with sessionId: \${sessionId}\`);
        
        const transport = sessionId ? transports.get(sessionId) : null;
        
        if (transport) {
          // Log JSON-RPC method
          if (message && message.method) {
            console.log(\`[MCP-Stateful] JSON-RPC Method: \${message.method}, ID: \${message.id}\`);
          }
          // Forward message to transport using handlePostMessage
          await transport.handlePostMessage(req, res, message);
          console.log(\`[MCP-Stateful] Message forwarded to transport \${sessionId}\`);
        } else {
          // No active SSE session found, process statelessly for ChatGPT Custom Actions compatibility
          console.log('[MCP] No active SSE session found. Processing message statelessly.');
          await handleStatelessMessage(message, res);
        }
      } catch (error) {
        console.error('[MCP] Error processing message:', error);
        res.writeHead(500, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Internal server error', details: error.message, stack: error.stack }));
      }
    });
    return;
  }

  // Default 404
  console.warn(\`[MCP] Route not found: \${req.method} \${req.url}\`);
  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ error: 'Not found' }));
}

function createHttpServer(options = {}) {
  const transports = new Map();
  const server = http.createServer(async (req, res) => {
    handleRequest(req, res, transports, createMCPServer);
  });
  return server;
}

function createHttpsServer(options = {}) {
  const certPath = process.env.MCP_CERT_PATH;
  const keyPath = process.env.MCP_KEY_PATH;
  
  if (!certPath || !keyPath) {
    console.error('FATAL: HTTPS mode requires MCP_CERT_PATH and MCP_KEY_PATH environment variables');
    process.exit(1);
  }
  
  const credentials = {
    key: fs.readFileSync(keyPath),
    cert: fs.readFileSync(certPath)
  };

  const transports = new Map();
  const server = https.createServer(credentials, async (req, res) => {
    handleRequest(req, res, transports, createMCPServer);
  });
  return server;
}

export { createHttpServer, createHttpsServer };
`;

fs.writeFileSync(targetPath, code);
console.log('Successfully updated http-server.js with socket flushing and compression disabling!');
