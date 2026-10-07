#!/usr/bin/env node
import http from 'node:http';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const HOST='127.0.0.1';
const PORT=Number(process.env.OKX_CHATGPT_BROWSER_BRIDGE_PORT||17657);
const MCP_URL=process.env.OKX_CHATGPT_DEV_MCP_URL||'http://127.0.0.1:5583/mcp';
const MCP_HEALTH_URL=process.env.OKX_CHATGPT_DEV_MCP_HEALTH_URL||'http://127.0.0.1:5583/health';
const MAX_BODY=262144;
const clean=value=>String(value??'').replace(/\s+/g,' ').trim();

export function validateRequest(input){
  if(!input||typeof input!=='object'||Array.isArray(input)) throw new Error('invalid_request');
  const model=clean(input.model);
  const effort=clean(input.effort).toLowerCase();
  const profile=clean(input.profile);
  const prompt=String(input.prompt??'').trim();
  if(model!=='gpt-5.6-sol') throw new Error('invalid_model');
  if(effort!=='xhigh') throw new Error('invalid_effort');
  if(profile!=='okx') throw new Error('invalid_profile');
  if(input.web_search===true) throw new Error('web_search_disabled');
  if(!prompt||prompt.length>120000) throw new Error('invalid_prompt');
  return {model,effort,profile,prompt,web_search:false};
}

export function mcpResultToProviderResponse(body){
  const result=body?.result;
  const structured=result?.structuredContent;
  if(result?.isError===true||!structured||structured.ok===false){
    throw new Error(clean(structured?.error||body?.error?.message||'dev_mcp_error'));
  }
  if(structured.model!=='gpt-5.6-sol') throw new Error('model_mismatch');
  if(structured.effort!=='xhigh') throw new Error('effort_mismatch');
  if(structured.transport!=='chatgpt_browser') throw new Error('transport_mismatch');
  if(structured.profile!=='okx') throw new Error('profile_mismatch');
  if(typeof structured.text!=='string'||!structured.text.trim()) throw new Error('missing_text');
  return structured;
}

function authHeader(){
  const token=String(process.env.SHOPVIVALIZ_REMOTE_MCP_TOKEN||'').trim();
  if(token.length<32) throw new Error('dev_mcp_auth_unavailable');
  return 'Bearer '+token;
}

async function callDevMcp(request){
  const response=await fetch(MCP_URL,{
    method:'POST',
    headers:{
      authorization:authHeader(),
      'content-type':'application/json',
      accept:'application/json',
    },
    body:JSON.stringify({
      jsonrpc:'2.0',
      id:1,
      method:'tools/call',
      params:{name:'browser_chatgpt_respond',arguments:request},
    }),
    signal:AbortSignal.timeout(200000),
  });
  if(!response.ok) throw new Error('dev_mcp_http_'+response.status);
  return mcpResultToProviderResponse(await response.json());
}

async function health(){
  try{
    const response=await fetch(MCP_HEALTH_URL,{signal:AbortSignal.timeout(1500)});
    const payload=await response.json().catch(()=>({}));
    return {
      ok:response.ok&&payload?.ok===true,
      endpoint:'okx-chatgpt-browser-bridge',
      transport:'dev_browser_mcp',
      mcp_port:5583,
      browser_session:'dev',
    };
  }catch{
    return {ok:false,endpoint:'okx-chatgpt-browser-bridge',transport:'dev_browser_mcp',mcp_port:5583,browser_session:'dev'};
  }
}

function sendJson(res,status,payload){
  const body=JSON.stringify(payload);
  res.writeHead(status,{'content-type':'application/json','content-length':Buffer.byteLength(body)});
  res.end(body);
}
function errorCode(error){
  return clean(error?.message||error||'bridge_error').replace(/[^a-zA-Z0-9_:-]/g,'_').slice(0,120);
}
function handler(req,res){
  if(req.method==='GET'&&req.url==='/health'){
    health().then(payload=>sendJson(res,payload.ok?200:503,payload));
    return;
  }
  if(req.method!=='POST'||req.url!=='/v1/respond'){
    sendJson(res,404,{ok:false,error:'not_found'});
    return;
  }
  let raw='';
  let bytes=0;
  req.on('data',chunk=>{
    bytes+=chunk.length;
    if(bytes<=MAX_BODY) raw+=chunk;
  });
  req.on('end',async()=>{
    if(bytes>MAX_BODY){sendJson(res,413,{ok:false,error:'body_too_large'});return;}
    let request;
    try{request=validateRequest(JSON.parse(raw));}
    catch(error){sendJson(res,400,{ok:false,error:errorCode(error)});return;}
    try{sendJson(res,200,await callDevMcp(request));}
    catch(error){sendJson(res,503,{ok:false,error:errorCode(error)});}
  });
}
function direct(){
  if(!process.argv[1]) return false;
  try{return fileURLToPath(import.meta.url)===path.resolve(process.argv[1]);}
  catch{return false;}
}
if(direct()){
  http.createServer(handler).listen(PORT,HOST,()=>console.log('okx_chatgpt_browser_bridge ready'));
}
