import http from 'node:http';
import { fileURLToPath } from 'node:url';
import { authorizeTool } from './tool-policy.mjs';
import { createCopyModule } from './copy-module.mjs';
import { createOkxRestClient, environmentCredentialProvider } from './okx-rest.mjs';

const PACKAGE='@okx_ai/okx-trade-mcp';
const VERSION='1.4.8';
const PROFILE='live';
const MCP_COMMAND=fileURLToPath(new URL('./node_modules/.bin/okx-trade-mcp',import.meta.url));
const DEFAULT_STATE='/home/ubuntu/shopvivaliz-deploy/shared/okx-pilot/copy-state';

async function defaultTransportFactory({command,args}) {
  const [{Client},{StdioClientTransport}]=await Promise.all([
    import('@modelcontextprotocol/sdk/client/index.js'),
    import('@modelcontextprotocol/sdk/client/stdio.js'),
  ]);
  const transport=new StdioClientTransport({command,args,stderr:'pipe'});
  const client=new Client({name:'shopvivaliz-okx-read-bridge',version:'1.0.0'},{capabilities:{}});
  await client.connect(transport);
  return {listTools:()=>client.listTools(),
    callTool:({name,arguments:toolArguments})=>client.callTool({name,arguments:toolArguments}),
    ping:()=>client.ping(),close:()=>client.close()};
}
function sendJson(res,status,payload){
  const body=JSON.stringify(payload);
  res.writeHead(status,{'content-type':'application/json; charset=utf-8','content-length':Buffer.byteLength(body),
    'cache-control':'no-store','x-content-type-options':'nosniff'});
  res.end(body);
}
async function readJsonBody(req){
  const chunks=[];let size=0;
  for await(const chunk of req){size+=chunk.length;if(size>65536)throw Error('REQUEST_TOO_LARGE');chunks.push(chunk)}
  return JSON.parse(Buffer.concat(chunks).toString('utf8')||'{}');
}
function toolOutput(out){return {content:[{type:'text',text:JSON.stringify(out)}],isError:false}}
function readLegacy(res){
  if(res?.isError)throw Error('UPSTREAM_TOOL_FAILED');
  const txt=res?.content?.find(x=>x.type==='text')?.text;
  let d;try{d=JSON.parse(txt)}catch{throw Error('UPSTREAM_BAD_RESULT')}
  if(!d?.ok||!Array.isArray(d?.data?.data))throw Error('UPSTREAM_UNVERIFIED');
  return d.data.data;
}
function makeApi(connected,rest){
  return {
    get:async(path,params={})=>{
      // Original read-only CLI uses the pre-existing protected LIVE profile.
      if(path==='/api/v5/account/config'){
        return readLegacy(await connected.callTool({name:'account_get_config',arguments:{}}));
      }
      if(path==='/api/v5/account/balance'){
        return readLegacy(await connected.callTool({name:'account_get_balance',arguments:{...params}}));
      }
      return rest.get(path,params);
    },
    restIdentity:()=>rest.get('/api/v5/account/config'),
    post:(path,payload)=>rest.post(path,payload)
  };
}
function safeCode(e){
  const code=String(e?.code||e?.message||'COPY_MODULE_FAILED');
  return /^[A-Z][A-Z0-9_]{1,80}$/.test(code)?code:'COPY_MODULE_FAILED';
}
export function createOkxReadBridge({port=17671,transportFactory=defaultTransportFactory,copyFactory,restClient}={}){
  const readOnly=process.env.OKX_MCP_READ_ONLY!=='0';
  const writeEnabled=process.env.OKX_SPOT_FUTURES_WRITE_ENABLED==='1';
  const args=['--profile',PROFILE,'--modules','all',...(readOnly?['--read-only']:[])];
  let runtimeTools=new Set(),runtimeToolMeta=new Map(),upstreamTools=[],copyModule=null;
  const connectionPromise=Promise.resolve(transportFactory({command:MCP_COMMAND,args})).then(async connection=>{
    const listed=await connection.listTools();
    upstreamTools=listed?.tools||[];
    runtimeTools=new Set(upstreamTools.map(t=>t?.name).filter(Boolean));
    runtimeToolMeta=new Map(upstreamTools.filter(t=>t?.name).map(t=>[t.name,t]));
    const rest=restClient||createOkxRestClient({
      credentials:environmentCredentialProvider(),
      baseUrl:process.env.OKX_COPY_API_BASE||'https://www.okx.com'
    });
    copyModule=copyFactory?copyFactory({connection,rest}):createCopyModule({
      api:makeApi(connection,rest),
      stateDir:process.env.OKX_COPY_STATE_DIR||DEFAULT_STATE,
      writeEnabled:process.env.OKX_COPY_WRITE_ENABLED==='1'
    });
    return connection;
  });
  const server=http.createServer(async(req,res)=>{
    try{
      if(req.method==='GET'&&req.url==='/health'){
        const connection=await connectionPromise;
        if(connection.ping)await connection.ping();
        return sendJson(res,200,{package:PACKAGE,version:VERSION,profile:PROFILE,
          read_only:readOnly,spot_futures_writes_enabled:writeEnabled,
          copy_writes_enabled:process.env.OKX_COPY_WRITE_ENABLED==='1',
          tool_count:runtimeTools.size,copy_tool_count:copyModule.listTools().length,upstream_connected:true});
      }
      if(req.method==='GET'&&req.url==='/v1/tools'){
        await connectionPromise;
        return sendJson(res,200,{tools:[...upstreamTools,...copyModule.listTools()]});
      }
      if(req.method==='GET'&&req.url==='/v1/copy/health'){
        await connectionPromise;
        return sendJson(res,200,await copyModule.health());
      }
      if(req.method==='POST'&&req.url==='/v1/call'){
        const body=await readJsonBody(req);
        if(typeof body.request_id!=='string'||!body.request_id||typeof body.tool!=='string'||!body.tool||
          body.arguments===null||typeof body.arguments!=='object'||Array.isArray(body.arguments)){
          return sendJson(res,400,{error:'INVALID_REQUEST'});
        }
        if(body.tool.startsWith('okx.copy.')){
          await connectionPromise;
          try{
            const out=await copyModule.call(body.tool,body.arguments,{requestId:body.request_id});
            console.info(JSON.stringify({event:'okx.copy.call',tool:body.tool,outcome:out.code||'READ_OK',at:new Date().toISOString()}));
            return sendJson(res,200,{request_id:body.request_id,result:toolOutput(out)});
          }catch(e){
            const error=safeCode(e),status=error==='UID_MISMATCH'||error==='WRITE_DISABLED'?403:
              error==='DUPLICATE_OR_UNKNOWN'||error==='APPROVAL_REQUIRED'||error==='UNKNOWN_RECONCILE_REQUIRED'?409:503;
            console.warn(JSON.stringify({event:'okx.copy.call',tool:body.tool,outcome:error,at:new Date().toISOString()}));
            return sendJson(res,status,{request_id:body.request_id,error});
          }
        }
        const access=authorizeTool({tool:body.tool,runtimeTools,runtimeToolMeta,writeEnabled});
        if(!access.allowed)return sendJson(res,access.code==='READ_ONLY'?403:503,
          {error:access.code,request_id:body.request_id});
        const connection=await connectionPromise;
        const result=await connection.callTool({name:body.tool,arguments:body.arguments});
        return sendJson(res,200,{request_id:body.request_id,result});
      }
      return sendJson(res,404,{error:'NOT_FOUND'});
    }catch{return sendJson(res,503,{error:'UPSTREAM_UNAVAILABLE'})}
  });
  server.on('close',()=>connectionPromise.then(x=>x.close()).catch(()=>{}));
  connectionPromise.then(()=>server.listen(port,'127.0.0.1')).catch(error=>queueMicrotask(()=>server.emit('error',error)));
  return server;
}
if(process.argv[1]&&fileURLToPath(import.meta.url)===process.argv[1])
  createOkxReadBridge({port:Number(process.env.OKX_BRIDGE_PORT||'17671')});
