import { createHash, randomUUID } from 'node:crypto';
import { mkdir, open, readFile, rename, unlink, readdir } from 'node:fs/promises';
import { join } from 'node:path';

export const EXPECTED_UID='822315791411831486';
const PREFIX='okx.copy.';
const ONLY_OLD=new Set(['slime198888','Modern-dAPI-Manatee']);
// Historical nicknames were confirmed by the account owner against these current OKX identities.
const VERIFIED_OLD_ALIASES=Object.freeze({
  'slime198888':{currentName:'史莱姆冲冲冲!',uniqueCode:'6A48C398F18CB31C'},
  'Modern-dAPI-Manatee':{currentName:'Bcbbi',uniqueCode:'BB3398A957270A39'}
});
const ONLY_NEW=new Set(['Caesar cipher','Xiaoyao Lee']);
const PROTECTED=new Set(['BestMax','NANO IA']);
const MODES=['SPOT','SWAP'];
const READ=['account.verify','traders.list','trader.details','balance.available','balance.allocated',
  'spot.status','futures.status','transaction.verify'];
const WRITE=['stop','funds.internal_transfer','trader.start','trader.settings.update'];
const UNSUPPORTED=['positions.list','positions.details','history','profit_loss','positions.close','funds.release'];
const REQUEST_KEYS=['instType','trader','uniqueCode','subPosId','subPosCloseType','amount','from','to',
  'copyMgnMode','copyInstIdType','copyMode','copyTotalAmt','copyAmt','copyRatio','confirmSmartSync','tpRatio','slRatio','slTotalAmt','instId'];
const fields={
  instType:{type:'string',enum:MODES},trader:{type:'string',minLength:2},uniqueCode:{type:'string',minLength:4},
  subPosId:{type:'string',minLength:1},subPosCloseType:{type:'string',enum:['market_close','copy_close','manual_close']},
  amount:{type:'string',pattern:'^[0-9]+(\\.[0-9]{1,8})?$'},from:{type:'string',enum:['6','18']},to:{type:'string',enum:['6','18']},
  copyMgnMode:{type:'string'},copyInstIdType:{type:'string'},copyMode:{type:'string'},
  copyTotalAmt:{type:'string'},copyAmt:{type:'string'},copyRatio:{type:'string'},
  tpRatio:{type:'string'},slRatio:{type:'string'},slTotalAmt:{type:'string'},instId:{type:'string'},
  phase:{type:'string',enum:['preview','execute']},plan_id:{type:'string'},approval_id:{type:'string'},confirmSmartSync:{type:'boolean'},
  transId:{type:'string'},operation_id:{type:'string'},limit:{type:'string'}
};
function fail(code){throw new Error(code)}
function label(x){return String(x?.nickName??x?.nickname??x?.traderName??x?.leadTraderName??x?.name??'').trim()}
function code(x){return String(x?.uniqueCode??'')}
function hash(x){return createHash('sha256').update(JSON.stringify(x)).digest('hex')}
function stableSnapshot(x,tool){
  let out=x;
  if(x?.balance?.trading)out={...out,balance:{...x.balance,trading:x.balance.trading.map(r=>({availBal:r.availBal??null,cashBal:r.cashBal??null}))}};
  if((tool==='stop'||tool==='positions.close')&&out?.trader)
    out={...out,trader:{uniqueCode:code(out.trader),name:label(out.trader)}};
  return out;
}
function safeId(x){if(typeof x!=='string'||!/^[a-zA-Z0-9-]{1,100}$/.test(x))fail('INVALID_IDENTIFIER');return x}
function validateMode(v){if(!MODES.includes(v))fail('INVALID_MODALITY')}
function normalizeArgs(x){return Object.fromEntries(REQUEST_KEYS.filter(k=>x[k]!==undefined).map(k=>[k,String(x[k])]))}
function currency(x){if(typeof x!=='string'||!/^(0|[1-9][0-9]*)(\.[0-9]{1,8})?$/.test(x)||Number(x)<=0||!Number.isFinite(Number(x)))fail('INVALID_AMOUNT');return x}
function ui(){return {mode:'AUTHENTICATED_OKX_UI',instructions:'Abra OKX > Trade > Bots & Copy > Copy trading > My copies, selecione Spot/Futures e confirme a identidade da subconta; nenhuma ordem ou movimentacao deve ser automatizada.'}}
async function durableWrite(path,object,{exclusive=false}={}){
  const data=JSON.stringify(object)+'\n',tmp=path+'.'+randomUUID()+'.tmp';
  const f=await open(tmp,'wx',0o600);
  try{await f.writeFile(data);await f.sync()}finally{await f.close()}
  if(exclusive){try{const g=await open(path,'wx',0o600);await g.close()}catch(e){await unlink(tmp);if(e.code==='EEXIST')fail('DUPLICATE_OR_UNKNOWN');throw e}}
  await rename(tmp,path);
  const dir=await open(join(path,'..'),'r');try{await dir.sync()}finally{await dir.close()}
}
function schemas(name){
  const props={...fields};
  const required=[];
  if(name.includes('trader.details')||name.includes('positions.details'))required.push('instType','uniqueCode');
  if(name==='positions.details')required.push('subPosId');
  if(name==='transaction.verify')required.push('operation_id');
  if(WRITE.includes(name))required.push('phase');
  return {type:'object',properties:props,required,additionalProperties:false};
}
function copyPlanArgs(a){return Object.fromEntries(Object.entries(normalizeArgs(a)).sort(([x],[y])=>x.localeCompare(y)))}
function deriveOpen(positions,uniqueCode){return positions.filter(x=>code(x)===uniqueCode)}
function sumField(rows,key){let n=0;let count=0;for(const x of rows){if(x[key]!==undefined&&Number.isFinite(Number(x[key]))){n+=Number(x[key]);count++}}return count?n.toFixed(8):null}
function validateGrants(grant,plan,tool){
  if(grant.plan_id!==plan.plan_id||grant.uid!==EXPECTED_UID||grant.tool!==tool||
    !Number.isFinite(grant.expires_at)||grant.expires_at<=Date.now())fail('APPROVAL_INVALID');
}

