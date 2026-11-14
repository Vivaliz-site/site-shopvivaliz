<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/admin-guard.php';
$channel=(string)($_GET['channel']??'');
if(!in_array($channel,['shopee','tiktok'],true)){http_response_code(404);exit('Canal inexistente');}
if(empty($_SESSION['sv_marketplace_margin_csrf']))$_SESSION['sv_marketplace_margin_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['sv_marketplace_margin_csrf'];
$label=$channel==='shopee'?'Shopee':'TikTok Shop';
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Margens <?=htmlspecialchars($label,ENT_QUOTES,'UTF-8')?> — ShopVivaliz</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f5f8fc;color:#152a45;font:14px system-ui,Arial,sans-serif}
header{background:#153759;color:white;padding:17px 22px}header h1{font-size:1.5rem;margin:0}header a{color:white}
main{max-width:1450px;padding:16px;margin:auto}.card{background:white;border:1px solid #dae3ec;border-radius:12px;padding:16px;margin-bottom:13px}
.metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px}
.metric strong{display:block;font-size:1.7rem}.metric small{color:#60748c}
.row{display:flex;flex-wrap:wrap;gap:9px;align-items:center}button,select,input{font:inherit;padding:10px;border:1px solid #b7c6d6;border-radius:7px;background:white}
button{cursor:pointer;font-weight:750;color:#13496e}button:disabled{opacity:.5;cursor:not-allowed}
button.primary{background:#144d76;color:white;border-color:#144d76}
input[type=search]{flex:1;min-width:200px}
table{border-collapse:collapse;width:100%;min-width:1120px}td,th{padding:10px;border-bottom:1px solid #e6ebf1;text-align:left}
th{background:#f0f5fa;font-size:12px;color:#526680}tr:hover{background:#f6faff}
.scroll{overflow:auto}#feedback{min-height:1.5em;color:#144d76;font-weight:650;margin-top:12px}
.warn{color:#975d0d}.bad{color:#b02c31}.good{color:#12774d}.muted{color:#63758a}p{line-height:1.5}
@media(max-width:700px){.metrics{grid-template-columns:repeat(2,1fr)}main{padding:9px}}
</style></head>
<body><header><h1>Auditoria de Margens · <?=htmlspecialchars($label,ENT_QUOTES,'UTF-8')?></h1>
<a href="/admin/margens-marketplaces.php">Voltar à Central de Margens</a></header>
<main>
<div class="card"><p>Imposto: 14% · Margem-alvo: 9%. Cada anúncio e variação é calculado separadamente. Os custos dos kits vêm da composição no ERP. A ausência de taxa/frete/custo não representa margem zero. Nenhuma ação abaixo altera anúncios ou preços.</p></div>
<div class="metrics">
<div class="card metric"><small>Anúncios ativos</small><strong id="total">—</strong></div>
<div class="card metric"><small>Margem inferior a 9%</small><strong class="bad" id="risk">—</strong></div>
<div class="card metric"><small>Margem ≥ 9%</small><strong class="good" id="healthy">—</strong></div>
<div class="card metric"><small>Pendentes de dados</small><strong class="warn" id="pending">—</strong></div>
</div>
<section class="card"><h2>Auditoria independente</h2>
<p class="muted" id="activity">Consultando estado da API...</p>
<div class="row">
<button id="sync" type="button">Sincronizar catálogo oficial</button>
<button id="run-all" class="primary" type="button">Auditar ativos</button>
<button id="run-selected" type="button" disabled>Auditar selecionados</button>
<button id="refresh" type="button">Atualizar</button>
<button id="pdf" type="button" disabled>Exportar PDF</button>
<button id="xlsx" type="button" disabled>Exportar Excel</button>
</div><div id="feedback" aria-live="polite" role="status"></div>
</section>
<section class="card"><h2>Simular preço (sem publicação)</h2>
<p class="muted">Selecione um anúncio e informe preço em reais. A prévia não reutiliza cotação de outra faixa como se fosse tarifa oficial.</p>
<div class="row"><input id="target-price" type="text" inputmode="decimal" placeholder="Ex.: 99,90"><button id="preview" type="button">Simular</button></div>
<p id="preview-result" class="muted">Nenhuma simulação realizada.</p></section>
<section class="card"><h2>Anúncios</h2><div class="row">
<input id="search" type="search" placeholder="Buscar SKU, anúncio ou título">
<select id="filter"><option value="all">Todos</option><option value="risk">Abaixo de 9%</option><option value="healthy">9% ou acima</option><option value="pending">Incompletos</option></select>
<label><input type="checkbox" id="active" checked> Somente ativos</label>
<span id="selection" class="muted">0 selecionados</span>
</div>
<div class="scroll"><table><thead><tr><th>Selecionar</th><th>ID</th><th>SKU</th><th>Título</th><th>Situação</th><th>Preço (R$)</th><th>Custo (R$)</th><th>Imposto (R$)</th><th>Taxas (R$)</th><th>Frete (R$)</th><th>Lucro (R$)</th><th>Margem</th><th>Detalhe</th></tr></thead><tbody id="rows"><tr><td colspan="13">Carregando...</td></tr></tbody></table></div></section>
</main>
<script>
'use strict';
const CHANNEL=<?=json_encode($channel,JSON_HEX_TAG|JSON_HEX_AMP|JSON_THROW_ON_ERROR)?>;
const CSRF=<?=json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP|JSON_THROW_ON_ERROR)?>;
const el=id=>document.getElementById(id);
const state={listings:[],results:new Map(),selected:new Set(),audit:null,busy:false};
const money=c=>Number.isInteger(c)?(c/100).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2}):'—';
const pct=b=>Number.isInteger(b)?(b/100).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2})+'%':'—';
const active=r=>['active','live','buyable','published'].includes(String(r.status||'').toLowerCase());
const status=r=>{if(!r||r.status==='INCOMPLETE')return 'pending';return r.margin_bps<900?'risk':'healthy';};
function feedback(message,error=false){el('feedback').textContent=message;el('feedback').className=error?'bad':'';}
async function request(action,options={}){
 const u=new URL('/api/admin/marketplace-margin.php',location.origin);
 u.searchParams.set('channel',CHANNEL);u.searchParams.set('action',action);
 if(options.id)u.searchParams.set('id',options.id);
 const response=await fetch(u,{method:options.body?'POST':'GET',headers:{Accept:'application/json',...(options.body?{'Content-Type':'application/json','X-CSRF-Token':CSRF}:{})},body:options.body?JSON.stringify(options.body):undefined,credentials:'same-origin',cache:'no-store'});
 const data=await response.json();
 if(!response.ok)throw Error(data.error||'HTTP_'+response.status);
 return data;
}
function filtered(){
 const q=el('search').value.trim().toLowerCase(),f=el('filter').value;
 return state.listings.filter(x=>(!el('active').checked||active(x))&&
 (!q||[x.listing_id,x.seller_sku,x.title].join(' ').toLowerCase().includes(q))&&
 (f==='all'||status(state.results.get(x.listing_id))===f));
}
function render(){
 let risk=0,healthy=0,pending=0;
 const listed=state.listings.filter(x=>!el('active').checked||active(x));
 for(const x of listed){const cat=status(state.results.get(x.listing_id));if(cat==='risk')risk++;else if(cat==='healthy')healthy++;else pending++;}
 el('total').textContent=listed.length;el('risk').textContent=risk;el('healthy').textContent=healthy;el('pending').textContent=pending;
 el('selection').textContent=state.selected.size+' selecionado(s)';
 el('run-all').disabled=state.busy||!listed.length;
 el('run-selected').disabled=state.busy||!state.selected.size;
 el('sync').disabled=state.busy;
 const body=el('rows');body.replaceChildren();
 for(const x of filtered().slice(0,500)){
  const r=state.results.get(x.listing_id), tr=document.createElement('tr');
  const td=document.createElement('td'),check=document.createElement('input');
  check.type='checkbox';check.checked=state.selected.has(x.listing_id);
  check.addEventListener('change',()=>{check.checked?state.selected.add(x.listing_id):state.selected.delete(x.listing_id);render()});
  td.appendChild(check);tr.appendChild(td);
  const values=[x.listing_id,x.seller_sku||'—',x.title||'—',r?.status||'PENDENTE',
     money(x.price_cents),money(r?.product_cost_cents),money(r?.tax_cents),money(r?.fee_cents),
     money(r?.seller_shipping_cents),money(r?.profit_cents),pct(r?.margin_bps),
     (r?.missing||[]).join(' · ')||r?.basis||''];
  for(const value of values){const cell=document.createElement('td');cell.textContent=String(value);tr.appendChild(cell)}
  body.appendChild(tr);
 }
 if(!body.childElementCount){const tr=document.createElement('tr');const td=document.createElement('td');td.colSpan=13;td.textContent='Nenhum anúncio corresponde aos filtros';tr.appendChild(td);body.appendChild(tr)}
}
async function latest(){
 const a=await request('audits');
 const audit=(a.audits||[])[0];
 if(!audit){state.audit=null;state.results.clear();el('activity').textContent='Nenhuma auditoria executada';el('pdf').disabled=el('xlsx').disabled=true;return;}
 state.audit=audit;
 el('activity').textContent='Auditoria '+audit.status+' · '+audit.complete+' completos · '+audit.incomplete+' incompletos · '+audit.created_at;
 const done=audit.status==='COMPLETE';
 el('pdf').disabled=el('xlsx').disabled=!done;
 if(done){const payload=await request('audit',{id:audit.id});state.results=new Map((payload.rows||[]).map(r=>[r.listing_id,r]));}
}
async function refresh(){
 try{const data=await request('listings');state.listings=data.listings||[];await latest();feedback('Catálogo atualizado.');render();}
 catch(e){feedback('API indisponível: '+e.message,true);render()}
}
async function run(scope){
 state.busy=true;render();feedback('Auditoria enfileirada...');
 try{
 const ids=scope==='SELECTED'?[...state.selected]:[];
 const data=await request('start',{body:{scope,ids,active_only:el('active').checked}});
 feedback('Execução '+data.audit_id+' iniciada.');
 for(let i=0;i<25;i++){await new Promise(resolve=>setTimeout(resolve,800));await latest();render();if(state.audit?.status==='COMPLETE'||state.audit?.status==='FAILED')break;}
 }catch(e){feedback(e.message,true)}finally{state.busy=false;render()}
}
el('refresh').addEventListener('click',refresh);
el('sync').addEventListener('click',async()=>{state.busy=true;render();feedback('Consultando API oficial...');try{const r=await request('sync',{body:{}});feedback(r.imported+' anúncios sincronizados');await refresh()}catch(e){feedback('Sincronização não concluída: '+e.message,true)}finally{state.busy=false;render()}});
el('run-all').addEventListener('click',()=>run('ALL'));
el('run-selected').addEventListener('click',()=>run('SELECTED'));
el('search').addEventListener('input',render);
el('filter').addEventListener('change',render);
el('active').addEventListener('change',render);
el('preview').addEventListener('click',async()=>{
 const raw=el('target-price').value.trim().replace(',','.');
 if(state.selected.size!==1||!/^[0-9]{1,7}(?:\.[0-9]{1,2})?$/.test(raw)){
 feedback('Selecione um único anúncio e informe preço válido.',true);return;}
 const price=Math.round(Number(raw)*100);
 try{
 const r=await request('preview',{body:{listing_id:[...state.selected][0],price_cents:price}});
 el('preview-result').textContent='Prévia '+r.result.status+' · margem '+pct(r.result.margin_bps)+' · recomendação '+r.result.price_proposal.status+' · preço NÃO publicado.';
 }catch(e){feedback(e.message,true)}
});
for(const fmt of ['pdf','xlsx'])el(fmt).addEventListener('click',()=>{
 if(!state.audit||state.audit.status!=='COMPLETE')return;
 const u=new URL('/api/admin/marketplace-margin.php',location.origin);
 u.searchParams.set('channel',CHANNEL);u.searchParams.set('action','export');u.searchParams.set('id',state.audit.id);u.searchParams.set('format',fmt);
 location.href=u;
});
refresh();
</script></body></html>
