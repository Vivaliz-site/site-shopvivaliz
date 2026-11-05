<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
if (empty($_SESSION['sv_ml_margin_csrf'])) {
    $_SESSION['sv_ml_margin_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['sv_ml_margin_csrf'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Auditoria de Margens Mercado Livre — ShopVivaliz</title>
<style>
:root{--ink:#18304f;--blue:#173b63;--sea:#087f77;--warn:#a65306;--bad:#a92a38;--muted:#64748b;--line:#dbe4ed;--bg:#f3f7fb}
*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:var(--ink)}
header{background:var(--blue);color:#fff;display:flex;justify-content:space-between;align-items:center;gap:16px;padding:17px 24px;flex-wrap:wrap}
header h1{font-size:1.35rem;margin:0}header a{color:#fff;font-weight:600}
main{max-width:1480px;margin:auto;padding:20px}
.card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:18px;margin-bottom:16px;box-shadow:0 1px 2px #173b6308}
.notice{border-left:4px solid var(--warn);background:#fffaf2;padding:14px 17px;border-radius:8px;line-height:1.5}
.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px}
.metric span{display:block;color:var(--muted);font-size:.8rem}.metric strong{display:block;font-size:1.8rem;margin-top:6px}
.muted{color:var(--muted)}.small{font-size:.87rem}.danger{color:var(--bad)}.positive{color:#147548}.warning{color:var(--warn)}
.flex{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.spread{justify-content:space-between}
h2{font-size:1.06rem;margin:0 0 8px}p{line-height:1.5}
button,select,input[type=search],input[type=text]{font:inherit;border:1px solid #bdcbd9;border-radius:7px;background:#fff;padding:9px 12px;color:var(--ink)}
button{font-weight:650;cursor:pointer}button:disabled{cursor:not-allowed;opacity:.46}
button.primary{background:var(--blue);color:#fff;border-color:var(--blue)}button.teal{background:var(--sea);color:#fff;border-color:var(--sea)}
input[type=search]{flex:1;min-width:230px}select{min-width:165px}
.table-wrap{overflow:auto;margin-top:14px}table{border-collapse:collapse;width:100%;min-width:1200px;font-size:.86rem}
th,td{padding:10px 11px;border-bottom:1px solid #e5edf3;text-align:left;white-space:nowrap}
th{background:#f1f6fa;color:#52647a;font-size:.75rem;text-transform:uppercase;letter-spacing:.025em}
tr:hover{background:#f8fbfd}th:first-child,td:first-child{position:sticky;left:0;background:#fff}
td code{font-size:.86rem;color:var(--blue)}td.num,th.num{text-align:right}
.pill{display:inline-block;padding:4px 8px;border-radius:6px;background:#eaf0f7;font-size:.76rem;white-space:normal}
progress{width:100%;height:15px;margin-top:12px;accent-color:var(--sea)}
#feedback{min-height:1.45em;font-weight:650;padding:8px 0}#show-more{margin-top:14px}
@media(max-width:900px){.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}main{padding:12px}.card{padding:13px}}
@media(max-width:560px){header{padding:14px}.flex button{flex:1}input[type=search]{min-width:100%}}
</style>
</head>
<body>
<header>
 <div><h1>Auditoria de Margens · Mercado Livre</h1><div class="small">ShopVivaliz · API oficial Mercado Livre + Olist/Tiny</div></div>
 <a href="/admin/mercadolivre.php">Voltar ao Mercado Livre</a>
</header>
<main>
 <div class="notice card">
  Auditoria de contribuição por <strong>anúncio MLB individual</strong>, sem agrupar anúncios que usam o mesmo SKU no ERP.
  Taxas, frete do vendedor, custo e impostos precisam de fonte válida; dados ausentes são sinalizados como <strong>INCOMPLETE</strong>.
  Esta etapa <strong>não altera preços, anúncios ou estoque</strong>.
 </div>
 <div class="metrics" aria-label="Resumo de anúncios">
  <div class="card metric"><span>Anúncios sincronizados</span><strong id="total">—</strong></div>
  <div class="card metric"><span>Margem estimada menor que 10%</span><strong id="risk" class="danger">—</strong></div>
  <div class="card metric"><span>Margem estimada de 10% ou mais</span><strong id="healthy" class="positive">—</strong></div>
  <div class="card metric"><span>Incompletos / falhas</span><strong id="incomplete" class="warning">—</strong></div>
 </div>
 <section class="card" aria-live="polite" aria-label="Andamento">
  <div class="flex spread"><div><h2 id="activity">Verificando integração...</h2><div class="muted small" id="activity-note">Nenhuma auditoria foi iniciada nesta sessão.</div></div><strong class="small" id="activity-count">—</strong></div>
  <progress id="progress" max="100" value="0" hidden></progress>
 </section>
 <section class="card">
  <div class="flex spread">
   <div><h2>Auditar e exportar</h2><div class="muted small" id="last-run">Os anúncios dependem de sincronização prévia.</div></div>
   <div class="flex">
    <button id="audit-selected" type="button" class="primary" disabled>Auditar selecionados</button>
    <button id="audit-all" type="button" class="teal" disabled>Auditar todos</button>
   </div>
  </div>
  <p class="muted small">O processamento é assíncrono: clique, acompanhe o progresso e retorne à página. O backend não inicia um segundo processamento para a mesma conta enquanto houver uma auditoria ativa.</p>
  <div class="flex">
   <button id="refresh" type="button">Atualizar</button>
   <button id="resume" type="button" disabled>Retomar fila</button>
   <button id="export-pdf" type="button" disabled>Exportar PDF</button>
   <button id="export-xlsx" type="button" disabled>Exportar Excel</button>
   <span class="muted small" id="selected-count">Nenhum anúncio selecionado</span>
  </div>
  <div id="feedback" role="status" aria-live="polite"></div>
 </section>
 <section class="card" aria-label="Simulador de preço sem publicação">
  <h2>Simular outro preço, sem alterar o anúncio</h2>
  <p class="muted small">Selecione exatamente um anúncio ativo sem variações e informe o preço desejado. Esta simulação recalcula apenas a estimativa com as taxas, frete e custos disponíveis. <strong>Nenhuma alteração é enviada ao Mercado Livre ou ao ERP.</strong></p>
  <div class="flex">
   <label for="simulate-price" class="small">Preço de venda (R$)</label>
   <input id="simulate-price" type="text" inputmode="decimal" maxlength="11" placeholder="Ex.: 159,90" aria-label="Preço para simulação">
   <button id="simulate" type="button" disabled>Calcular margem simulada</button>
  </div>
  <div class="muted small" id="simulation-result" role="status" aria-live="polite">Selecione um anúncio para simular.</div>
 </section>
 <section class="card">
  <h2>Anúncios Mercado Livre</h2>
  <div class="flex">
   <input id="search" type="search" placeholder="Buscar código MLB, SKU ou modalidade" aria-label="Buscar anúncios">
   <select id="filter" aria-label="Filtrar resultado">
    <option value="all">Todos os resultados</option>
    <option value="risk">Margem menor que 10%</option>
    <option value="healthy">Margem de 10% ou mais</option>
    <option value="incomplete">Incompletos / falhas</option>
    <option value="pending">Ainda não auditados</option>
   </select>
   <label class="flex small" for="only-active"><input id="only-active" type="checkbox"> Somente ativos</label>
   <span id="status-breakdown" class="muted small">Status ML aguardando sincronização</span>
   <span id="shown" class="muted small"></span>
  </div>
  <div class="table-wrap">
   <table>
    <thead><tr>
     <th><input type="checkbox" id="select-visible" aria-label="Selecionar anúncios visíveis"></th>
     <th>MLB</th><th>SKU vendedor</th><th>Variações</th><th>Tipo</th><th>Logística</th><th>Status ML</th><th>Situação da auditoria</th>
     <th class="num">Receita (R$)</th><th class="num">Custo ERP (R$)</th><th class="num">Imposto (R$)</th>
     <th class="num">Taxas (R$)</th><th class="num">Frete vendedor (R$)</th>
     <th class="num">Contribuição (R$)</th><th class="num">Margem (%)</th>
    </tr></thead>
    <tbody id="rows"><tr><td colspan="15" class="muted">Carregando anúncios...</td></tr></tbody>
   </table>
  </div>
  <button id="show-more" type="button" hidden>Mostrar mais anúncios</button>
 </section>
</main>
<script>
'use strict';
const csrf = <?php echo json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR); ?>;
const endpoint = '/admin/mercadolivre-margin-api.php';
const $ = id => document.getElementById(id);
const state = {listings:[], results:new Map(), selected:new Set(), status:null, filter:'all', activeOnly:false, visibleLimit:100, updating:false, simulating:false, simulatedItem:null, configured:false};
const completeStatuses = new Set(['COMPLETED','COMPLETED_WITH_ERRORS']);
const processingStatuses = new Set(['QUEUED','RUNNING','WAITING_PROVIDER']);
const columns = 15;
function feedback(message, bad=false){
 $('feedback').textContent=message;
 $('feedback').className=bad?'danger':'';
}
async function request(action, opts={}){
 const query = new URLSearchParams({action, ...opts.query});
 const response = await fetch(endpoint+'?'+query, {
  method:opts.method||'GET',
  headers:{'Accept':'application/json',...(opts.method==='POST'?{'Content-Type':'application/json','X-CSRF-Token':csrf}:{})},
  body:opts.method==='POST'?JSON.stringify(opts.body||{}):undefined,
  credentials:'same-origin',
  cache:'no-store',
 });
 let result;
 try{result=await response.json();}catch{throw new Error('Resposta inesperada do servidor.');}
 if(!response.ok){
  const code=result?.error?.code||'BACKEND_UNAVAILABLE';
  if(code==='ML_MARGIN_NOT_CONFIGURED')throw new Error('Integração ainda não provisionada: conta e credencial da API de margens pendentes.');
  if(code==='AUDIT_ALREADY_RUNNING_DIFFERENT_SELECTION')throw new Error('Há outra auditoria em execução para esta conta, com uma seleção diferente. Aguarde a conclusão antes de auditar outro conjunto de anúncios.');
  throw new Error(code);
 }
 return result;
}
function metric(id,value){$(id).textContent=value;}
function money(x){
 if(x===null||x===undefined||x==='')return '—';
 const n=Number(x);return Number.isFinite(n)?n.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}):'—';
}
function percent(x){
 if(x===null||x===undefined||x==='')return '—';
 const n=Number(x);return Number.isFinite(n)?n.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}):'—';
}
const missingReasonLabels = Object.freeze({
 'MISSING_COST':'Custo, embalagem ou despesa variável sem comprovação',
 'MISSING_TAX_PROFILE':'Imposto deste anúncio não configurado',
 'MISSING_FEE_QUOTE':'Tarifa de venda do ML não obtida',
 'MISSING_SHIPPING_QUOTE':'Frete do vendedor ainda não cotado',
 'PROMOTION_FUNDING_RECONCILIATION_REQUIRED':'Promoção exige conciliação',
 'REQUIRES_ACTUAL_RECONCILIATION':'Requer conciliação de valores efetivos',
});
const auditErrorLabels = Object.freeze({
 'MARKETPLACE_PRICE_NOT_SYNCED':'Preço vigente ainda não sincronizado',
 'VARIATIONS_REQUIRE_INDIVIDUAL_AUDIT':'Variações precisam de auditoria por SKU',
 'LISTING_NOT_ACTIVE':'Anúncio não está ativo',
 'LISTING_STATUS_UNKNOWN':'Status do anúncio ainda não sincronizado',
 'ITEM_NOT_FOUND':'Anúncio não encontrado na conta',
});
function missingReasons(row){
 const raw=row?.missing_inputs_json;
 if(!raw)return [];
 let values=raw;
 try{if(typeof raw==='string')values=JSON.parse(raw);}catch{return ['Motivos inválidos no relatório'];}
 if(!Array.isArray(values))return ['Motivos inválidos no relatório'];
 return [...new Set(values.filter(x=>typeof x==='string'))].map(x=>missingReasonLabels[x]||x);
}
function diagnostic(row){
 if(!row)return 'Não auditado';
 if(row.audit_status==='COMPLETE')return 'Completo';
 if(row.audit_status==='QUEUED')return 'Na fila';
 const issues=missingReasons(row);
 const error=row.error_code?(auditErrorLabels[row.error_code]||row.error_code):'';
 const summary=[...(error?[error]:[]),...issues].join(' · ');
 const label=row.audit_status==='FAILED'?'Falha':'Incompleto';
 return summary?label+' · '+summary:label;
}
function resultCategory(listing){
 const row=state.results.get(listing.item_id);
 if(!row||row.audit_status==='QUEUED')return 'pending';
 if(row.audit_status!=='COMPLETE')return 'incomplete';
 const margin=Number(row.contribution_margin_pct);
 if(!Number.isFinite(margin))return 'incomplete';
 return margin<10?'risk':'healthy';
}
function mlStatusText(status){
 if(status==='active')return 'Ativo';
 if(status==='paused')return 'Pausado';
 if(status==='under_review')return 'Em análise';
 if(status==='closed')return 'Encerrado';
 return status||'Não sincronizado';
}
function filtered(){
 const q=$('search').value.trim().toLowerCase();
 return state.listings.filter(l=>{
  const hay=[l.item_id,l.seller_sku,l.listing_type_id,l.logistic_type,...(l.variants||[]).map(v=>v.seller_sku||'')].join(' ').toLowerCase();
  return (!state.activeOnly||l.status==='active')&&(!q||hay.includes(q))&&(state.filter==='all'||resultCategory(l)===state.filter);
 });
}
function createCell(tr,text,classes=''){
 const td=document.createElement('td');
 td.textContent=String(text??'—');td.className=classes;tr.appendChild(td);
 return td;
}
function render(){
 let risk=0, healthy=0, incomplete=0;
 for(const l of state.listings){const status=resultCategory(l);if(status==='risk')risk++;if(status==='healthy')healthy++;if(status==='incomplete')incomplete++;}
 metric('total',state.configured?state.listings.length:'—');
 metric('risk',state.status?risk:'—');
 metric('healthy',state.status?healthy:'—');
 metric('incomplete',state.status?incomplete:'—');
 const view=filtered(), subset=view.slice(0,state.visibleLimit);
 $('shown').textContent=view.length+' anúncios encontrados';
 $('selected-count').textContent=state.selected.size+' selecionados';
 const activeCount=state.listings.filter(l=>l.status==='active').length;
 const pausedCount=state.listings.filter(l=>l.status==='paused').length;
 const reviewCount=state.listings.filter(l=>l.status==='under_review').length;
 const unknownCount=state.listings.filter(l=>!l.status).length;
 $('status-breakdown').textContent=state.configured
  ? activeCount+' ativos · '+pausedCount+' pausados · '+reviewCount+' em análise'+(unknownCount?' · '+unknownCount+' sem status':'')
  : 'Status ML aguardando sincronização';
 $('audit-all').textContent=state.activeOnly?'Auditar todos os ativos':'Auditar todos';
 $('audit-all').disabled=!state.configured||(state.activeOnly?activeCount===0:!state.listings.length)||state.updating;
 $('audit-selected').disabled=!state.configured||state.selected.size===0||state.updating;
 const simulationId=state.selected.size===1?[...state.selected][0]:null;
 const simulationListing=state.listings.find(l=>l.item_id===simulationId);
 const eligibleSimulation=!!simulationListing&&simulationListing.status==='active'&&(simulationListing.variants||[]).length===0;
 $('simulate').disabled=!state.configured||!eligibleSimulation||state.updating||state.simulating;
 if(state.simulatedItem!==null&&state.simulatedItem!==simulationId){
  state.simulatedItem=null;
  $('simulation-result').textContent='Selecione um anúncio e calcule uma nova simulação; nenhum preço foi alterado.';
 }
 const table=$('rows');table.replaceChildren();
 if(!subset.length){const tr=document.createElement('tr');const td=createCell(tr,state.configured?'Nenhum anúncio corresponde ao filtro.':'Aguardando a integração da API.');td.colSpan=columns;table.appendChild(tr);}
 for(const listing of subset){
  const r=state.results.get(listing.item_id)||null;
  const tr=document.createElement('tr');
  const td=document.createElement('td');
  const cb=document.createElement('input');cb.type='checkbox';cb.checked=state.selected.has(listing.item_id);cb.setAttribute('aria-label','Selecionar '+listing.item_id);
  cb.addEventListener('change',()=>{if(cb.checked)state.selected.add(listing.item_id);else state.selected.delete(listing.item_id);render();});
  td.appendChild(cb);tr.appendChild(td);
  createCell(tr,listing.item_id);
  createCell(tr,listing.seller_sku||'Sem SKU');
  createCell(tr,(listing.variants||[]).length);
  createCell(tr,listing.listing_type_id||'—');
  createCell(tr,[listing.shipping_mode,listing.logistic_type].filter(Boolean).join(' / ')||'—');
  createCell(tr,mlStatusText(listing.status));
   createCell(tr,diagnostic(r));
  createCell(tr,money(r?.effective_revenue),'num');
  createCell(tr,money(r?.product_cost),'num');
  createCell(tr,money(r?.tax_amount),'num');
  createCell(tr,money(r?.sale_fee_net),'num');
  createCell(tr,money(r?.seller_shipping_cost),'num');
  createCell(tr,money(r?.contribution_margin),'num');
  createCell(tr,percent(r?.contribution_margin_pct),'num');
  table.appendChild(tr);
 }
 $('show-more').hidden=view.length<=subset.length;
 $('select-visible').checked=!!subset.length&&subset.every(l=>state.selected.has(l.item_id));
}
async function loadStatus(){
 try{
  const status=await request('status');
  state.status=status;
  const done=Number(status.processed_items||0), total=Number(status.total_items||0);
  $('activity').textContent='Auditoria '+status.status;
  $('activity-count').textContent=done+' / '+total;
  $('activity-note').textContent=(status.incomplete_items||0)+' incompletos · '+(status.failed_items||0)+' falhas';
  $('last-run').textContent='Execução: '+status.run_id+' · '+status.scope;
  $('progress').hidden=false;
  $('progress').value=total?Math.min(100,100*done/total):0;
  const complete=completeStatuses.has(status.status);
  $('export-pdf').disabled=!complete;
  $('export-xlsx').disabled=!complete;
  $('resume').disabled=!processingStatuses.has(status.status);
  await loadRows(total);
 }catch(e){
  if(e.message==='AUDIT_NOT_SELECTED'||e.message==='AUDIT_NOT_FOUND'){
   state.status=null;$('activity').textContent='Nenhuma auditoria ativa';$('activity-note').textContent='Selecione anúncios ou clique em Auditar todos.';$('activity-count').textContent='—';
   $('progress').hidden=true;$('export-pdf').disabled=true;$('export-xlsx').disabled=true;$('resume').disabled=true;
  }else{feedback('Não foi possível consultar a auditoria: '+e.message,true);}
 }
 render();
}
async function loadRows(total){
 const collected=new Map(), pageSize=200;
 for(let offset=0;offset<total&&offset<20000;offset+=pageSize){
  const data=await request('rows',{query:{offset,limit:pageSize}});
  const values=Array.isArray(data.rows)?data.rows:[];
  for(const row of values)if(row&&typeof row.item_id==='string')collected.set(row.item_id,row);
  if(values.length<pageSize)break;
 }
 state.results=collected;
}
async function refresh(){
 if(state.updating)return;
 state.updating=true;render();
 try{
  const data=await request('listings');
  state.listings=Array.isArray(data.listings)?data.listings:[];
  state.configured=true;
  state.selected=new Set([...state.selected].filter(id=>state.listings.some(l=>l.item_id===id&&(!state.activeOnly||l.status==='active'))));
  await loadStatus();
  if(!state.listings.length)feedback('A API está conectada, mas o catálogo ainda não foi sincronizado.',true);
  else feedback('Catálogo consultado: '+state.listings.length+' anúncios.');
 }catch(e){
  state.configured=false;state.listings=[];state.results=new Map();feedback(e.message,true);
  $('activity').textContent='Integração indisponível';$('activity-note').textContent='A auditoria não será executada até a conexão ser validada.';
 }
 finally{state.updating=false;render();}
}
function canonicalPreviewPrice(raw){
 const value=raw.trim().replace(',', '.');
 if(!/^(?:0|[1-9][0-9]{0,6})(?:\.[0-9]{1,2})?$/.test(value))return null;
 const n=Number(value);
 return Number.isFinite(n)&&n>0&&n<=1000000?value:null;
}
async function simulatePrice(){
 if(state.updating||state.simulating||!state.configured)return;
 if(state.selected.size!==1){feedback('Selecione exatamente um anúncio para simular.',true);return;}
 const itemId=[...state.selected][0];
 const listing=state.listings.find(l=>l.item_id===itemId);
 if(!listing||listing.status!=='active'||(listing.variants||[]).length){
  feedback('A simulação exige anúncio ativo, sem variações, identificado no catálogo.',true);return;
 }
 const price=canonicalPreviewPrice($('simulate-price').value);
 if(price===null){feedback('Informe um preço positivo em reais, sem separador de milhar e com até duas casas decimais.',true);return;}
 state.simulating=true;
 $('simulation-result').textContent='Consultando as taxas, o frete e os dados de margem para '+itemId+'...';
 render();
 try{
  const quote=await request('preview',{method:'POST',body:{item_id:itemId,requested_price:price}});
  if(quote.subject_id!==itemId||quote.state!=='ESTIMATED'||quote.sources?.price?.origin!=='REQUESTED_PRICE'){
   throw new Error('A API devolveu uma cotação diferente da simulação solicitada.');
  }
  const missing=Array.isArray(quote.completeness?.missing)?quote.completeness.missing.map(x=>missingReasonLabels[x]||x):[];
  const costs=quote.components||{};
  const fields=[
   'MLB '+itemId,
   'Preço simulado: R$ '+money(quote.effective_revenue),
   'Custo produto: R$ '+money(costs.product_cost),
   'Embalagem: R$ '+money(costs.packaging_cost),
   'Outras despesas: R$ '+money(costs.other_variable_cost),
   'Imposto: R$ '+money(costs.tax_amount),
   'Tarifas: R$ '+money(costs.sale_fee_net),
   'Frete vendedor: R$ '+money(costs.seller_shipping_cost),
   'Contribuição estimada: R$ '+money(quote.contribution_margin),
   'Margem estimada: '+percent(quote.contribution_margin_pct)+'%',
   'Confiabilidade: '+(quote.completeness?.status||'INCOMPLETE'),
  ];
  if(missing.length)fields.push('Pendências: '+missing.join('; '));
  fields.push('SIMULAÇÃO: preço NÃO alterado no Mercado Livre nem no ERP.');
  $('simulation-result').textContent=fields.join(' · ');
  state.simulatedItem=itemId;
  feedback('Simulação calculada. Nenhum anúncio ou produto ERP foi alterado.');
 }catch(e){
  $('simulation-result').textContent='Simulação não concluída: '+e.message;
  feedback('Simulação não concluída: '+e.message,true);
 }finally{
  state.simulating=false;render();
 }
}
async function start(scope){
 if(state.updating)return;
 let ids=scope==='SELECTED'?[...state.selected]:[];
 if(state.activeOnly){
  const activeIds=new Set(state.listings.filter(l=>l.status==='active').map(l=>l.item_id));
  ids=scope==='ALL'?[...activeIds]:ids.filter(id=>activeIds.has(id));
  if(!ids.length){feedback('Nenhum anúncio ativo com status oficial confirmado foi encontrado.',true);return;}
  // The backend ALL scope includes paused listings; send vetted ACTIVE IDs.
  scope='SELECTED';
 }
 if(scope==='SELECTED'&&!ids.length)return;
 state.updating=true;render();feedback('Enviando auditoria à fila...');
 try{
  const response=await request('start',{method:'POST',body:{scope,item_ids:scope==='SELECTED'?ids:[]}});
  feedback(response.already_active?'Auditoria já em execução: acompanhando o mesmo run.':'Auditoria enfileirada; o progresso aparecerá nesta página.');
  await loadStatus();
 }catch(e){feedback('Não foi possível iniciar a auditoria: '+e.message,true);}
 finally{state.updating=false;render();}
}
$('audit-all').addEventListener('click',()=>start('ALL'));
$('simulate').addEventListener('click',simulatePrice);
$('audit-selected').addEventListener('click',()=>start('SELECTED'));
$('refresh').addEventListener('click',refresh);
$('search').addEventListener('input',()=>{state.visibleLimit=100;render();});
$('filter').addEventListener('change',e=>{state.filter=e.target.value;state.visibleLimit=100;render();});
$('only-active').addEventListener('change',e=>{
 state.activeOnly=e.target.checked;
 if(state.activeOnly){
  const allowed=new Set(state.listings.filter(l=>l.status==='active').map(l=>l.item_id));
  state.selected=new Set([...state.selected].filter(id=>allowed.has(id)));
 }
 state.visibleLimit=100;render();
});
$('show-more').addEventListener('click',()=>{state.visibleLimit+=100;render();});
$('select-visible').addEventListener('change',e=>{for(const l of filtered().slice(0,state.visibleLimit)){if(e.target.checked)state.selected.add(l.item_id);else state.selected.delete(l.item_id);}render();});
$('resume').addEventListener('click',async()=>{try{await request('resume',{method:'POST'});feedback('Retomada solicitada com sucesso.');await loadStatus();}catch(e){feedback(e.message,true);}});
for(const format of ['pdf','xlsx']){
 $('export-'+format).addEventListener('click',()=>{if(state.status&&completeStatuses.has(state.status.status)){window.location.assign(endpoint+'?action=export&format='+format);}});
}
refresh();
setInterval(()=>{if(state.configured&&state.status&&processingStatuses.has(state.status.status)&&!state.updating)loadStatus();},5000);
</script>
</body>
</html>