export function createCopyModule({api,stateDir,writeEnabled=false}){
  if(!api||!stateDir)throw new Error('MISSING_DEPENDENCY');
  const tools=[...READ,...WRITE,...UNSUPPORTED].map(n=>({
    name:PREFIX+n,description:WRITE.includes(n)?'Copy Trading: preview por padrao; execute somente com grant de operacao especifico.':
      'OKX Copy Trading: consulta oficial com UID verificado.',
    inputSchema:schemas(n)
  }));
  async function identity(){
    const accounts=await api.get('/api/v5/account/config');
    const a=accounts?.[0];
    if(!a?.uid||a.uid!==EXPECTED_UID||!a.mainUid||a.uid===a.mainUid)fail('UID_MISMATCH');
    if(typeof api.restIdentity!=='function')fail('REST_IDENTITY_UNAVAILABLE');
    const restAccounts=await api.restIdentity();
    const restAccount=restAccounts?.[0];
    if(!restAccount?.uid||restAccount.uid!==EXPECTED_UID||!restAccount.mainUid||restAccount.uid===restAccount.mainUid)fail('UID_MISMATCH');
    return {verified:true,uid:a.uid,subaccount:true,environment:'LIVE',roleType:a.roleType,spotRoleType:a.spotRoleType};
  }
  async function traders(instType){
    validateMode(instType);
    const xs=await api.get('/api/v5/copytrading/current-lead-traders',{instType});
    return Array.isArray(xs)?xs:[];
  }
  async function balance(){
    const rows=await api.get('/api/v5/account/balance',{ccy:'USDT'});
    const details=(rows||[]).flatMap(x=>x.details||[]).filter(x=>x.ccy==='USDT');
    return {currency:'USDT',trading:details.map(x=>({availBal:x.availBal??null,cashBal:x.cashBal??null,eq:x.eq??null,
      frozenBal:x.frozenBal??null})),source:'GET /api/v5/account/balance',not_fully_releasable:true};
  }
  async function read(name,a){
    switch(name){
      case 'account.verify':return identity();
      case 'traders.list':return {instType:a.instType||'SWAP',data:await traders(a.instType||'SWAP')};
      case 'trader.details':{
        validateMode(a.instType);if(!a.uniqueCode)fail('UNIQUE_CODE_REQUIRED');
        const [mine,settings,stats]=await Promise.all([
          traders(a.instType),api.get('/api/v5/copytrading/copy-settings',{instType:a.instType,uniqueCode:a.uniqueCode}),
          api.get('/api/v5/copytrading/public-stats',{instType:a.instType,uniqueCode:a.uniqueCode,lastDays:'2'})]);
        return {trader:mine.find(x=>code(x)===a.uniqueCode)||null,settings,stats};
      }
      case 'balance.available':return balance();
      case 'balance.allocated':{
        const mode=a.instType||'SWAP',all=await traders(mode);
        const items=[];
        for(const t of all){let settings;try{settings=await api.get('/api/v5/copytrading/copy-settings',{instType:mode,uniqueCode:code(t)})}catch{settings=null}
          items.push({trader:label(t),uniqueCode:code(t),settings,open_positions:'UNAVAILABLE_BY_OKX_API',capital_releasable:'UNDETERMINED'})}
        return {instType:mode,data:items,note:'OKX delisted copy-position APIs on 2024-12-16; position-level allocation is unavailable by official API.'};
      }
      case 'spot.status':
      case 'futures.status':{
        const mode=name==='spot.status'?'SPOT':'SWAP',current=await traders(mode);
        return {instType:mode,active_traders:current,open_positions:'UNAVAILABLE_BY_OKX_API',role:await api.get('/api/v5/copytrading/config')};
      }
      case 'transaction.verify':{
        safeId(a.operation_id);
        const p=join(stateDir,'operations',a.operation_id+'.json');
        let record;try{record=JSON.parse(await readFile(p,'utf8'))}catch{return {code:'TRANSACTION_NOT_FOUND'}}
        if(record.uid!==EXPECTED_UID)fail('UID_MISMATCH');
        if(record.status==='UNKNOWN'){
          let plan=null;
          try{plan=JSON.parse(await readFile(join(stateDir,'plans',String(record.plan_id||'')+'.json'),'utf8'))}catch{}
          if(record.tool==='okx.copy.stop'&&plan?.tool==='okx.copy.stop'&&plan.uid===EXPECTED_UID){
            const args=plan.args||{};
            validateMode(args.instType);
            const active=await traders(args.instType);
            const traderStillActive=active.some(x=>code(x)===String(args.uniqueCode||''));
            if(!traderStillActive){
              record.status='VERIFIED';
              record.verified_at=new Date().toISOString();
              record.verification_basis='READ_ONLY_TRADER_ABSENT';
              await durableWrite(p,record);
              return {status:'VERIFIED',operation_id:a.operation_id,verified_at:record.verified_at,
                verification_basis:record.verification_basis,position_state:'UNAVAILABLE_BY_OKX_API'};
            }
          }
          return {status:'UNKNOWN_RECONCILE_REQUIRED',operation_id:a.operation_id,
            next_step:'Inspecionar OKX; nao repetir automaticamente.'};
        }
        return {status:record.status,operation_id:a.operation_id,verified_at:record.verified_at||null,
          verification_basis:record.verification_basis||null};
      }
      default:return {code:'UNSUPPORTED_BY_OKX',assisted_execution:ui()};
    }
  }
  async function ensureTrader(tool,a,existing){
    const old=tool==='stop';
    if(PROTECTED.has(a.trader))fail('PROTECTED_TRADER');
    if(!(old?ONLY_OLD:ONLY_NEW).has(a.trader))fail('TRADER_NOT_ALLOWED');
    if(!a.uniqueCode||!a.trader)fail('TRADER_ID_REQUIRED');
    if(old){
      const mapped=VERIFIED_OLD_ALIASES[a.trader];
      if(!mapped||mapped.uniqueCode!==a.uniqueCode||
        !Array.isArray(existing)||!existing.some(t=>code(t)===mapped.uniqueCode&&label(t)===mapped.currentName))
        fail('TRADER_ID_UNVERIFIED');
      return;
    }
    if(tool==='trader.settings.update'){
      if(!Array.isArray(existing)||!existing.some(t=>code(t)===a.uniqueCode&&label(t)===a.trader))fail('TRADER_ID_UNVERIFIED');
      return;
    }
    if(Array.isArray(existing)&&existing.some(t=>code(t)===a.uniqueCode))fail('TRADER_ALREADY_ACTIVE');
    const page=await api.get('/api/v5/copytrading/public-lead-traders',{instType:a.instType,limit:'20'});
    const source=(page||[]).flatMap(item=>Array.isArray(item?.ranks)?item.ranks:[item]);
    if(!source.some(t=>code(t)===a.uniqueCode&&label(t)===a.trader))fail('TRADER_ID_UNVERIFIED');
  }
  async function snapshot(tool,a){
    if(a.subAcct||a.uid||a.account||a.destination||a.address)fail('CROSS_ACCOUNT_FORBIDDEN');
    const mode=a.instType||'SWAP';validateMode(mode);
    if(tool==='stop'&&mode!=='SWAP')fail('SOURCE_MODALITY_MISMATCH');
    if(tool==='trader.start'&&a.trader==='Caesar cipher'&&mode!=='SPOT')fail('TARGET_MODALITY_MISMATCH');
    if(tool==='trader.start'&&a.trader==='Xiaoyao Lee'&&mode!=='SWAP')fail('TARGET_MODALITY_MISMATCH');
    const [bal,active]=await Promise.all([balance(),traders(mode)]);
    if(tool==='funds.internal_transfer'){
      currency(a.amount);
      if(!(['6','18'].includes(a.from)&&['6','18'].includes(a.to)&&a.from!==a.to))fail('TRANSFER_SCOPE_FORBIDDEN');
      if(a.from==='18'&&Number(a.amount)>Number(bal.trading[0]?.availBal??0))fail('INSUFFICIENT_BALANCE');
      return {balance:bal,positions:'UNAVAILABLE_BY_OKX_API',allocated:active,scope:'SAME_SUBACCOUNT_ONLY'};
    }
    await ensureTrader(tool,a,active);
    if(tool==='stop'&&a.confirmSmartSync!==true&&a.confirmSmartSync!=='true')fail('SMART_SYNC_CONFIRMATION_REQUIRED');
    if(tool==='trader.start'||tool==='trader.settings.update'){
      for(const x of ['copyMgnMode','copyInstIdType','copyMode','copyTotalAmt'])if(!a[x])fail('COPY_SETTING_REQUIRED_'+x);
      currency(a.copyTotalAmt);
      if(tool==='trader.start'&&Number(a.copyTotalAmt)>Number(bal.trading[0]?.availBal??0))fail('INSUFFICIENT_BALANCE');
    }
    return {balance:bal,positions:'UNAVAILABLE_BY_OKX_API',trader:active.find(t=>code(t)===a.uniqueCode)||null,
      allocated_limit:'UNDETERMINED',release_estimate:'UNDETERMINED',fees:'VARIABLE',loss_exposure:'UNDETERMINED'};
  }
  async function preview(name,args){
    const a=copyPlanArgs(args);const snap=await snapshot(name,a);await mkdir(join(stateDir,'plans'),{recursive:true,mode:0o700});
    const plan_id=randomUUID(),expires_at=Date.now()+300000;
    await durableWrite(join(stateDir,'plans',plan_id+'.json'),{plan_id,tool:PREFIX+name,uid:EXPECTED_UID,args:a,snapshot:snap,expires_at});
    return {code:'APPROVAL_REQUIRED',plan_id,expires_at,uid:EXPECTED_UID,operation:name,instType:a.instType||'SWAP',
      capital_available:snap.balance,open_positions:snap.positions??'UNAVAILABLE_BY_OKX_API',fees:snap.fees||'UNKNOWN',
      potential_loss:snap.loss_exposure||'UNKNOWN',release_estimate:snap.release_estimate||'UNDETERMINED',assisted_execution:ui()};
  }
  function payload(name,a){
    if(name==='stop')return {instType:a.instType,uniqueCode:a.uniqueCode,subPosCloseType:a.subPosCloseType};
    if(name==='funds.internal_transfer')return {ccy:'USDT',amt:a.amount,from:a.from,to:a.to,type:'0'};
    const keys=['instType','uniqueCode','copyMgnMode','copyInstIdType','copyMode','copyTotalAmt','copyAmt',
      'copyRatio','tpRatio','slRatio','slTotalAmt','instId','subPosCloseType'];
    return Object.fromEntries(keys.filter(k=>a[k]!==undefined).map(k=>[k,a[k]]));
  }
  const paths={stop:'/api/v5/copytrading/stop-copy-trading',
    'funds.internal_transfer':'/api/v5/asset/transfer','trader.start':'/api/v5/copytrading/first-copy-settings',
    'trader.settings.update':'/api/v5/copytrading/amend-copy-settings'};
  async function verifyWrite(name,a,response){
    if(name==='stop')return !(await traders(a.instType)).some(x=>code(x)===a.uniqueCode);
    if(name==='trader.start')return (await traders(a.instType)).some(x=>code(x)===a.uniqueCode);
    if(name==='trader.settings.update'){
      const settings=await api.get('/api/v5/copytrading/copy-settings',{instType:a.instType,uniqueCode:a.uniqueCode});
      return !!settings?.length&&settings.some(s=>String(s.copyTotalAmt||'')===String(a.copyTotalAmt));
    }
    if(name==='funds.internal_transfer'){
      const id=response?.[0]?.transId;if(!id)return false;
      const status=await api.get('/api/v5/asset/transfer-state',{transId:id});
      return status?.some(x=>x.state==='success'||x.state==='2');
    }
    return false;
  }
  async function execute(name,args,{requestId}={}){
    if(!writeEnabled)fail('WRITE_DISABLED');
    if(name==='stop'&&!args.subPosCloseType)fail('CLOSE_POLICY_REQUIRED');
    safeId(requestId);
    safeId(args.plan_id);safeId(args.approval_id);
    const base=join(stateDir,'plans');let plan;try{plan=JSON.parse(await readFile(join(base,args.plan_id+'.json'),'utf8'))}catch{fail('PLAN_NOT_FOUND')}
    if(plan.tool!==PREFIX+name||plan.uid!==EXPECTED_UID||Date.now()>plan.expires_at||
      hash(plan.args)!==hash(copyPlanArgs(args)))fail('STALE_OR_MISMATCHED_PLAN');
    let grant;try{grant=JSON.parse(await readFile(join(stateDir,'grants',args.approval_id+'.json'),'utf8'))}catch{fail('APPROVAL_REQUIRED')}
    validateGrants(grant,plan,PREFIX+name);
    await mkdir(join(stateDir,'operations'),{recursive:true,mode:0o700});
    const op_id=hash({tool:name,plan_id:plan.plan_id,args_hash:hash(plan.args),uid:EXPECTED_UID});const file=join(stateDir,'operations',op_id+'.json');
    try{await readFile(file);fail('DUPLICATE_OR_UNKNOWN')}catch(e){if(e?.message==='DUPLICATE_OR_UNKNOWN')throw e;if(e.code!=='ENOENT')throw e}
    const lock=join(stateDir,'.execution-lock');let lf;try{lf=await open(lock,'wx',0o600)}catch{fail('EXECUTION_LOCKED')}
    try{
      await identity();
      const current=await snapshot(name,plan.args);
      if(hash(stableSnapshot(current,name))!==hash(stableSnapshot(plan.snapshot,name)))fail('STALE_PREFLIGHT');
      const record={operation_id:op_id,tool:PREFIX+name,uid:EXPECTED_UID,request_id:requestId,
        args_hash:hash(plan.args),plan_id:plan.plan_id,status:'UNKNOWN',created_at:new Date().toISOString()};
      await durableWrite(file,record);
      await rename(join(stateDir,'grants',args.approval_id+'.json'),join(stateDir,'grants',args.approval_id+'.consumed'));
      let result;
      try{result=await api.post(paths[name],payload(name,plan.args))}catch{
        fail('UNKNOWN_RECONCILE_REQUIRED');
      }
      let verified=false;try{verified=await verifyWrite(name,plan.args,result)}catch{}
      if(!verified)fail('UNKNOWN_RECONCILE_REQUIRED');
      record.status='VERIFIED';record.verified_at=new Date().toISOString();await durableWrite(file,record);
      return {code:'VERIFIED',operation_id:op_id,uid:EXPECTED_UID};
    }finally{await lf?.close();await unlink(lock).catch(()=>{})}
  }
  async function call(tool,args={},{requestId}={}){
    if(!tool.startsWith(PREFIX)||!tools.some(t=>t.name===tool))fail('UNKNOWN_TOOL');
    if(!args||Array.isArray(args)||typeof args!=='object')fail('INVALID_ARGUMENTS');
    if(args.subAcct||args.uid||args.account||args.destination||args.address)fail('CROSS_ACCOUNT_FORBIDDEN');
    const name=tool.slice(PREFIX.length);
    if(UNSUPPORTED.includes(name))return {code:'UNSUPPORTED_BY_OKX',assisted_execution:ui()};
    await identity();
    if(READ.includes(name))return read(name,args);
    if(!WRITE.includes(name))fail('UNKNOWN_TOOL');
    if(args.phase==='preview')return preview(name,args);
    if(args.phase==='execute')return execute(name,args,{requestId});
    fail('PHASE_REQUIRED');
  }
  async function health(){
    let pending=0;try{pending=(await readdir(join(stateDir,'operations'))).length}catch{}
    return {enabled:true,write_enabled:writeEnabled,uid_required:EXPECTED_UID,tool_count:tools.length,
      credential_verification:'PER_CALL',journal_entries:pending};
  }
  return {call,listTools:()=>tools,health};
}
