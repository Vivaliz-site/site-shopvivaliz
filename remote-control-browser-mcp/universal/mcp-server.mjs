import http from 'node:http';
import { fork } from 'node:child_process';
import { timingSafeEqual } from 'node:crypto';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
const ROOT=dirname(fileURLToPath(import.meta.url));
const TOKEN=process.env.SHOPVIVALIZ_REMOTE_MCP_TOKEN||'';
if(!TOKEN) throw new Error('mcp_token_unavailable');
const tools=[
  {name:'browser_universal_tabs_list',description:'List saved logical tabs; pages reload when switched',inputSchema:{type:'object',properties:{},additionalProperties:false}},
  {name:'browser_universal_tabs_open',description:'Add a logical tab and visit a public URL',inputSchema:{type:'object',properties:{url:{type:'string'}},required:['url'],additionalProperties:false}},
  {name:'browser_universal_tabs_switch',description:'Restore a logical tab by navigating to its saved URL',inputSchema:{type:'object',properties:{tab_id:{type:'string'}},required:['tab_id'],additionalProperties:false}},
  {name:'browser_universal_tabs_close',description:'Close a saved logical tab',inputSchema:{type:'object',properties:{tab_id:{type:'string'}},required:['tab_id'],additionalProperties:false}},
  {name:'browser_universal_probe',description:'Run isolated browser smoke test',inputSchema:{type:'object',properties:{},additionalProperties:false}},
  {name:'browser_universal_open',description:'Open a URL in isolated Chrome and return title',inputSchema:{type:'object',properties:{url:{type:'string'}},required:['url'],additionalProperties:false}},
  {name:'browser_universal_inspect',description:'Inspect sanitized page interactive controls',inputSchema:{type:'object',properties:{url:{type:'string'},tab_id:{type:'string'}},required:[],additionalProperties:false}},
  {name:'browser_universal_click',description:'Click CSS selector on an authorized URL',inputSchema:{type:'object',properties:{url:{type:'string'},tab_id:{type:'string'},selector:{type:'string'}},required:['selector'],additionalProperties:false}},
  {name:'browser_universal_fill',description:'Fill a non-sensitive field on an authorized URL',inputSchema:{type:'object',properties:{url:{type:'string'},tab_id:{type:'string'},selector:{type:'string'},text:{type:'string'}},required:['selector','text'],additionalProperties:false}},
  {name:'browser_universal_select',description:'Select one option on an authorized URL',inputSchema:{type:'object',properties:{url:{type:'string'},tab_id:{type:'string'},selector:{type:'string'},value:{type:'string'}},required:['selector','value'],additionalProperties:false}},
  {name:'browser_universal_check',description:'Check one checkbox on an authorized URL',inputSchema:{type:'object',properties:{url:{type:'string'},tab_id:{type:'string'},selector:{type:'string'}},required:['selector'],additionalProperties:false}},
  {name:'browser_universal_press',description:'Press a restricted navigation key on an authorized URL',inputSchema:{type:'object',properties:{url:{type:'string'},tab_id:{type:'string'},selector:{type:'string'},key:{type:'string',enum:['Enter','Tab','Escape','ArrowUp','ArrowDown','Space']}},required:['selector','key'],additionalProperties:false}},
  {name:'browser_universal_upload',description:'Attach a staged file under the isolated upload-staging directory',inputSchema:{type:'object',properties:{url:{type:'string'},tab_id:{type:'string'},selector:{type:'string'},filename:{type:'string'}},required:['selector','filename'],additionalProperties:false}},
  {name:'browser_universal_download',description:'Download by clicking a specified element and save under the isolated downloads directory',inputSchema:{type:'object',properties:{url:{type:'string'},tab_id:{type:'string'},selector:{type:'string'}},required:['selector'],additionalProperties:false}}
];
function authorized(raw){const supplied=(raw||'').replace(/^Bearer /i,'');const a=Buffer.from(supplied);const b=Buffer.from(TOKEN);return a.length===b.length && timingSafeEqual(a,b)}
let browserBusy=false;
let worker=null;
let waiting=null;
let requestCounter=0;
const workerEnv={
  PATH:process.env.PATH||'/usr/local/bin:/usr/bin:/bin',
  HOME:process.env.HOME||'/home/ubuntu',
  LANG:'C.UTF-8',
};
for(const key of ['SHOPVIVALIZ_BROWSER_UNIVERSAL_BINARY','SHOPVIVALIZ_BROWSER_UNIVERSAL_DATA_DIR','SHOPVIVALIZ_BROWSER_UNIVERSAL_PROFILE_DIR']){
  if(process.env[key])workerEnv[key]=process.env[key];
}
function ensureWorker(){
  if(worker&&worker.connected)return worker;
  const child=fork(ROOT+'/live-worker.mjs',[],{cwd:ROOT,stdio:['ignore','ignore','pipe','ipc'],env:workerEnv});
  child.stderr.on('data',()=>{});
  child.on('message',response=>{
    if(!waiting||response?.id!==waiting.id)return;
    const w=waiting;waiting=null;clearTimeout(w.timeout);
    if(response.error)w.reject(Error(response.error));
    else w.resolve(response.result);
  });
  child.on('exit',()=>{
    if(worker===child)worker=null;
    if(waiting){const w=waiting;waiting=null;clearTimeout(w.timeout);w.reject(Error('browser_worker_unavailable'));}
  });
  worker=child;
  return child;
}
async function runTool(name,args){
  if(!tools.some(t=>t.name===name))throw Error('unknown_tool');
  if(browserBusy)throw Error('browser_busy');
  browserBusy=true;
  try{
    return await new Promise((resolve,reject)=>{
      const child=ensureWorker();
      const id=++requestCounter;
      const timeout=setTimeout(()=>{
        if(waiting?.id===id)waiting=null;
        child.kill('SIGKILL');
        reject(Error('browser_operation_timeout'));
      },45000);
      waiting={id,resolve,reject,timeout};
      child.send({id,action:name.replace('browser_universal_',''),args:args||{}},err=>{
        if(err&&waiting?.id===id){waiting=null;clearTimeout(timeout);reject(Error('browser_worker_send_failed'));}
      });
    });
  }finally{browserBusy=false;}
}
process.on('SIGTERM',()=>{if(worker)worker.kill('SIGTERM');process.exit(0);});
const server=http.createServer(async(req,res)=>{const send=(status,obj)=>{res.writeHead(status,{'content-type':'application/json; charset=utf-8'});res.end(JSON.stringify(obj))};if(req.socket.remoteAddress!=='127.0.0.1'&&req.socket.remoteAddress!=='::1')return send(403,{error:'loopback_only'});if(req.url==='/health'&&req.method==='GET')return send(200,{ok:true,endpoint:'browser-universal-mcp',tools:tools.length,worker_connected:!!worker?.connected});if(req.url!=='/mcp'||req.method!=='POST')return send(404,{error:'not_found'});if(!authorized(req.headers.authorization))return send(401,{error:'unauthorized'});let data='';for await(const chunk of req){data+=chunk;if(data.length>30000)return send(413,{error:'too_large'})}try{const x=JSON.parse(data);let result;if(x.method==='initialize')result={protocolVersion:'2025-03-26',capabilities:{tools:{}},serverInfo:{name:'shopvivaliz-browser-universal',version:'0.1.0'}};else if(x.method==='tools/list')result={tools};else if(x.method==='tools/call'){try{const value=await runTool(x.params?.name,x.params?.arguments||{});result={content:[{type:'text',text:JSON.stringify(value)}],isError:value?.ok===false}}catch(e){result={content:[{type:'text',text:JSON.stringify({ok:false,error:String(e.message).slice(0,240)})}],isError:true}}}else if(x.method==='notifications/initialized'){res.writeHead(202);res.end();return}else return send(400,{jsonrpc:'2.0',id:x.id??null,error:{code:-32601,message:'unsupported_method'}});return send(200,{jsonrpc:'2.0',id:x.id??null,result})}catch(e){return send(200,{jsonrpc:'2.0',id:null,error:{code:-32000,message:String(e.message).slice(0,280)}})}});
server.listen(Number(process.env.SHOPVIVALIZ_BROWSER_UNIVERSAL_PORT||5595),'127.0.0.1');
