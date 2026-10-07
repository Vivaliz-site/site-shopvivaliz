#!/usr/bin/env node
import http from 'node:http';
import { readFileSync } from 'node:fs';

const HOST = '127.0.0.1';
const PORT = Number(process.env.OKX_CHATGPT_BROWSER_BRIDGE_PORT || 17657);
const MCP_URL = String(process.env.OKX_CHATGPT_BROWSER_MCP_URL || 'http://127.0.0.1:5583/mcp');
const TIMEOUT_MS = Math.max(30000, Number(process.env.OKX_CHATGPT_TIMEOUT_MS || 230000));
const MAX_BODY = 262144;
const clean = value => String(value ?? '').replace(/\s+/g, ' ').trim();

function mcpToken() {
  const directory = String(process.env.CREDENTIALS_DIRECTORY || '').trim();
  if (!directory) throw new Error('mcp_credential_directory_missing');
  let token = '';
  try {
    token = readFileSync(directory + '/mcp-token', 'utf8').trim();
  } catch {
    throw new Error('mcp_token_missing');
  }
  if (token.length < 16) throw new Error('mcp_token_missing');
  return token;
}


export function validateRequest(input) {
  if (!input || typeof input !== 'object' || Array.isArray(input)) throw new Error('invalid_request');
  const model = clean(input.model);
  const effort = clean(input.effort).toLowerCase();
  const profile = clean(input.profile);
  const prompt = String(input.prompt ?? '').trim();
  if (model !== 'gpt-5.6-sol') throw new Error('invalid_model');
  if (effort !== 'xhigh') throw new Error('invalid_effort');
  if (profile !== 'dev') throw new Error('invalid_profile');
  if (input.web_search === true) throw new Error('web_search_disabled');
  if (!prompt || prompt.length > 120000) throw new Error('invalid_prompt');
  return { model, effort, profile, prompt, web_search: false };
}

export function buildMcpCall(request) {
  const validated = validateRequest(request);
  return {
    jsonrpc: '2.0',
    id: 'okx-chatgpt-browser',
    method: 'tools/call',
    params: {
      name: 'browser_chatgpt_infer',
      arguments: {
        model: validated.model,
        effort: validated.effort,
        prompt: validated.prompt,
      },
    },
  };
}

function validateInferenceResult(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('invalid_mcp_inference_result');
  if (value.ok !== true) {
    if (value.error) throw new Error(clean(value.error));
    throw new Error('invalid_mcp_inference_result');
  }
  if (value.model !== 'gpt-5.6-sol') throw new Error('invalid_mcp_inference_result');
  if (value.effort !== 'xhigh') throw new Error('invalid_mcp_inference_result');
  if (value.transport !== 'chatgpt_browser') throw new Error('invalid_mcp_inference_result');
  if (value.profile !== 'dev') throw new Error('invalid_mcp_inference_result');
  if (typeof value.text !== 'string' || !value.text.trim()) throw new Error('invalid_mcp_inference_result');
  return value;
}

export function parseMcpInferenceResult(payload) {
  const result = payload?.result;
  if (!result || result.isError === true) {
    const detail = result?.structuredContent?.error;
    throw new Error(clean(detail || 'mcp_inference_error'));
  }
  if (result.structuredContent && typeof result.structuredContent === 'object') {
    return validateInferenceResult(result.structuredContent);
  }
  const part = Array.isArray(result.content)
    ? result.content.find(item => item && item.type === 'text' && typeof item.text === 'string')
    : null;
  if (!part) throw new Error('invalid_mcp_inference_result');
  let decoded;
  try {
    decoded = JSON.parse(part.text);
  } catch {
    throw new Error('invalid_mcp_inference_result');
  }
  return validateInferenceResult(decoded);
}

async function mcpRequest(payload, timeoutMs = TIMEOUT_MS) {
  const token = mcpToken();
  const response = await fetch(MCP_URL, {
    method: 'POST',
    headers: {
      authorization: 'Bearer ' + token,
      'content-type': 'application/json',
      accept: 'application/json',
    },
    body: JSON.stringify(payload),
    signal: AbortSignal.timeout(timeoutMs),
  });
  let body = null;
  try {
    body = await response.json();
  } catch {}
  if (!response.ok) throw new Error('mcp_http_' + response.status);
  if (!body || typeof body !== 'object') throw new Error('mcp_invalid_response');
  if (body.error) throw new Error(clean(body.error.message || 'mcp_rpc_error'));
  return body;
}

async function inference(request) {
  const response = await mcpRequest(buildMcpCall(request), TIMEOUT_MS);
  return parseMcpInferenceResult(response);
}

async function health() {
  try {
    const response = await mcpRequest({
      jsonrpc: '2.0',
      id: 'okx-chatgpt-browser-health',
      method: 'tools/list',
      params: {},
    }, 4000);
    const tools = Array.isArray(response?.result?.tools) ? response.result.tools : [];
    const available = tools.some(tool => tool?.name === 'browser_chatgpt_infer');
    return {
      ok: available,
      endpoint: 'okx-chatgpt-browser-bridge',
      transport: 'browser_dev_mcp',
      mcp_port: 5583,
      profile: 'dev',
      model: 'gpt-5.6-sol',
      effort: 'xhigh',
      inference_tool_available: available,
    };
  } catch (error) {
    return {
      ok: false,
      endpoint: 'okx-chatgpt-browser-bridge',
      transport: 'browser_dev_mcp',
      mcp_port: 5583,
      profile: 'dev',
      model: 'gpt-5.6-sol',
      effort: 'xhigh',
      inference_tool_available: false,
      error: errorCode(error),
    };
  }
}

function errorCode(error) {
  return clean(error?.message || error || 'browser_bridge_error')
    .replace(/[^A-Za-z0-9_:-]+/g, '_')
    .slice(0, 120);
}

function reply(res, status, payload) {
  const body = JSON.stringify(payload);
  res.writeHead(status, {
    'content-type': 'application/json',
    'content-length': Buffer.byteLength(body),
  });
  res.end(body);
}

function handler(req, res) {
  if (req.method === 'GET' && req.url === '/health') {
    health().then(value => reply(res, value.ok ? 200 : 503, value));
    return;
  }
  if (req.method !== 'POST' || req.url !== '/v1/respond') {
    reply(res, 404, { ok: false, error: 'not_found' });
    return;
  }
  let raw = '';
  let bytes = 0;
  req.on('data', chunk => {
    bytes += chunk.length;
    if (bytes <= MAX_BODY) raw += chunk;
  });
  req.on('end', async () => {
    if (bytes > MAX_BODY) {
      reply(res, 413, { ok: false, error: 'body_too_large' });
      return;
    }
    let request;
    try {
      request = validateRequest(JSON.parse(raw));
    } catch (error) {
      reply(res, 400, { ok: false, error: errorCode(error) });
      return;
    }
    try {
      const result = await inference(request);
      reply(res, 200, result);
    } catch (error) {
      reply(res, 503, { ok: false, error: errorCode(error) });
    }
  });
}

function isDirectInvocation() {
  try {
    return new URL(import.meta.url).pathname === process.argv[1];
  } catch {
    return false;
  }
}

if (isDirectInvocation()) {
  http.createServer(handler).listen(PORT, HOST, () => {
    console.log('okx_chatgpt_browser_bridge ready');
  });
}
