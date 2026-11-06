import http from 'node:http';
import {readFileSync} from 'node:fs';
import {resolve,dirname} from 'node:path';
import {fileURLToPath} from 'node:url';
import {timingSafeEqual} from 'node:crypto';
import {BrowserDriver} from './browser.mjs';
import {TakeoverController} from './controller.mjs';

export const RESOURCE_URI='ui://shopvivaliz-web-agent/takeover.html';
const WIDGET=readFileSync(resolve(dirname(fileURLToPath(import.meta.url)),'../web/takeover.html'),'utf8');
const inputObject=(properties,required=[])=>({type:'object',properties,required,additionalProperties:false});
const S={type:'string'};
const SCROLL={type:'integer',minimum:-2000,maximum:2000};
const TOOLS=[
  {name:'web_agent_open',description:'Open a public website in a new isolated ShopVivaliz browser session. Does not reuse corporate Chrome profiles.',inputSchema:inputObject({url:S},['url']),annotations:{readOnlyHint:false,openWorldHint:true}},
  {name:'web_agent_inspect',description:'Inspect sanitized controls only after human has returned control. Never call during human takeover.',inputSchema:inputObject({session_id:S},['session_id']),annotations:{readOnlyHint:true,openWorldHint:true}},
  {name:'web_agent_click',description:'Click an explicitly authorized control after human takeover ends. Requires separate approval for consequential actions.',inputSchema:inputObject({session_id:S,selector:S},['session_id','selector']),annotations:{openWorldHint:true}},
  {name:'web_agent_navigate',description:'Navigate the existing isolated session to a public HTTP(S) URL. Agent mode only.',inputSchema:inputObject({session_id:S,url:S},['session_id','url']),annotations:{readOnlyHint:false,openWorldHint:true}},
  {name:'web_agent_back',description:'Go to the previous page in the existing browser session. Agent mode only.',inputSchema:inputObject({session_id:S},['session_id']),annotations:{readOnlyHint:false,openWorldHint:true}},
  {name:'web_agent_forward',description:'Go forward in the existing browser session. Agent mode only.',inputSchema:inputObject({session_id:S},['session_id']),annotations:{readOnlyHint:false,openWorldHint:true}},
  {name:'web_agent_scroll',description:'Scroll the current public webpage by delta_y pixels, range -2000 to 2000, nonzero. Agent mode only.',inputSchema:inputObject({session_id:S,delta_y:SCROLL},['session_id','delta_y']),annotations:{readOnlyHint:false,openWorldHint:true}},
  {name:'web_agent_fill',description:'Fill a non-secret search, text, or contenteditable field in agent mode. Password, OTP, tokens and payment fields require a separate human takeover. Filling does not submit.',inputSchema:inputObject({session_id:S,selector:S,text:S},['session_id','selector','text']),annotations:{readOnlyHint:false,openWorldHint:true}},
  {name:'web_agent_key',description:'Press an allowed navigation key in agent mode. Submitting consequential forms needs authorization.',inputSchema:inputObject({session_id:S,key:S},['session_id','key']),annotations:{readOnlyHint:false,openWorldHint:true}},
  {name:'web_agent_takeover',description:'Open private takeover UI in ChatGPT so the owner can type a login password or TOTP directly without sending secrets to the model. The browser pauses until the owner returns control.',inputSchema:inputObject({session_id:S},['session_id']),_meta:{ui:{resourceUri:RESOURCE_URI},'openai/outputTemplate':RESOURCE_URI,'openai/toolInvocation/invoking':'Abrindo navegador para autenticacao','openai/toolInvocation/invoked':'Navegador disponivel para controle humano'}},
  {name:'web_agent_status',description:'Read nonsecret takeover status. If human control is active, do not access page content or call other automation actions.',inputSchema:inputObject({session_id:S},['session_id']),annotations:{readOnlyHint:true,openWorldHint:false}},
  {name:'web_agent_close',description:'Close an idle session, not during human takeover.',inputSchema:inputObject({session_id:S},['session_id']),annotations:{openWorldHint:false}}
];
const safeError=(error)=>String(error?.message||'internal_error').slice(0,100).replace(/[^a-zA-Z0-9_:-]/g,'_');
function sendJson(res,status,value,more={}){res.writeHead(status,{'content-type':'application/json; charset=utf-8','cache-control':'no-store','x-content-type-options':'nosniff',...more});res.end(JSON.stringify(value));}
function authorize(authorization,expected){
  if(!expected || typeof authorization!=='string'||!authorization.startsWith('Bearer '))return false;
  const a=Buffer.from(authorization.slice(7));const b=Buffer.from(expected);
  return a.length===b.length && timingSafeEqual(a,b);
}
async function readJson(req,limit){
  let total=0;const chunks=[];
  for await(const chunk of req){total+=chunk.length;if(total>limit)throw Error('request_too_large');chunks.push(chunk);}
  try{return JSON.parse(Buffer.concat(chunks).toString('utf8'));}catch{throw Error('invalid_json');}
}
function validId(x){return typeof x==='string'&&/^[a-f0-9-]{36}$/.test(x);}
export function createServer({controller=new TakeoverController(new BrowserDriver()),mcpToken,publicOrigin='http://127.0.0.1:5598'}={}){
  if(!mcpToken||mcpToken.length<32)throw Error('WEB_AGENT_MCP_TOKEN_requires_32_characters');
  const origin=new URL(publicOrigin).origin;
  if(origin.startsWith('http:')&&!/^http:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/.test(origin))throw Error('https_required_for_public_origin');
  const rate=new Map();
  function permit(key,max=100){const now=Date.now();const v=rate.get(key);if(!v||now-v.start>=60000){rate.set(key,{start:now,count:1});return true;}v.count++;return v.count<=max;}
  const server=http.createServer(async(req,res)=>{
    const pathname=(()=>{try{return new URL(req.url,'http://127.0.0.1').pathname}catch{return ''}})();
    const common={'cache-control':'no-store','x-content-type-options':'nosniff','referrer-policy':'no-referrer','content-security-policy':"default-src 'none'; frame-ancestors 'none'"};
    // The server itself is bound only to loopback. A separately approved
    // authenticated reverse proxy may later map it to the declared origin.
    const remote=req.socket.remoteAddress;
    if(remote!=='127.0.0.1'&&remote!=='::1')return sendJson(res,403,{error:'loopback_only'});
    if(pathname==='/health'&&req.method==='GET')return sendJson(res,200,{ok:true,app:'shopvivaliz-web-agent',transport:'loopback',exposed:false},common);
    if(pathname==='/mcp'){
      if(req.method!=='POST')return sendJson(res,405,{error:'method_not_allowed'},common);
      if(!authorize(req.headers.authorization,mcpToken))return sendJson(res,401,{error:'unauthorized'},common);
      let x;
      try{x=await readJson(req,32000);}catch(e){return sendJson(res,400,{error:safeError(e)},common);}
      const id=x.id??null;
      const reply=(value)=>sendJson(res,200,{jsonrpc:'2.0',id,result:value},common);
      if(x.method==='initialize')return reply({protocolVersion:'2025-03-26',capabilities:{tools:{},resources:{}},serverInfo:{name:'shopvivaliz-web-agent',version:'0.1.0'}});
      if(x.method==='notifications/initialized'){res.writeHead(202,common);return res.end();}
      if(x.method==='ping')return reply({});
      if(x.method==='tools/list')return reply({tools:TOOLS});
      if(x.method==='resources/list')return reply({resources:[{uri:RESOURCE_URI,name:'ShopVivaliz browser takeover',mimeType:'text/html;profile=mcp-app'}]});
      if(x.method==='resources/read'&&x.params?.uri===RESOURCE_URI)return reply({contents:[{uri:RESOURCE_URI,mimeType:'text/html;profile=mcp-app',text:WIDGET,_meta:{ui:{prefersBorder:true,csp:{connectDomains:[origin],resourceDomains:[]}},'openai/ui':{availableDisplayModes:['fullscreen','inline']}}}]});
      if(x.method!=='tools/call')return sendJson(res,200,{jsonrpc:'2.0',id,error:{code:-32601,message:'method_not_found'}},common);
      const {name}=x.params||{};const args=x.params?.arguments||{};
      const def=TOOLS.find(t=>t.name===name);
      if(!def)return sendJson(res,200,{jsonrpc:'2.0',id,error:{code:-32602,message:'unknown_tool'}},common);
      try{
        for(const [k,v] of Object.entries(args)){
          const p=def.inputSchema.properties[k];
          if(!p)throw Error('invalid_arguments');
          if(p.type==='string' && (typeof v!=='string'||!v))throw Error('invalid_arguments');
          if(p.type==='integer' && (!Number.isInteger(v)||v<p.minimum||v>p.maximum))throw Error('invalid_arguments');
        }
        for(const k of def.inputSchema.required)if(!Object.hasOwn(args,k))throw Error('missing_required_argument');
        let data,privateMeta;
        if(name==='web_agent_open')data=await controller.open(args.url);
        else if(name==='web_agent_inspect')data=await controller.inspect(args.session_id);
        else if(name==='web_agent_click')data=await controller.click(args.session_id,args.selector);
        else if(name==='web_agent_navigate')data=await controller.navigate(args.session_id,args.url);
        else if(name==='web_agent_back')data=await controller.back(args.session_id);
        else if(name==='web_agent_forward')data=await controller.forward(args.session_id);
        else if(name==='web_agent_scroll')data=await controller.scroll(args.session_id,args.delta_y);
        else if(name==='web_agent_fill')data=await controller.fill(args.session_id,args.selector,args.text);
        else if(name==='web_agent_key')data=await controller.key(args.session_id,args.key);
        else if(name==='web_agent_status')data=controller.status(args.session_id);
        else if(name==='web_agent_close')data=await controller.close(args.session_id);
        else if(name==='web_agent_takeover'){
          const r=await controller.handoff(args.session_id);
          data=r.public;privateMeta={handoff:{...r.private,api_base:origin,site_origin:r.public.site_origin}};
        }
        const result={content:[{type:'text',text:JSON.stringify(data)}],structuredContent:data};
        if(privateMeta)result._meta=privateMeta;
        return reply(result);
      }catch(e){return reply({content:[{type:'text',text:JSON.stringify({ok:false,error:safeError(e)})}],isError:true});}
    }
    if(!pathname.startsWith('/human/'))return sendJson(res,404,{error:'not_found'},common);
    const m=/^\/human\/([a-f0-9-]{36})\/(frame|action|release)$/.exec(pathname);
    if(!m||!validId(m[1]))return sendJson(res,404,{error:'not_found'},common);
    const id=m[1],operation=m[2];
    const cors={'access-control-allow-origin':'*','access-control-allow-headers':'Authorization, Content-Type','access-control-allow-methods':'GET, POST, OPTIONS','access-control-expose-headers':'X-ShopVivaliz-Site-Origin','vary':'Origin','cache-control':'no-store','x-content-type-options':'nosniff','referrer-policy':'no-referrer'};
    if(req.method==='OPTIONS'){res.writeHead(204,cors);return res.end();}
    if(!permit(id,200))return sendJson(res,429,{error:'rate_limited'},cors);
    const supplied=(req.headers.authorization||'').replace(/^Bearer /i,'');
    try{
      if(operation==='frame'&&req.method==='GET'){
        const {frame,origin}=await controller.humanFrame(id,supplied,true);
        res.writeHead(200,{'content-type':'image/jpeg','content-length':frame.length,'X-ShopVivaliz-Site-Origin':origin,...cors});return res.end(frame);
      }
      if(operation==='action'&&req.method==='POST'){
        const v=await readJson(req,2048);
        await controller.humanAction(id,supplied,v.action,v);
        return sendJson(res,200,{ok:true},cors);
      }
      if(operation==='release'&&req.method==='POST')return sendJson(res,200,await controller.release(id,supplied),cors);
      return sendJson(res,405,{error:'method_not_allowed'},cors);
    }catch(e){
      const code=safeError(e);
      const status=/capability|handoff_expired/.test(code)?403:/request_too_large/.test(code)?413:/human_takeover_inactive|session_not_found/.test(code)?404:400;
      return sendJson(res,status,{error:code},cors);
    }
  });
  return server;
}

if(process.argv[1]&&resolve(process.argv[1])===fileURLToPath(import.meta.url)){
  const token=process.env.WEB_AGENT_MCP_TOKEN;
  const host='127.0.0.1';
  const port=Number(process.env.WEB_AGENT_PORT||5598);
  const server=createServer({mcpToken:token,publicOrigin:process.env.WEB_AGENT_PUBLIC_ORIGIN||`http://${host}:${port}`});
  server.listen(port,host,()=>console.log(`web-agent local listener ${host}:${port}`));
}
