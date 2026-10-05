import http from 'node:http';
import { fileURLToPath } from 'node:url';
import { authorizeReadTool } from './tool-policy.mjs';

const PACKAGE='@okx_ai/okx-trade-mcp';
const VERSION='1.4.8';
const PROFILE='live';
const MCP_COMMAND=fileURLToPath(new URL('./node_modules/.bin/okx-trade-mcp',import.meta.url));

async function defaultTransportFactory({command,args}) {
  const [{Client},{StdioClientTransport}] = await Promise.all([
    import('@modelcontextprotocol/sdk/client/index.js'),
    import('@modelcontextprotocol/sdk/client/stdio.js'),
  ]);
  const transport=new StdioClientTransport({command,args,stderr:'pipe'});
  const client=new Client({name:'shopvivaliz-okx-read-bridge',version:'1.0.0'},{capabilities:{}});
  await client.connect(transport);
  return {
    listTools:()=>client.listTools(),
    callTool:({name,arguments:toolArguments})=>client.callTool({name,arguments:toolArguments}),
    close:()=>client.close(),
  };
}
function sendJson(res,status,payload){
  const body=JSON.stringify(payload);
  res.writeHead(status,{'content-type':'application/json; charset=utf-8','content-length':Buffer.byteLength(body)});
  res.end(body);
}
async function readJsonBody(req){
  const chunks=[]; let size=0;
  for await (const chunk of req) {
    size+=chunk.length;
    if(size>65536) throw new Error('request too large');
    chunks.push(chunk);
  }
  return JSON.parse(Buffer.concat(chunks).toString('utf8') || '{}');
}

export function createOkxReadBridge({port=17671,transportFactory=defaultTransportFactory}={}) {
  const args=['--profile',PROFILE,'--modules','all','--read-only'];
  let runtimeTools=new Set();
  const connectionPromise=Promise.resolve(transportFactory({command:MCP_COMMAND,args})).then(async connection=>{
    const listed=await connection.listTools();
    runtimeTools=new Set((listed?.tools || []).map(t=>t?.name).filter(Boolean));
    return connection;
  });
  const server=http.createServer(async(req,res)=>{
    try {
      if(req.method==='GET' && req.url==='/health') {
        await connectionPromise;
        return sendJson(res,200,{package:PACKAGE,version:VERSION,profile:PROFILE,read_only:true,tool_count:runtimeTools.size,upstream_connected:true});
      }
      if(req.method==='POST' && req.url==='/v1/call') {
        const body=await readJsonBody(req);
        if(typeof body.request_id!=='string' || !body.request_id || typeof body.tool!=='string' || !body.tool || body.arguments===null || typeof body.arguments!=='object' || Array.isArray(body.arguments)) {
          return sendJson(res,400,{error:'INVALID_REQUEST'});
        }
        const access=authorizeReadTool({tool:body.tool,runtimeTools});
        if(!access.allowed) return sendJson(res,access.code==='READ_ONLY'?403:503,{error:access.code,request_id:body.request_id});
        const connection=await connectionPromise;
        const result=await connection.callTool({name:body.tool,arguments:body.arguments});
        return sendJson(res,200,{request_id:body.request_id,result});
      }
      return sendJson(res,404,{error:'NOT_FOUND'});
    } catch {
      return sendJson(res,503,{error:'UPSTREAM_UNAVAILABLE'});
    }
  });
  server.on('close',()=>{connectionPromise.then(c=>c.close()).catch(()=>{});});
  connectionPromise.then(()=>server.listen(port,'127.0.0.1')).catch(error=>queueMicrotask(()=>server.emit('error',error)));
  return server;
}

if (process.argv[1] && fileURLToPath(import.meta.url)===process.argv[1]) {
  createOkxReadBridge({port:Number(process.env.OKX_BRIDGE_PORT || '17671')});
}
