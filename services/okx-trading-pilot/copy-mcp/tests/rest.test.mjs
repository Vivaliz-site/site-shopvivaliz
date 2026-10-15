import test from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { createOkxRestClient, environmentCredentialProvider } from '../okx-rest.mjs';
import { mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
const credentials=async()=>({key:'test-key',secret:'test-secret',passphrase:'test-passphrase'});
test('signed REST GET uses official path and authenticates without printing credentials',async()=>{
 let req;
 const client=createOkxRestClient({credentials,fetcher:async(url,opts)=>{
   req={url,opts};
   return {ok:true,status:200,json:async()=>({code:'0',data:[{uid:'expected'}]})};
 }});
 assert.deepEqual(await client.get('/api/v5/account/config'),[{uid:'expected'}]);
 assert.equal(req.url,'https://www.okx.com/api/v5/account/config');
 const headers=req.opts.headers;
 assert.equal(headers['OK-ACCESS-KEY'],'test-key');
 const expected=createHmac('sha256','test-secret').update(headers['OK-ACCESS-TIMESTAMP']+'GET'+'/api/v5/account/config').digest('base64');
 assert.equal(headers['OK-ACCESS-SIGN'],expected);
});
test('network timeout never triggers repeat POST',async()=>{
 let n=0;const client=createOkxRestClient({credentials,
  fetcher:async()=>{n++;throw new Error('timeout')}});
 await assert.rejects(()=>client.post('/api/v5/copytrading/stop-copy-trading',{uniqueCode:'TEST'}),/OKX_TRANSPORT_UNKNOWN/);
 assert.equal(n,1);
});
test('rejects unauthorized endpoints and invalid origins',async()=>{
 assert.throws(()=>createOkxRestClient({credentials,baseUrl:'https://example.com'}),/INVALID_OKX_ORIGIN/);
 const client=createOkxRestClient({credentials,fetcher:async()=>{throw Error('MUST_NOT_CALL')}});
 await assert.rejects(()=>client.post('/api/v5/asset/withdrawal',{}),/NON_ALLOWLISTED_PATH/);
});
test('API rejection surfaces explicit safe error code',async()=>{
 const client=createOkxRestClient({credentials,fetcher:async()=>({ok:true,status:200,
   json:async()=>({code:'59264',data:[],msg:'not supported'})})});
 await assert.rejects(()=>client.get('/api/v5/copytrading/config'),/OKX_API_REJECTED/);
});


test('credential provider securely falls back to the protected live config when env is absent',async()=>{
 const dir=await mkdtemp(join(tmpdir(),'okx-config-'));
 const configPath=join(dir,'config.toml');
 await writeFile(configPath,"[profiles.live]\nsite = 'global'\napi_key = 'file-key'\nsecret_key = 'file-secret'\npassphrase = 'file-pass'\n");
 const provider=environmentCredentialProvider({}, {configPath});
 assert.deepEqual(await provider(),{key:'file-key',secret:'file-secret',passphrase:'file-pass'});
});

test('credential provider fails closed on a partially configured environment',async()=>{
 const provider=environmentCredentialProvider({OKX_COPY_API_KEY:'only-key'},{configPath:'/does/not/matter'});
 await assert.rejects(()=>provider(),/INCOMPLETE_OKX_CREDENTIAL_ENV/);
});


test('REST allowlist rejects delisted copy-position endpoints before network access',async()=>{
 const fetcher=async()=>{throw new Error('NETWORK_SHOULD_NOT_BE_CALLED')};
 const client=createOkxRestClient({credentials:async()=>({key:'k',secret:'s',passphrase:'p'}),fetcher});
 for(const [method,path] of [
   ['get','/api/v5/copytrading/current-subpositions'],
   ['get','/api/v5/copytrading/subpositions-history'],
   ['post','/api/v5/copytrading/close-subposition']
 ]){
   await assert.rejects(()=>client[method](path,{}),/NON_ALLOWLISTED_PATH/);
 }
});
