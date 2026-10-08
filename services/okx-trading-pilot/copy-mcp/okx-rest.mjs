import { createHmac } from 'node:crypto';
import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { homedir } from 'node:os';
import { parse as parseToml } from 'smol-toml';

const ALLOWED_BASE_URLS=new Set(['https://www.okx.com','https://openapi.okx.com','https://us.okx.com','https://eea.okx.com']);
const READ_PATHS=new Set(['/api/v5/account/config','/api/v5/account/balance','/api/v5/copytrading/current-lead-traders','/api/v5/copytrading/current-subpositions','/api/v5/copytrading/subpositions-history','/api/v5/copytrading/copy-settings','/api/v5/copytrading/public-stats','/api/v5/copytrading/public-lead-traders','/api/v5/copytrading/config','/api/v5/asset/transfer-state']);
const WRITE_PATHS=new Set(['/api/v5/copytrading/stop-copy-trading','/api/v5/copytrading/close-subposition','/api/v5/copytrading/first-copy-settings','/api/v5/copytrading/amend-copy-settings','/api/v5/asset/transfer']);
export class OkxApiError extends Error{
  constructor(code,providerCode=''){super(code);this.name='OkxApiError';this.code=code;this.providerCode=providerCode}
}
export function createOkxRestClient({credentials,baseUrl='https://www.okx.com',fetcher=fetch,timeoutMs=12000}={}){
  if(!ALLOWED_BASE_URLS.has(baseUrl))throw new OkxApiError('INVALID_OKX_ORIGIN');
  if(typeof credentials!=='function')throw new OkxApiError('AUTHENTICATED_CREDENTIAL_PROVIDER_REQUIRED');
  async function request(method,path,params={}){
    if(!(method==='GET'?READ_PATHS:WRITE_PATHS).has(path))throw new OkxApiError('NON_ALLOWLISTED_PATH');
    const {key,secret,passphrase}=await credentials();
    if(!key||!secret||!passphrase)throw new OkxApiError('LIVE_CREDENTIALS_MISSING');
    const query=method==='GET'?new URLSearchParams(Object.entries(params).filter(([,v])=>v!==undefined)).toString():'';
    const requestPath=path+(query?'?'+query:'');
    const body=method==='POST'?JSON.stringify(params):'';
    const timestamp=new Date().toISOString();
    const sign=createHmac('sha256',secret).update(timestamp+method+requestPath+body).digest('base64');
    const headers={'OK-ACCESS-KEY':key,'OK-ACCESS-SIGN':sign,
      'OK-ACCESS-PASSPHRASE':passphrase,'OK-ACCESS-TIMESTAMP':timestamp,
      'Content-Type':'application/json'};
    let response;
    try{response=await fetcher(baseUrl+requestPath,{method,headers,
      ...(method==='POST'?{body}:{}),signal:AbortSignal.timeout(timeoutMs)})}
    catch{throw new OkxApiError('OKX_TRANSPORT_UNKNOWN')}
    let result;
    try{result=await response.json()}catch{throw new OkxApiError('OKX_BAD_RESPONSE')}
    if(!response.ok||result?.code!=='0'||!Array.isArray(result?.data))
      throw new OkxApiError('OKX_API_REJECTED',String(result?.code??response.status).slice(0,15));
    return result.data;
  }
  return {get:(path,params)=>request('GET',path,params),post:(path,params)=>request('POST',path,params)};
}
export function environmentCredentialProvider(env=process.env,{configPath=join(homedir(),'.okx','config.toml')}={}){
  return async()=>{
    const provided=[env.OKX_COPY_API_KEY,env.OKX_COPY_API_SECRET,env.OKX_COPY_API_PASSPHRASE];
    if(provided.every(Boolean)) return {key:provided[0],secret:provided[1],passphrase:provided[2]};
    if(provided.some(Boolean)) throw new OkxApiError('INCOMPLETE_OKX_CREDENTIAL_ENV');
    const config=parseToml(await readFile(configPath,'utf8'));
    const profile=config?.profiles?.live;
    if(profile?.site!=='global'||!profile.api_key||!profile.secret_key||!profile.passphrase)
      throw new OkxApiError('LIVE_CREDENTIALS_MISSING');
    return {key:String(profile.api_key),secret:String(profile.secret_key),passphrase:String(profile.passphrase)};
  };
}
