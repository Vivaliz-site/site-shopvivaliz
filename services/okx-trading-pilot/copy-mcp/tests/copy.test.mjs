import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, mkdir, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createCopyModule } from '../copy-module.mjs';

const UID = '822315791411831486';
function fakeApi({uid=UID, uncertain=false, balance='12.5'}={}) {
  const calls=[];
  const traders=[{uniqueCode:'OLD1',nickName:'slime198888'},{uniqueCode:'OLD2',nickName:'Modern-dAPI-Manatee'},
    {uniqueCode:'KEEP1',nickName:'BestMax'},{uniqueCode:'KEEP2',nickName:'NANO IA'}];
  const positions=[{uniqueCode:'OLD1',subPosId:'P1',margin:'15',upl:'-2',instId:'BTC-USDT-SWAP'}];
  return {calls,async get(path,params={}) {
    calls.push({method:'GET',path,params});
    if(path==='/api/v5/account/config') return [{uid,mainUid:'MAIN_UID'}];
    if(path==='/api/v5/account/balance') return [{details:[{ccy:'USDT',availBal:balance,eq:'1200'}]}];
    if(path==='/api/v5/copytrading/current-lead-traders') return params.instType==='SPOT'?[]:traders;
    if(path==='/api/v5/copytrading/current-subpositions') return params.instType==='SPOT'?[]:positions;
    if(path==='/api/v5/copytrading/subpositions-history') return [];
    if(path==='/api/v5/copytrading/copy-settings') return [{copyTotalAmt:'600'}];
    if(path==='/api/v5/copytrading/public-lead-traders') return [];
    if(path==='/api/v5/asset/transfer-state') return [{state:'success',transId:params.transId}];
    return [];
  },async post(path,body){
    calls.push({method:'POST',path,body});
    if(uncertain) throw Object.assign(new Error('timeout'),{code:'ETIMEDOUT'});
    return [{result:true}];
  }};
}
async function harness(opts={}) {
  const stateDir=await mkdtemp(join(tmpdir(),'okx-copy-'));
  const api=fakeApi(opts);
  return {mod:createCopyModule({api,stateDir,writeEnabled:opts.writeEnabled??false}),api,stateDir};
}
test('account verification accepts only exact subaccount uid',async()=>{
  const {mod}=await harness();const r=await mod.call('okx.copy.account.verify',{});
  assert.equal(r.verified,true);assert.equal(r.uid,UID);
});
test('wrong UID is fail-closed before balance query',async()=>{
  const {mod,api}=await harness({uid:'WRONG'});
  await assert.rejects(()=>mod.call('okx.copy.balance.available',{}),/UID_MISMATCH/);
  assert.equal(api.calls.filter(c=>c.path.includes('balance')).length,0);
});
test('previews show positions, balance and never POST',async()=>{
  const {mod,api}=await harness();
  const out=await mod.call('okx.copy.stop',{phase:'preview',instType:'SWAP',trader:'slime198888',uniqueCode:'OLD1'}, {requestId:'p1'});
  assert.equal(out.code,'APPROVAL_REQUIRED');assert.ok(out.plan_id);assert.equal(out.open_positions.length,1);
  assert.equal(api.calls.filter(c=>c.method==='POST').length,0);
});
test('writes remain disabled without gate even with a plan',async()=>{
  const {mod,api}=await harness();
  const args={phase:'preview',instType:'SWAP',trader:'slime198888',uniqueCode:'OLD1'};
  const out=await mod.call('okx.copy.stop',args,{requestId:'p1'});
  await assert.rejects(()=>mod.call('okx.copy.stop',{...args,phase:'execute',plan_id:out.plan_id,approval_id:'fake'},{requestId:'p1'}),/WRITE_DISABLED/);
  assert.equal(api.calls.filter(c=>c.method==='POST').length,0);
});
test('preserved traders can never be stopped or closed',async()=>{
  const {mod,api}=await harness();
  await assert.rejects(()=>mod.call('okx.copy.stop',{phase:'preview',instType:'SWAP',trader:'BestMax',uniqueCode:'KEEP1'}),/PROTECTED_TRADER|TRADER_NOT_ALLOWED/);
  assert.equal(api.calls.filter(c=>c.method==='POST').length,0);
});
test('copy funds release is not a fabricated API call',async()=>{
  const {mod,api}=await harness();const r=await mod.call('okx.copy.funds.release',{});
  assert.equal(r.code,'UNSUPPORTED_BY_OKX');assert.equal(api.calls.filter(c=>c.method==='POST').length,0);
});
test('transfer rejects external or cross-account flows',async()=>{
  const {mod,api}=await harness();
  await assert.rejects(()=>mod.call('okx.copy.funds.internal_transfer',{phase:'preview',from:'6',to:'18',amount:'1',subAcct:'other'}),/CROSS_ACCOUNT_FORBIDDEN/);
  assert.equal(api.calls.filter(c=>c.method==='POST').length,0);
});
test('ambiguous timeout is journaled and never retried',async()=>{
  const {mod,api,stateDir}=await harness({uncertain:true,writeEnabled:true});
  const common={instType:'SWAP',trader:'slime198888',uniqueCode:'OLD1',subPosCloseType:'manual_close'};
  const plan=await mod.call('okx.copy.stop',{...common,phase:'preview'}, {requestId:'p1'});
  await mkdir(join(stateDir,'grants'),{recursive:true});
  await writeFile(join(stateDir,'grants','A1.json'),JSON.stringify({plan_id:plan.plan_id,tool:'okx.copy.stop',uid:UID,expires_at:Date.now()+60000}),{mode:0o600});
  await assert.rejects(()=>mod.call('okx.copy.stop',{...common,phase:'execute',plan_id:plan.plan_id,approval_id:'A1'},{requestId:'mut1'}),/UNKNOWN_RECONCILE_REQUIRED/);
  assert.equal(api.calls.filter(c=>c.method==='POST').length,1);
  await assert.rejects(()=>mod.call('okx.copy.stop',{...common,phase:'execute',plan_id:plan.plan_id,approval_id:'A1'},{requestId:'mut1'}),/DUPLICATE_OR_UNKNOWN|APPROVAL_REQUIRED/);
  assert.equal(api.calls.filter(c=>c.method==='POST').length,1);
  const files=await import('node:fs/promises').then(x=>x.readdir(join(stateDir,'operations')));
  const record=JSON.parse(await readFile(join(stateDir,'operations',files[0]),'utf8'));
  assert.equal(record.status,'UNKNOWN');
});
test('tool schemas expose all requested 18 functions',async()=>{
  const {mod}=await harness();
  const expected=['account.verify','traders.list','trader.details','positions.list','positions.details','balance.available','balance.allocated','history','profit_loss','spot.status','futures.status','stop','positions.close','funds.release','funds.internal_transfer','trader.start','trader.settings.update','transaction.verify'];
  for(const suffix of expected){const tool=mod.listTools().find(t=>t.name==='okx.copy.'+suffix);assert.ok(tool,suffix);assert.equal(tool.inputSchema.type,'object')}
});
test('insufficient transferable USDT is denied in preview without POST',async()=>{
 const {mod,api}=await harness({balance:'0.02'});
 await assert.rejects(()=>mod.call('okx.copy.funds.internal_transfer',
   {phase:'preview',instType:'SWAP',amount:'50',from:'18',to:'6'}),/INSUFFICIENT_BALANCE/);
 assert.equal(api.calls.filter(x=>x.method==='POST').length,0);
});
test('unknown operation survives module restart with zero replayed POST',async()=>{
 const {mod,api,stateDir}=await harness({uncertain:true,writeEnabled:true});
 const base={instType:'SWAP',trader:'slime198888',uniqueCode:'OLD1',subPosCloseType:'manual_close'};
 const preview=await mod.call('okx.copy.stop',{phase:'preview',...base});
 await mkdir(join(stateDir,'grants'),{recursive:true});
 await writeFile(join(stateDir,'grants','R1.json'),JSON.stringify({
   plan_id:preview.plan_id,tool:'okx.copy.stop',uid:UID,expires_at:Date.now()+60000
 }));
 const input={phase:'execute',...base,plan_id:preview.plan_id,approval_id:'R1'};
 await assert.rejects(()=>mod.call('okx.copy.stop',input,{requestId:'req-restart'}),/UNKNOWN_RECONCILE_REQUIRED/);
 const restarted=createCopyModule({api,stateDir,writeEnabled:true});
 await assert.rejects(()=>restarted.call('okx.copy.stop',input,{requestId:'req-restart'}),/APPROVAL_REQUIRED|DUPLICATE_OR_UNKNOWN/);
 assert.equal(api.calls.filter(x=>x.method==='POST').length,1);
});
test('cross-account operations reject even with write flag disabled and no trader access',async()=>{
 const {mod,api}=await harness();
 await assert.rejects(()=>mod.call('okx.copy.funds.internal_transfer',{phase:'preview',account:'other',
    from:'6',to:'18',amount:'1'}),/CROSS_ACCOUNT_FORBIDDEN/);
 assert.equal(api.calls.filter(x=>x.path==='/api/v5/asset/transfer').length,0);
});
