import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, mkdir, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createCopyModule } from '../copy-module.mjs';

const UID = '822315791411831486';
const SLIME_CODE='6A48C398F18CB31C';
function fakeApi({uid=UID, restUid=uid, uncertain=false, balance='12.5', stopped=false, renamedOld=false, publicTarget=false, volatileEq=false}={}) {
  const calls=[];let balanceReads=0;let transferSeq=0;
  const old1Code=SLIME_CODE;
  const old1Name='史莱姆冲冲冲!';
  let traders=(stopped?[]:[{uniqueCode:old1Code,nickName:old1Name}]).concat([
    {uniqueCode:'OLD2',nickName:'Modern-dAPI-Manatee'},
    {uniqueCode:'KEEP1',nickName:'BestMax'},{uniqueCode:'KEEP2',nickName:'NANO IA'}]);
  let positions=stopped?[]:[{uniqueCode:old1Code,subPosId:'P1',margin:'15',upl:'-2',instId:'BTC-USDT-SWAP'}];
  return {calls,async restIdentity(){
    calls.push({method:'GET',path:'/api/v5/account/config',via:'rest'});
    return [{uid:restUid,mainUid:'MAIN_UID'}];
  },async get(path,params={}) {
    calls.push({method:'GET',path,params});
    if(path==='/api/v5/account/config') return [{uid,mainUid:'MAIN_UID'}];
    if(path==='/api/v5/account/balance') return [{details:[{ccy:'USDT',availBal:balance,cashBal:balance,eq:volatileEq?String(1200+(balanceReads++)):'1200',frozenBal:volatileEq?String(20+balanceReads):'20'}]}];
    if(path==='/api/v5/copytrading/current-lead-traders') return params.instType==='SPOT'?[]:traders;
    if(path==='/api/v5/copytrading/current-subpositions') return params.instType==='SPOT'?[]:positions;
    if(path==='/api/v5/copytrading/subpositions-history') return [];
    if(path==='/api/v5/copytrading/copy-settings') return [{copyTotalAmt:'600'}];
    if(path==='/api/v5/copytrading/public-lead-traders') return publicTarget?[{ranks:[{uniqueCode:'NEW1',nickName:'Xiaoyao Lee'}]}]:[];
    if(path==='/api/v5/asset/transfer-state') return [{state:'success',transId:params.transId}];
    return [];
  },async post(path,body){
    calls.push({method:'POST',path,body});
    if(uncertain) throw Object.assign(new Error('timeout'),{code:'ETIMEDOUT'});
    if(path==='/api/v5/copytrading/stop-copy-trading'){
      traders=traders.filter(x=>x.uniqueCode!==body.uniqueCode);
      positions=positions.filter(x=>x.uniqueCode!==body.uniqueCode);
    }
    if(path==='/api/v5/copytrading/first-copy-settings')traders.push({uniqueCode:body.uniqueCode,nickName:'Xiaoyao Lee'});
    if(path==='/api/v5/asset/transfer')return [{transId:'T'+(++transferSeq)}];
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
test('supported write previews never depend on delisted copy-position endpoints',async()=>{
  const {mod,api}=await harness();
  const out=await mod.call('okx.copy.stop',{phase:'preview',instType:'SWAP',trader:'slime198888',uniqueCode:SLIME_CODE,confirmSmartSync:true}, {requestId:'p1'});
  assert.equal(out.code,'APPROVAL_REQUIRED');assert.ok(out.plan_id);
  assert.equal(out.open_positions,'UNAVAILABLE_BY_OKX_API');
  const delisted=new Set(['/api/v5/copytrading/current-subpositions','/api/v5/copytrading/subpositions-history','/api/v5/copytrading/close-subposition']);
  assert.equal(api.calls.filter(c=>delisted.has(c.path)).length,0);
  assert.equal(api.calls.filter(c=>c.method==='POST').length,0);
});
test('writes remain disabled without gate even with a plan',async()=>{
  const {mod,api}=await harness();
  const args={phase:'preview',instType:'SWAP',trader:'slime198888',uniqueCode:SLIME_CODE,confirmSmartSync:true};
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
  const common={instType:'SWAP',trader:'slime198888',uniqueCode:SLIME_CODE,subPosCloseType:'manual_close',confirmSmartSync:true};
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
 const base={instType:'SWAP',trader:'slime198888',uniqueCode:SLIME_CODE,subPosCloseType:'manual_close',confirmSmartSync:true};
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


test('REST credential identity mismatch fails closed before copy data access',async()=>{
 const {mod,api}=await harness({restUid:'WRONG'});
 await assert.rejects(()=>mod.call('okx.copy.traders.list',{instType:'SWAP'}),/UID_MISMATCH/);
 assert.equal(api.calls.filter(x=>x.path==='/api/v5/copytrading/current-lead-traders').length,0);
});

test('transaction verify reconciles an unknown stop from read-only absence and never POSTs',async()=>{
 const {mod,api,stateDir}=await harness({stopped:true});
 await mkdir(join(stateDir,'plans'),{recursive:true});
 await mkdir(join(stateDir,'operations'),{recursive:true});
 const planId='plan-reconcile-stop';
 const operationId='op-reconcile-stop';
 const args={instType:'SWAP',trader:'slime198888',uniqueCode:SLIME_CODE,subPosCloseType:'market_close',confirmSmartSync:true};
 await writeFile(join(stateDir,'plans',planId+'.json'),JSON.stringify({
   plan_id:planId,tool:'okx.copy.stop',uid:UID,args,expires_at:Date.now()-1000
 }),{mode:0o600});
 await writeFile(join(stateDir,'operations',operationId+'.json'),JSON.stringify({
   operation_id:operationId,tool:'okx.copy.stop',uid:UID,status:'UNKNOWN',plan_id:planId,
   created_at:new Date(Date.now()-10000).toISOString()
 }),{mode:0o600});
 const out=await mod.call('okx.copy.transaction.verify',{operation_id:operationId});
 assert.equal(out.status,'VERIFIED');
 assert.equal(out.verification_basis,'READ_ONLY_TRADER_ABSENT');
 assert.equal(api.calls.filter(x=>x.method==='POST').length,0);
 assert.equal(api.calls.filter(x=>x.path==='/api/v5/copytrading/current-subpositions').length,0);
 const saved=JSON.parse(await readFile(join(stateDir,'operations',operationId+'.json'),'utf8'));
 assert.equal(saved.status,'VERIFIED');
 assert.equal(saved.verification_basis,'READ_ONLY_TRADER_ABSENT');
 assert.ok(saved.verified_at);
});


test('stop preview requires explicit Smart Sync confirmation',async()=>{
 const {mod,api}=await harness();
 await assert.rejects(()=>mod.call('okx.copy.stop',{
   phase:'preview',instType:'SWAP',trader:'slime198888',uniqueCode:SLIME_CODE
 }),/SMART_SYNC_CONFIRMATION_REQUIRED/);
 assert.equal(api.calls.filter(x=>x.method==='POST').length,0);
});

test('historical stopped-trader alias is pinned to the current exact OKX identity',async()=>{
 const {mod}=await harness({renamedOld:true});
 const out=await mod.call('okx.copy.stop',{
   phase:'preview',instType:'SWAP',trader:'slime198888',uniqueCode:SLIME_CODE,confirmSmartSync:true
 });
 assert.equal(out.code,'APPROVAL_REQUIRED');
});

test('delisted copy-position tools fail closed to assisted execution without calling removed endpoints',async()=>{
 const {mod,api}=await harness();
 for(const [tool,args] of [
   ['okx.copy.positions.list',{instType:'SWAP'}],
   ['okx.copy.positions.details',{instType:'SWAP',uniqueCode:SLIME_CODE,subPosId:'P1'}],
   ['okx.copy.history',{instType:'SWAP'}],
   ['okx.copy.profit_loss',{instType:'SWAP'}],
   ['okx.copy.positions.close',{phase:'preview',instType:'SWAP',trader:'slime198888',uniqueCode:SLIME_CODE,subPosId:'P1'}]
 ]){
   const out=await mod.call(tool,args);
   assert.equal(out.code,'UNSUPPORTED_BY_OKX',tool);
   assert.equal(out.assisted_execution?.mode,'AUTHENTICATED_OKX_UI',tool);
 }
 const delisted=new Set(['/api/v5/copytrading/current-subpositions','/api/v5/copytrading/subpositions-history','/api/v5/copytrading/close-subposition']);
 assert.equal(api.calls.filter(c=>delisted.has(c.path)).length,0);
});

test('new trader identity is verified from wrapped public ranks even when other traders are active',async()=>{
 const {mod}=await harness({publicTarget:true,balance:'500'});
 const out=await mod.call('okx.copy.trader.start',{
   phase:'preview',instType:'SWAP',trader:'Xiaoyao Lee',uniqueCode:'NEW1',
   copyMgnMode:'copy',copyInstIdType:'copy',copyMode:'ratio_copy',copyTotalAmt:'50',copyRatio:'1'
 });
 assert.equal(out.code,'APPROVAL_REQUIRED');
});

test('preflight ignores volatile equity fields but still executes only once with a grant',async()=>{
 const {mod,api,stateDir}=await harness({volatileEq:true,writeEnabled:true});
 const common={instType:'SWAP',trader:'slime198888',uniqueCode:SLIME_CODE,subPosCloseType:'market_close',confirmSmartSync:true};
 const plan=await mod.call('okx.copy.stop',{...common,phase:'preview'},{requestId:'volatile-preview'});
 await mkdir(join(stateDir,'grants'),{recursive:true});
 await writeFile(join(stateDir,'grants','VOL1.json'),JSON.stringify({
   plan_id:plan.plan_id,tool:'okx.copy.stop',uid:UID,expires_at:Date.now()+60000
 }),{mode:0o600});
 const out=await mod.call('okx.copy.stop',{...common,phase:'execute',plan_id:plan.plan_id,approval_id:'VOL1'},{requestId:'volatile-execute'});
 assert.equal(out.code,'VERIFIED');
 assert.equal(api.calls.filter(x=>x.method==='POST').length,1);
});


test('identical independently approved operations get distinct idempotency scopes',async()=>{
 const {mod,api,stateDir}=await harness({writeEnabled:true,balance:'100'});
 const common={instType:'SWAP',from:'18',to:'6',amount:'1'};
 async function executeOnce(label){
   const plan=await mod.call('okx.copy.funds.internal_transfer',{...common,phase:'preview'},{requestId:'preview-'+label});
   await mkdir(join(stateDir,'grants'),{recursive:true});
   const approval='APP-'+label;
   await writeFile(join(stateDir,'grants',approval+'.json'),JSON.stringify({
     plan_id:plan.plan_id,tool:'okx.copy.funds.internal_transfer',uid:UID,expires_at:Date.now()+60000
   }),{mode:0o600});
   return mod.call('okx.copy.funds.internal_transfer',{
     ...common,phase:'execute',plan_id:plan.plan_id,approval_id:approval
   },{requestId:'execute-'+label});
 }
 const first=await executeOnce('ONE');
 const second=await executeOnce('TWO');
 assert.equal(first.code,'VERIFIED');
 assert.equal(second.code,'VERIFIED');
 assert.notEqual(first.operation_id,second.operation_id);
 assert.equal(api.calls.filter(x=>x.method==='POST'&&x.path==='/api/v5/asset/transfer').length,2);
});
