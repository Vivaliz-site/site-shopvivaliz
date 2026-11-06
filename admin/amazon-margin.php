<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/admin-guard.php';
if (empty($_SESSION['sv_margin_csrf_token'])) {
    $_SESSION['sv_margin_csrf_token']=bin2hex(random_bytes(32));
}
$csrf=(string)$_SESSION['sv_margin_csrf_token'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Auditoria de Margens Amazon — ShopVivaliz</title>
<link rel="stylesheet" href="/css/style.css">
<style>
:root{--a:#17365d;--b:#0d9488;--muted:#64748b;--line:#dce4ed;--bg:#f5f7fb}
*{box-sizing:border-box}
body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:#16263b}
header{background:var(--a);color:#fff;padding:16px 22px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}
header a{color:#fff;font-weight:700}
main{max-width:1460px;margin:auto;padding:22px}
h1{margin:0;font-size:1.35rem}h2{margin:0;font-size:1.05rem}
small,.muted{color:var(--muted)}.small{font-size:.85rem}
.card{background:#fff;border:1px solid var(--line);border-radius:13px;padding:18px;margin-bottom:18px}
.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.metric strong{display:block;font-size:1.75rem;margin-top:6px}.metric span{font-size:.82rem;color:var(--muted)}
.metric small{display:block;font-size:.75rem;color:var(--muted)}
.filter-active{display:flex;align-items:center;gap:8px;font-weight:700;border:1px solid #99d6c6;background:#f0fdf9;border-radius:8px;padding:9px 12px;cursor:pointer}
.filter-active input{width:18px;height:18px;accent-color:#0f766e;cursor:pointer}
.cell-note{display:block;font-size:.73rem;color:#64748b;margin-top:2px;max-width:170px;white-space:normal}
.cell-note.warn{color:#aa5f13}

.controls{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
input[type=search],input[type=number],select{font:inherit;border:1px solid #cbd5e1;border-radius:7px;padding:10px;background:white;color:#152b43}
input[type=search]{min-width:240px;flex:2}
select{min-width:140px}
button{padding:10px 13px;border-radius:8px;border:1px solid #bac8d8;background:white;color:#17365d;cursor:pointer;font-weight:650}
button.primary{background:var(--a);color:white;border-color:var(--a)}
button.teal{background:var(--b);color:white;border-color:var(--b)}
button:disabled{opacity:.45;cursor:not-allowed}
button:hover:not(:disabled){filter:brightness(.96)}
.table-scroller{overflow:auto;margin-top:13px}
table{border-collapse:collapse;width:100%;font-size:.88rem;min-width:1120px}
thead{background:#f0f4f9}
th,td{padding:11px 9px;text-align:left;border-bottom:1px solid #e7edf4;white-space:nowrap}
th{font-weight:700;color:#44546b;font-size:.76rem;text-transform:uppercase;letter-spacing:.025em}
td strong{display:block}td code{font-size:.83rem;color:#284965}
.right{text-align:right}.positive{color:#13794f;font-weight:750}.danger{color:#bd3429;font-weight:750}.warning{color:#ab6a11;font-weight:750}
.pill{display:inline-block;font-size:.75rem;padding:3px 7px;border-radius:6px;background:#e9eff6;color:#345}
.alert{border-left:4px solid #b45309;background:#fff7ed;padding:13px 16px;margin-bottom:15px;border-radius:8px}
.note{font-size:.84rem;line-height:1.5;color:#475569}
#feedback{min-height:1.4em;color:#17365d;font-weight:650}
#simulator[hidden]{display:none}
#simulator .controls{margin-top:12px}
@media(max-width:920px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}main{padding:12px}.card{padding:12px}}
@media(max-width:550px){.cards{grid-template-columns:1fr 1fr}header{padding:12px}input[type=search]{min-width:100%;width:100%}button{flex:1}}
</style>
</head>
<body>
<header>
  <div><h1>Auditoria de Margens Amazon</h1><div class="small">ShopVivaliz · Olist/Tiny + SP-API</div></div>
  <a href="/admin/">Voltar ao Admin</a>
</header>
<main>
  <div class="alert">Primeira fase: <strong>auditoria e relatórios somente leitura</strong>. Alterações de preço serão liberadas apenas após validação da sincronização individual Amazon/ERP e do impacto no site.</div>
  <div class="cards">
    <div class="card metric"><span id="n-total-label">Anúncios ativos</span><strong id="n-total">—</strong><small id="n-total-context">—</small></div>
    <div class="card metric"><span>Abaixo de 10%</span><strong class="danger" id="n-risk">—</strong></div>
    <div class="card metric"><span>Margem confirmada ≥10%</span><strong class="positive" id="n-good">—</strong></div>
    <div class="card metric"><span>Pendentes / sem confirmação</span><strong class="warning" id="n-pending">—</strong></div>
  </div>
  <section class="card" id="audit-live" role="status" aria-live="polite">
    <div class="controls" style="justify-content:space-between">
      <div><h2 id="activity-title">Verificando a fila de auditorias…</h2>
        <p class="note" id="activity-detail">Consultando o estado do processamento.</p></div>
      <strong id="activity-count" class="muted small"></strong>
    </div>
    <progress id="activity-progress" max="100" value="0" style="width:100%;height:14px;margin-top:12px" hidden></progress>
    <div id="activity-next" class="note small" style="margin-top:8px"></div>
  </section>
  <section class="card">
    <div class="controls" style="justify-content:space-between">
      <div><h2>Seleção e atualização</h2><span class="muted small" id="last-run">Carregando auditoria…</span></div>
      <div class="controls">
        <button type="button" class="primary" id="run-selected" disabled>Auditar selecionados</button>
        <button type="button" class="teal" id="run-all">Auditar todos</button>
      </div>
    </div>
    <p class="note">A auditoria utiliza a fila da VM Marina e consulta as APIs Amazon/Olist. O estado é atualizado automaticamente nesta tela. Repetir o clique não inicia outra auditoria idêntica enquanto a primeira estiver pendente.</p>
    <div class="controls">
      <button type="button" id="reload">Atualizar dados</button>
      <button type="button" id="pdf" disabled>Exportar PDF</button>
      <button type="button" id="xlsx" disabled>Exportar Excel</button>
      <span id="selected-count" class="muted small">Nenhum selecionado (exportação seguirá o filtro de ativos)</span>
    </div>
    <div id="feedback" role="status" aria-live="polite"></div>
  </section>
  <section class="card" id="olist-import-section">
    <h2>Vínculos dos anúncios no ERP Olist</h2>
    <p class="note">Exporte os anúncios em <strong>Cadastros → Anúncios</strong> das integrações
      <strong>Amazon</strong>, <strong>Amazon Onsite</strong> e <strong>Amazon Classic</strong>,
      e selecione os arquivos originais Excel (.xls/.xlsx) ou CSV. O sistema cruza o código
      exato do anúncio com o SKU do produto ativo na API Olist. Exportações com mais de
      <strong>48 horas</strong> são referências históricas e <strong>não certificam custo ou margem</strong>.
      Nenhuma alteração é realizada na Amazon ou no ERP.</p>
    <div class="controls">
      <label for="olist-files"><strong>Exportações Olist</strong></label>
      <input id="olist-files" type="file" multiple accept=".xls,.xlsx,.csv"
        aria-label="Selecionar até cinco exportações de anúncios Olist">
      <button type="button" id="olist-import" disabled>Importar vínculos e auditar</button>
    </div>
    <p class="note small" id="olist-import-status" role="status" aria-live="polite">
      Consultando a última importação…
    </p>
  </section>
  <section class="card">
    <h2>Anúncios Amazon</h2>
    <p class="note">O filtro de ativos considera anúncios com status <strong>BUYABLE</strong> na Amazon. Anúncios só DISCOVERABLE não estão com oferta comprável. A auditoria completa continua verificando todo o catálogo, independente deste filtro.</p>
    <p class="note"><strong>Preço e custo são por unidade VENDIDA</strong> (kit completo, quando aplicável). O lucro por unidade vendida é separado do <strong>lucro total acumulado</strong> nas vendas conciliadas. Produtos simples vendidos como kit usam custo da peça multiplicado pela quantidade confirmada no anúncio; kits cadastrados no ERP não são multiplicados novamente.</p>
    <p class="note" id="prior-cost-indicator" hidden></p>
    <div class="controls" style="margin-top:12px">
      <input id="search" type="search" placeholder="Buscar produto, SKU Amazon, SKU ERP, ASIN…" aria-label="Buscar anúncio">
      <select id="program"><option value="">Todos os programas</option><option value="FBA_ONSITE">FBA Onsite</option><option value="DBA">DBA</option><option value="UNKNOWN">Não identificado</option></select>
      <select id="status"><option value="">Todos os resultados</option><option value="low">Abaixo de 10%</option><option value="healthy">≥ 10%</option><option value="pending">Margem não apurada</option><option value="unmapped">Vínculo ERP não identificado</option><option value="no_sales">Sem vendas no período</option><option value="no_price">Sem preço efetivo</option><option value="erp_error">Falha/ambiguidade ERP</option></select>
      <label class="filter-active" for="only-active"><input type="checkbox" id="only-active" checked> Somente ativos <span id="active-count" class="small muted"></span></label>
      <button type="button" id="select-visible">Selecionar visíveis</button>
      <button type="button" id="clear-selected">Limpar seleção</button>
    </div>
    <div class="table-scroller">
      <table>
        <thead><tr><th><input id="select-page" type="checkbox" aria-label="Selecionar página"></th><th>SKU Amazon</th><th>Produto ERP</th><th>Modalidade</th><th>Status Amazon</th><th class="right">Preço</th><th class="right">Custo</th><th class="right">Vendas</th><th class="right">Margem</th><th class="right">Lucro/unidade vendida</th><th class="right">Lucro total do período</th><th>Diagnóstico</th><th>Ações</th></tr></thead>
        <tbody id="rows"><tr><td colspan="13">Carregando…</td></tr></tbody>
      </table>
    </div>
    <div class="controls" style="margin-top:12px"><span id="count" class="muted small"></span><button id="more" type="button" hidden>Mostrar mais</button></div>
  </section>
  <section class="card" id="simulator" hidden>
    <h2>Simulação de preço</h2>
    <p class="note">Esta prévia não modifica ERP nem Amazon. Recalcula os impostos usando a taxa configurada, mas mantém as demais tarifas absolutas da última amostra. Pode mudar a comissão e a faixa logística; o resultado <strong>não é margem confirmada</strong>.</p>
    <div class="controls"><strong id="sim-name"></strong><label for="sim-price">Novo preço (R$)</label><input type="number" id="sim-price" step="0.01" min="0.01"><button id="sim-calc" type="button">Simular</button><button type="button" id="sim-close">Fechar</button></div>
    <p id="sim-result" role="status"></p>
  </section>
  <section class="card">
    <h2>Histórico das solicitações</h2>
    <div class="table-scroller"><table style="min-width:800px"><thead><tr><th>ID</th><th>Escopo</th><th>Situação</th><th>Etapa</th><th>Progresso</th><th>Solicitada em</th><th>Próxima tentativa / concluída</th></tr></thead><tbody id="requests"><tr><td colspan="7">—</td></tr></tbody></table></div>
  </section>
</main>
<script>
'use strict';
const CSRF=<?php echo json_encode($csrf,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR); ?>;
const API='/api/admin/amazon-margin.php';
const el=id=>document.getElementById(id);
const escapeHtml=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let data=[], selected=new Set(), displayed=[], shown=75, taxBps=1400, currentSim=null;
let lastRunId=null, submitBusy=false, pollBusy=false, activeAll=false;
const stageLabels={
 queued:'Na fila',starting:'Iniciando',loading_listings:'Localizando anúncios Amazon',
 loading_prices:'Consultando preços Amazon',mapping_erp_products:'Vinculando produtos Olist/Tiny',
 reading_transactions:'Conciliando tarifas e fretes',calculating_margins:'Calculando margens',
 saving_report:'Salvando relatório',waiting_amazon_quota:'Aguardando liberação da cota Amazon',
 waiting_olist_quota:'Aguardando liberação da cota Olist/Tiny',
 retry_pending:'Aguardando nova tentativa',completed:'Concluída',coalesced:'Solicitação repetida',
 failed:'Falha registrada'
};
function dateUtc(value){
 if(!value)return '—';
 const d=new Date(String(value).replace(' ','T')+(String(value).includes('Z')?'':'Z'));
 return Number.isNaN(+d)?String(value):d.toLocaleString('pt-BR');
}
async function readJson(res){
 const type=res.headers.get('content-type')||'';
 if(res.redirected||!type.includes('application/json'))
   throw new Error('Sessão administrativa expirada. Atualize a página e faça login novamente.');
 const j=await res.json();
 if(!res.ok||!j.ok)throw new Error(j.error||'Falha da API de auditoria');
 return j;
}
function queueDisplay(requests,meta){
 const list=Array.isArray(requests)?requests:[];
 const active=list.find(r=>r.state==='running')||list.find(r=>r.state==='queued');
 activeAll=list.some(r=>r.scope==='all'&&(r.state==='running'||r.state==='queued'));
 el('run-all').disabled=submitBusy||activeAll;
 el('run-all').textContent=activeAll?'Auditoria completa em processamento':'Auditar todos';
 if(active){
  const kind=active.scope==='all'?'todos os anúncios':'anúncios selecionados';
  const running=active.state==='running';
  const done=Number(active.progress_done||0),total=Number(active.progress_total||0);
  const stage=running&&(!active.stage||active.stage==='queued')
   ?'Processando, aguardando atualização do worker'
   :(stageLabels[active.stage]||active.stage||'Na fila');
  el('activity-title').textContent='Auditoria #'+active.id+' — '+(running?'em execução':'aguardando na fila');
  el('activity-detail').textContent=kind+' · '+stage+(total>0?' · '+done+' de '+total:'');
  el('activity-count').textContent=(running?'1 em execução · ':'')+
   list.filter(r=>r.state==='queued').length+' na fila';
  const bar=el('activity-progress');
  bar.hidden=!running||total<=0;
  if(!bar.hidden){bar.value=Math.min(100,Math.round(100*done/total));bar.setAttribute('aria-label',stage+': '+done+' de '+total);}
  const time=active.next_attempt_at?'Próxima tentativa: '+dateUtc(active.next_attempt_at):
   running&&active.heartbeat_at?'Última atualização: '+dateUtc(active.heartbeat_at):
   running&&active.started_at?'Iniciada em: '+dateUtc(active.started_at):'';
  el('activity-next').textContent=time+(meta?' · Último relatório concluído: #'+meta.run_id+' ('+meta.offer_count+' anúncios)':'');
 }else{
  el('activity-title').textContent=meta?'Auditoria #'+meta.run_id+' concluída':'Nenhuma auditoria concluída';
  el('activity-detail').textContent=meta?meta.offer_count+' anúncios no último relatório · '+dateUtc(meta.generated_at):'Clique em Auditar todos para iniciar.';
  el('activity-count').textContent='Fila sem execução ativa';
  el('activity-progress').hidden=true;
  el('activity-next').textContent='';
 }
 el('requests').innerHTML=list.map(r=>{
  const progress=r.progress_total>0?r.progress_done+' / '+r.progress_total:
   r.progress_done>0?String(r.progress_done):'—';
  return '<tr><td>#'+Number(r.id)+'</td><td>'+escapeHtml(r.scope==='all'?'Todos':'Selecionados')+'</td>'
    +'<td>'+escapeHtml(r.state)+'</td><td>'+escapeHtml(stageLabels[r.stage]||r.stage||'—')+'</td>'
    +'<td>'+escapeHtml(progress)+'</td><td>'+escapeHtml(dateUtc(r.created_at))+'</td>'
    +'<td>'+escapeHtml(dateUtc(r.next_attempt_at||r.finished_at))+'</td></tr>';
 }).join('')||'<tr><td colspan="7">Nenhuma solicitação.</td></tr>';
}
async function loadStatus(){
 if(pollBusy)return;
 pollBusy=true;
 try{
  const res=await fetch(API+'?action=status',{credentials:'same-origin',cache:'no-store'});
  const j=await readJson(res);
  queueDisplay(j.requests,j.latest_meta);
  if(j.latest_meta && j.latest_meta.run_id!==lastRunId){
   await load({quiet:true});
   el('feedback').textContent='Auditoria #'+j.latest_meta.run_id+
    ' concluída. Relatório atualizado com '+j.latest_meta.offer_count+' anúncios; PDF e Excel disponíveis.';
  }
 }catch(e){showError(e);}
 finally{pollBusy=false;}
}
const price=v=>v===null||v===undefined?'—':'R$ '+Number(v).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
const margin=v=>v===null||v===undefined?'—':(v/100).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2})+'%';
function isActive(r){return Array.isArray(r.amazon_status)&&r.amazon_status.includes('BUYABLE');}
function program(r){return r.program||(r.fulfillment_channel==='AMAZON'?'FBA_ONSITE':r.fulfillment_channel==='MERCHANT'?'MERCHANT':'UNKNOWN');}
function programLabel(r){const p=program(r);return p==='FBA_ONSITE'?'FBA Onsite':p==='DBA'?'DBA':p==='DBA_CANDIDATE'||p==='MERCHANT'?'Envio vendedor':p==='UNKNOWN'?'Não identificado':p;}
function state(r){if(r.margin_min_bps===null||r.margin_min_bps===undefined)return 'pending';return r.margin_min_bps<1000?'low':'healthy';}
function selectionVisible(){const current=new Set(displayed.map(r=>String(r.seller_sku)));return [...selected].filter(s=>current.has(s));}
function refreshSelection(){
 const visible=selectionVisible(),hidden=selected.size-visible.length;
 el('selected-count').textContent=visible.length+' selecionado(s) neste filtro'+
  (hidden?' · '+hidden+' oculto(s) por outros filtros':'')+
  (!selected.size?' · PDF/Excel incluirão os '+displayed.length+' anúncios filtrados':'');
 el('run-selected').disabled=submitBusy||visible.length===0||visible.length>1000;
}
function filterMatch(r,s){
 if(s==='')return true;
 if(s==='low'||s==='healthy'||s==='pending')return state(r)===s;
 if(s==='unmapped')return r.status==='unmapped_erp';
 if(s==='no_sales')return r.status==='no_confirmed_sales';
 if(s==='no_price')return r.price===null||r.price===undefined;
 if(s==='erp_error')return ['erp_or_catalog_read_failed','ambiguous_erp','erp_identity_mismatch','kit_cost_unverified'].includes(r.status);
 return true;
}
function filtered(){
 const q=el('search').value.trim().toLowerCase();
 const p=el('program').value,s=el('status').value;
 const only=el('only-active').checked;
 return data.filter(r=>(!only||isActive(r))
  &&(!q||[r.seller_sku,r.erp_sku,r.asin,r.title,r.ean].some(x=>String(x??'').toLowerCase().includes(q)))
  &&(!p||program(r)===p||(p==='DBA'&&program(r)==='DBA_CANDIDATE'))
  &&filterMatch(r,s));
}
const reasonText={
 confirmed:'Margem apurada pelas vendas conciliadas',
 partial:'Parte dos fretes/lançamentos ainda pendente de conciliação',
 unmapped_erp:'Produto ERP não identificado por EAN, SKU, pedido Amazon ou fonte atual Olist',
 ambiguous_erp:'Vínculo ERP ambíguo (EAN, pedidos ou relação atual Olist divergentes)',
 erp_or_catalog_read_failed:'Falha de consulta ao ERP ou catálogo Amazon',
 erp_identity_mismatch:'Identificação de produto divergente entre Amazon e ERP',
 price_or_cost_missing:'Preço efetivo ou custo de aquisição indisponível',
 kit_cost_unverified:'Quantidade ou composição do kit sem prova suficiente; margem bloqueada',
 price_divergence:'Preço da oferta divergente do cadastro',
 fulfillment_unverified:'Modalidade logística não identificada',
 no_confirmed_sales:'Sem vendas financeiras conciliadas no período',
};
function priceMarkup(r){
 if(r.price!==null&&r.price!==undefined)return price(r.price);
 if(r.listed_price!==null&&r.listed_price!==undefined)
  return price(r.listed_price)+'<span class="cell-note warn">Preço do cadastro Amazon; oferta não confirmada</span>';
 return '—<span class="cell-note warn">Preço não disponível</span>';
}
function costMarkup(r){
 if(r.last_cost!==null&&r.last_cost!==undefined) {
  const multiplier=Number(r.kit_cost_multiplier||1);
  const desc=multiplier>1
   ?'<span class="cell-note">Peça '+price(r.erp_base_cost)+' × '+multiplier+' unidades = custo do kit completo</span>'
   :r.kit_cost_basis==='ERP_COMPLETE_KIT_COST'
    ?'<span class="cell-note">Kit completo conferido com a soma dos componentes do Olist: '+price(r.erp_kit_bom_cost)+'. Sem multiplicar duas vezes.</span>'
    :'<span class="cell-note">Custo por unidade vendida</span>';
  return price(r.last_cost)+desc+(r.last_cost_date?'<span class="cell-note">Custo de '+escapeHtml(r.last_cost_date)+'</span>':'');
 }
 if(r.status==='kit_cost_unverified') {
  const registered=Number(r.erp_base_cost||0),bom=Number(r.erp_kit_bom_cost||0);
  const trace=registered>0&&bom>0
    ?'Custo histórico do kit '+price(registered)+' difere da soma dos componentes '+price(bom)+'.'
    :'Composição ou custo unitário dos componentes não comprovado.';
  return '—<span class="cell-note warn">'+trace+
    ' <strong>Custo e margem bloqueados até reconciliação.</strong></span>';
 }
 const prior=r.prior_cost_reference;
 if(prior&&Number.isFinite(Number(prior.cost))&&Number(prior.cost)>0) {
  return price(prior.cost)+'<span class="cell-note warn">Último custo verificado em auditoria #'+
    escapeHtml(prior.verified_run_id)+' ('+escapeHtml(prior.cost_date||'')+
    '). <strong>Referência histórica; não validado nesta execução.</strong></span>';
 }
 return '—<span class="cell-note warn">'+(r.status==='unmapped_erp'?'Produto relacionado ainda não identificado pela auditoria':
   r.status==='ambiguous_erp'?'Vínculo ERP ambíguo':
   r.status==='erp_or_catalog_read_failed'?'Consulta ERP falhou':
   r.status==='kit_cost_unverified'?'Quantidade/composição do kit não comprovada':'Custo não identificado')+'</span>';
}
function refreshMetrics(){
 const active=data.filter(isActive).length;
 const base=el('only-active').checked?data.filter(isActive):data;
 const healthy=base.filter(r=>state(r)==='healthy').length;
 const low=base.filter(r=>state(r)==='low').length;
 el('n-total-label').textContent=el('only-active').checked?'Anúncios ativos':'Anúncios cadastrados';
 el('n-total').textContent=base.length;
 el('n-total-context').textContent=active+' ativos de '+data.length+' no catálogo';
 el('active-count').textContent='('+active+')';
 el('n-risk').textContent=low;
 el('n-good').textContent=healthy;
 el('n-pending').textContent=base.length-low-healthy;
 const fallback=base.filter(r=>r.last_cost===null&&r.status!=='kit_cost_unverified'
   &&r.prior_cost_reference?.cost>0).length;
 const indicator=el('prior-cost-indicator');
 indicator.hidden=fallback===0;
 indicator.textContent=fallback+' anúncios com último custo de uma auditoria anterior. Esses valores são históricos, não custos confirmados nesta execução, e não habilitam margem certificada.';
}
function render(){
 refreshMetrics();
 displayed=filtered();
 const subset=displayed.slice(0,shown);
 el('rows').innerHTML=subset.map(r=>{
  const sku=String(r.seller_sku??'');
  const chosen=selected.has(sku);
  const st=state(r),active=isActive(r);
  const diag=r.erp_ui_conflict
   ?'Divergência entre vínculo da interface atual Olist e identificação anterior; custo e margem não certificados'
   :(reasonText[r.status]||String(r.status??'Não verificado'));
  const marginText=r.margin_weighted_bps===null||r.margin_weighted_bps===undefined
   ?'—<span class="cell-note warn">Margem não apurada</span>'
   :margin(r.margin_weighted_bps)+(r.status==='partial'?'<span class="cell-note warn">Conciliação parcial</span>':'');
  const simOk=r.price!==null&&r.price!==undefined&&r.margin_weighted_bps!==null&&r.margin_weighted_bps!==undefined;
  return '<tr><td><input type="checkbox" class="row-check" data-sku="'+escapeHtml(sku)+'" '+(chosen?'checked':'')+' aria-label="Selecionar '+escapeHtml(sku)+'"></td>'
   +'<td><strong><code>'+escapeHtml(sku)+'</code></strong><span class="cell-note">'+escapeHtml((r.title||'').slice(0,95))+'</span></td>'
   +'<td>'+escapeHtml(r.erp_sku||'—')+'<span class="cell-note">ASIN '+escapeHtml(r.asin||'—')+'</span>'+
     (r.ean?'<span class="cell-note">EAN '+escapeHtml(r.ean)+'</span>':'')+
     (r.erp_integration?'<span class="cell-note">ERP: '+escapeHtml(r.erp_integration)+'</span>':'')+
     (r.erp_match_method==='OLIST_AMAZON_ORDER'?'<span class="cell-note">Vínculo por '+Number(r.erp_order_evidence_count||0)+' pedido(s) Amazon/Olist</span>':'')+
     (r.erp_match_method==='OLIST_ADS_EXPORT'?'<span class="cell-note">Vínculo por exportação Olist recente, validado contra o produto ERP ativo em '+escapeHtml(r.erp_export_observed_at||'')+'</span>':'')+
     (r.erp_match_method==='OLIST_AUTHENTICATED_UI'?'<span class="cell-note">Vínculo pela interface autenticada de anúncios Olist, produto ativo confirmado na API em '+escapeHtml(r.erp_ui_observed_at||'')+'</span>':'')+
     (r.erp_ui_verified_same_product?'<span class="cell-note">Anúncio confirmado na interface atual Olist</span>':'')+
     (r.erp_ui_conflict?'<span class="cell-note warn">CONFLITO ERP: interface atual indica '+escapeHtml(r.erp_ui_candidate_sku||'sem SKU')+'. Custo e margem bloqueados até conciliação.</span>':'')+
     (r.erp_historical_ui_candidate?'<span class="cell-note warn">Captura antiga da interface Olist, SKU candidato '+escapeHtml(r.erp_historical_ui_candidate.erp_sku||'')+'; sem certificação.</span>':'')+
     (r.erp_historical_candidate?'<span class="cell-note warn">Exportação histórica: SKU candidato '+escapeHtml(r.erp_historical_candidate.erp_sku||'')+' ('+escapeHtml(r.erp_historical_candidate.observed_at||'')+'). Sem custo confirmado neste vínculo.</span>':'')+
     (r.erp_gtin_differs_from_amazon?'<span class="cell-note warn">GTIN do anúncio difere do ERP; vínculo conferido via pedido</span>':'')+'</td>'
   +'<td>'+escapeHtml(programLabel(r))+'</td>'
   +'<td><span class="pill">'+(active?'Ativo · BUYABLE':'Sem oferta ativa')+'</span></td>'
   +'<td class="right">'+priceMarkup(r)+'</td>'
   +'<td class="right">'+costMarkup(r)+'</td>'
   +'<td class="right">'+Number(r.confirmed_sales||0)+'<span class="cell-note">'+Number(r.confirmed_units||0)+' unidade(s) vendida(s)</span></td>'
   +'<td class="right '+(st==='low'?'danger':st==='healthy'?'positive':'warning')+'">'+marginText+'</td>'
   +'<td class="right">'+(r.margin_weighted_bps!==null&&r.margin_weighted_bps!==undefined&&Number(r.confirmed_units||0)>0?price(Number(r.profit_cents||0)/100/Number(r.confirmed_units)):'—')+'</td>'
   +'<td class="right">'+(r.margin_weighted_bps!==null&&r.margin_weighted_bps!==undefined?price(Number(r.profit_cents||0)/100):'—')+(r.status==='partial'?'<span class="cell-note warn">Parcial</span>':'')+'</td>'
   +'<td><span class="pill">'+escapeHtml(diag)+'</span></td>'
   +'<td><button type="button" data-sim="'+escapeHtml(sku)+'" '+(simOk?'':'disabled title="Precisa de preço e margem calculada"')+'>Simular</button></td></tr>';
 }).join('')||'<tr><td colspan="13">Nenhum anúncio corresponde aos filtros. Desmarque Somente ativos para consultar os demais.</td></tr>';
 el('count').textContent=displayed.length+' anúncio(s) encontrados neste filtro; '+subset.length+' exibidos.';
 el('more').hidden=shown>=displayed.length;
 el('select-page').checked=subset.length>0&&subset.every(r=>selected.has(String(r.seller_sku??'')));
 refreshSelection();
}
function showError(e){el('feedback').textContent=String(e.message||e).slice(0,200);}
async function load({quiet=false}={}){
 if(!quiet)el('feedback').textContent='Consultando último relatório…';
 try{
  const res=await fetch(API+'?action=latest',{credentials:'same-origin',cache:'no-store'});
  const json=await readJson(res);
  data=json.latest?.snapshot?.rows||[];
  taxBps=Number(json.latest?.snapshot?.tax_bps||1400);
  lastRunId=json.latest?.run_id??null;
  // Counters are recalculated by render() according to Somente ativos.
  el('last-run').textContent=json.latest?'Último relatório concluído: #'+json.latest.run_id+' · '+dateUtc(json.latest.generated_at)+' · '+data.length+' anúncios':'Nenhum relatório persistido';
  el('pdf').disabled=el('xlsx').disabled=data.length===0;
  queueDisplay(json.requests,json.latest?{run_id:json.latest.run_id,scope:json.latest.scope,generated_at:json.latest.generated_at,offer_count:data.length}:null);
  selected=new Set([...selected].filter(s=>data.some(r=>r.seller_sku===s)));
  render();
  if(!quiet)el('feedback').textContent='Relatório consultado. O andamento das novas auditorias aparece acima.';
 }catch(e){
  showError(e);
  if(!data.length)el('rows').innerHTML='<tr><td colspan="11">Não foi possível consultar o relatório. Verifique o erro acima.</td></tr>';
 }
}
async function requestRun(scope){
 if(submitBusy)return;
 if(scope==='selected'&&(!selectionVisible().length||selectionVisible().length>1000))return;
 submitBusy=true;
 el('run-all').disabled=true;el('run-selected').disabled=true;
 el('feedback').textContent='Enviando solicitação ao servidor…';
 try{
  const res=await fetch(API,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
   credentials:'same-origin',body:JSON.stringify({action:'request',scope,skus:scope==='selected'?selectionVisible():[]})});
  const json=await readJson(res);
  el('feedback').textContent=json.reused
    ?'Auditoria #'+json.request_id+' já solicitada. Nenhuma execução duplicada foi criada.'
    :'Auditoria #'+json.request_id+' recebida e registrada. Veja o andamento acima.';
  await loadStatus();
 }catch(e){showError(e);}
 finally{submitBusy=false;refreshSelection();el('run-all').disabled=activeAll;}
}
async function download(format){
 el('feedback').textContent='Gerando '+format.toUpperCase()+'…';
 try{
  const visibleSet=new Set(displayed.map(r=>String(r.seller_sku)));
  const chosen=selected.size?[...selected].filter(s=>visibleSet.has(s)):[...visibleSet];
  if(!chosen.length)throw Error('Nenhum anúncio no filtro para exportação. Ajuste os filtros ou a seleção.');
  const res=await fetch(API,{method:'POST',credentials:'same-origin',
   headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
   body:JSON.stringify({action:'export',format,skus:chosen})});
  if(!res.ok){const j=await res.json().catch(()=>({error:'Falha na exportação'}));throw Error(j.error||'Falha');}
  const blob=await res.blob();
  const href=URL.createObjectURL(blob);
  const a=document.createElement('a');
  a.href=href;a.download='shopvivaliz-margens-amazon.'+format;
  document.body.appendChild(a);a.click();a.remove();
  setTimeout(()=>URL.revokeObjectURL(href),1200);
  el('feedback').textContent='Relatório gerado.';
 }catch(e){showError(e);}
}
async function loadImportStatus(){
 try{
  const res=await fetch(API+'?action=olist_ads_status',{credentials:'same-origin',cache:'no-store'});
  const j=await readJson(res);
  const info=el('olist-import-status');
  if(!j.has_index){
   info.textContent='Nenhuma exportação importada. A auditoria usa vínculos por SKU, EAN e pedidos do Olist.';
   return;
  }
  const st=j.stats||{}, names=(j.files||[]).map(x=>x.name).join(' · ');
  info.textContent='Importação de '+dateUtc(j.imported_at)+' · '+(st.unique_seller_skus||0)+
   ' anúncios: '+(st.fresh_unique||0)+' vínculos recentes, '+(st.historical_unique||0)+
   ' históricos, '+(st.conflicting||0)+' divergentes. Arquivos: '+names+
   '. Vínculos antigos não certificam margem; a idade é revalidada em cada auditoria.';
 }catch(e){el('olist-import-status').textContent='Erro ao consultar importações: '+String(e.message||e).slice(0,140);}
}
async function importOlistExports(){
 const files=[...el('olist-files').files];
 if(!files.length||files.length>5){
  el('olist-import-status').textContent='Selecione de um a cinco arquivos de exportação originais do Olist.';return;
 }
 const button=el('olist-import');
 button.disabled=true;
 el('olist-import-status').textContent='Validando cabeçalhos, vinculações e data das exportações…';
 try{
  const form=new FormData();
  form.append('action','import_olist_ads');
  files.forEach(f=>form.append('ads_exports[]',f,f.name));
  const res=await fetch(API,{method:'POST',credentials:'same-origin',
   headers:{'X-CSRF-Token':CSRF},body:form});
  const j=await readJson(res);
  const st=j.import?.stats||{};
  el('olist-import-status').textContent='Importação concluída: '+(st.unique_seller_skus||0)+
   ' anúncios, '+(st.fresh_unique||0)+' vínculos recentes e '+(st.historical_unique||0)+
   ' referências históricas. '+(j.historical_only
      ?'Exportações antigas não habilitam margem; importe arquivos atuais do Olist.'
      :'Auditoria #'+j.audit_request?.request_id+' registrada na fila com os vínculos atuais.');
  if(!j.historical_only)await loadStatus();
 }catch(e){el('olist-import-status').textContent='Erro na importação: '+String(e.message||e).slice(0,180);}
 finally{button.disabled=el('olist-files').files.length===0;}
}
el('olist-files').onchange=()=>{el('olist-import').disabled=el('olist-files').files.length===0;};
el('olist-import').onclick=importOlistExports;

el('run-selected').onclick=()=>requestRun('selected');el('run-all').onclick=()=>requestRun('all');
el('pdf').onclick=()=>download('pdf');el('xlsx').onclick=()=>download('xlsx');
el('reload').onclick=()=>load();
el('search').oninput=()=>{shown=75;render();};
el('program').onchange=el('status').onchange=el('only-active').onchange=()=>{shown=75;render();};
el('select-visible').onclick=()=>{displayed.forEach(r=>selected.add(String(r.seller_sku)));render();};
el('clear-selected').onclick=()=>{selected.clear();render();};
el('select-page').onchange=e=>{displayed.slice(0,shown).forEach(r=>e.target.checked?selected.add(String(r.seller_sku)):selected.delete(String(r.seller_sku)));render();};
el('more').onclick=()=>{shown+=75;render();};
el('rows').addEventListener('change',e=>{const s=e.target.getAttribute('data-sku');if(!s)return;e.target.checked?selected.add(s):selected.delete(s);refreshSelection();});
el('rows').addEventListener('click',e=>{const button=e.target.closest('[data-sim]');if(!button)return;currentSim=data.find(r=>r.seller_sku===button.dataset.sim);if(!currentSim)return;el('sim-name').textContent=currentSim.seller_sku;el('sim-price').value=currentSim.price||'';el('sim-result').textContent='';el('simulator').hidden=false;el('simulator').scrollIntoView({behavior:'smooth'});});
el('sim-close').onclick=()=>{el('simulator').hidden=true;currentSim=null;};
el('sim-calc').onclick=()=>{if(!currentSim)return;const next=Number(el('sim-price').value),old=Number(currentSim.price),oldMargin=currentSim.margin_weighted_bps;if(!Number.isFinite(next)||next<=0||!old||oldMargin===null||oldMargin===undefined){el('sim-result').textContent='Preço inválido ou margem histórica indisponível.';return;}
 const approximateProfit=old*oldMargin/10000+(next-old)*(1-taxBps/10000);
 const estimate=100*approximateProfit/next;
 el('sim-result').textContent='Margem hipotética: '+estimate.toFixed(2).replace('.',',')+'% · Lucro hipotético: '+price(approximateProfit)+' por unidade. NÃO publicar a partir desta simulação; comissão e tarifas precisam ser recalculadas.';
};
load();
loadImportStatus();
setInterval(()=>{if(!document.hidden)loadStatus();},7000);
document.addEventListener('visibilitychange',()=>{if(!document.hidden)loadStatus();});
</script>
</body>
</html>
