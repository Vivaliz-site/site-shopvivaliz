<?php
declare(strict_types=1);

/**
 * Popup promocional de cupons para a home.
 * Exibe apenas cupons retornados pelo endpoint validado.
 */
function sv_popup_cupons_html(): string {
    return <<<'HTML'
<div id="popup-cupons-modal" class="popup-cupons-modal" style="display:none" aria-hidden="true">
  <div class="popup-cupons-overlay" onclick="sv_popup_cupons_close()"></div>
  <section class="popup-cupons-content" role="dialog" aria-modal="true" aria-labelledby="popup-cupons-title">
    <button type="button" class="popup-cupons-close" onclick="sv_popup_cupons_close()" aria-label="Fechar">&times;</button>

    <header class="popup-cupons-header">
      <span class="popup-cupons-kicker">Ofertas ativas</span>
      <h2 id="popup-cupons-title">Cupons disponíveis</h2>
      <p>Copie um código e aplique no checkout.</p>
    </header>

    <div class="popup-cupons-status" id="popup-cupons-status">Exibindo somente cupons válidos e ativos</div>
    <div class="popup-cupons-list" id="popup-cupons-list">
      <div class="popup-cupons-loading">Carregando cupons...</div>
    </div>

    <footer class="popup-cupons-footer">
      <label class="popup-cupons-checkbox">
        <input type="checkbox" id="popup-cupons-dont-show" onchange="sv_popup_cupons_save_preference()">
        <span>Não mostrar novamente hoje</span>
      </label>
    </footer>
  </section>
</div>

<style>
.popup-cupons-modal{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:18px;font-family:inherit}
.popup-cupons-overlay{position:absolute;inset:0;background:rgba(3,22,38,.72);backdrop-filter:blur(3px)}
.popup-cupons-content{position:relative;width:min(100%,560px);max-height:min(84vh,760px);overflow:auto;background:#fff;border:1px solid #e6ebef;border-radius:22px;box-shadow:0 24px 70px rgba(0,0,0,.28);padding:26px;transform:translateY(14px) scale(.98);opacity:0;transition:transform .22s ease,opacity .22s ease}
.popup-cupons-modal.is-visible .popup-cupons-content{transform:none;opacity:1}
.popup-cupons-close{position:absolute;top:14px;right:14px;width:38px;height:38px;border:0;border-radius:50%;background:#f2f5f7;color:#173042;font-size:26px;line-height:1;cursor:pointer}
.popup-cupons-header{padding-right:44px;margin-bottom:16px}
.popup-cupons-kicker{display:inline-flex;padding:6px 10px;border-radius:999px;background:#eaf8ed;color:#23843a;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}
.popup-cupons-header h2{margin:10px 0 5px;color:#102a3d;font-size:28px;line-height:1.08}
.popup-cupons-header p{margin:0;color:#667782;font-size:15px}
.popup-cupons-status{display:flex;align-items:center;gap:8px;margin-bottom:14px;padding:11px 13px;border:1px solid #bfe8c7;border-radius:12px;background:#f3fbf5;color:#23753a;font-size:13px;font-weight:700}
.popup-cupons-status:before{content:'✓';display:grid;place-items:center;width:21px;height:21px;border-radius:50%;background:#39ad52;color:#fff;font-size:12px}
.popup-cupons-list{display:grid;gap:12px}
.popup-cupons-item{position:relative;display:grid;grid-template-columns:1fr auto;gap:14px;align-items:center;padding:17px;border:1px solid #dfe6ea;border-left:4px solid #39ad52;border-radius:15px;background:#fff;box-shadow:0 6px 18px rgba(16,42,61,.07)}
.popup-cupons-item-info{min-width:0}
.popup-cupons-item-top{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:7px}
.popup-cupons-item-code{font-size:20px;font-weight:900;color:#102a3d;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.02em;overflow-wrap:anywhere}
.popup-cupons-item-badge{display:inline-flex;padding:6px 9px;background:#39ad52;color:#fff;border-radius:8px;font-size:12px;font-weight:900;white-space:nowrap}
.popup-cupons-item-label{color:#5f707b;font-size:14px;line-height:1.35}
.popup-cupons-item-expiry{margin-top:7px;color:#7b8a93;font-size:12px}
.popup-cupons-item-copy{min-width:126px;padding:12px 15px;background:#32a94b;color:#fff;border:0;border-radius:11px;cursor:pointer;font-size:14px;font-weight:900;transition:transform .15s ease,background .15s ease}
.popup-cupons-item-copy:hover{background:#268d3c;transform:translateY(-1px)}
.popup-cupons-item-copy.copied{background:#1769aa}
.popup-cupons-loading{text-align:center;padding:24px 10px;color:#71818a;font-size:14px}
.popup-cupons-footer{margin-top:17px;padding-top:14px;border-top:1px solid #edf0f2}
.popup-cupons-checkbox{display:flex;align-items:center;justify-content:center;gap:9px;color:#687983;font-size:13px;cursor:pointer;user-select:none}
.popup-cupons-checkbox input{width:19px;height:19px;accent-color:#32a94b}
@media(max-width:600px){
  .popup-cupons-modal{align-items:flex-end;padding:10px}
  .popup-cupons-content{width:100%;max-height:86vh;border-radius:22px 22px 16px 16px;padding:21px 16px 17px}
  .popup-cupons-header h2{font-size:24px}
  .popup-cupons-item{grid-template-columns:1fr;padding:14px;gap:11px}
  .popup-cupons-item-copy{width:100%;min-width:0}
  .popup-cupons-status{font-size:12px}
}
</style>

<script>
function sv_popup_cupons_close(){
  const modal=document.getElementById('popup-cupons-modal');
  if(!modal)return;
  modal.classList.remove('is-visible');
  modal.setAttribute('aria-hidden','true');
  setTimeout(()=>{modal.style.display='none';},220);
}
function sv_popup_cupons_show(){
  const modal=document.getElementById('popup-cupons-modal');
  if(!modal)return;
  sv_popup_cupons_load().then(hasCoupons=>{
    if(!hasCoupons)return;
    modal.style.display='flex';
    modal.setAttribute('aria-hidden','false');
    requestAnimationFrame(()=>modal.classList.add('is-visible'));
  });
}
function sv_popup_cupons_save_preference(){
  const checkbox=document.getElementById('popup-cupons-dont-show');
  if(checkbox&&checkbox.checked){
    localStorage.setItem('popup-cupons-hide-until',new Date(Date.now()+86400000).toISOString());
  }
}
function sv_popup_cupons_should_show(){
  try{
    const hideUntil=localStorage.getItem('popup-cupons-hide-until');
    return !hideUntil||new Date(hideUntil)<=new Date();
  }catch(e){return true;}
}
function sv_popup_cupons_copy_code(button,code){
  const done=()=>{
    button.textContent='✓ Copiado';
    button.classList.add('copied');
    setTimeout(()=>{button.textContent='Copiar cupom';button.classList.remove('copied');},2000);
  };
  if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(code).then(done).catch(done);}else{done();}
}
function sv_popup_cupons_load(){
  const list=document.getElementById('popup-cupons-list');
  if(!list)return Promise.resolve(false);
  return fetch('/api/coupons/active.php',{cache:'no-store'})
    .then(r=>{if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
    .then(coupons=>{
      if(!Array.isArray(coupons)||coupons.length===0){list.innerHTML='<div class="popup-cupons-loading">Nenhum cupom disponível no momento.</div>';return false;}
      list.innerHTML=coupons.map(c=>{
        const expiry=c.ends_at?'<div class="popup-cupons-item-expiry">Válido até '+sv_format_date(c.ends_at)+'</div>':'';
        return '<article class="popup-cupons-item"><div class="popup-cupons-item-info"><div class="popup-cupons-item-top"><div class="popup-cupons-item-code">'+sv_escape_html(c.code)+'</div><span class="popup-cupons-item-badge">'+sv_format_discount(c.type,Number(c.value||0))+'</span></div><div class="popup-cupons-item-label">'+sv_escape_html(c.label)+'</div>'+expiry+'</div><button type="button" class="popup-cupons-item-copy" data-code="'+sv_escape_html(c.code)+'">Copiar cupom</button></article>';
      }).join('');
      list.querySelectorAll('.popup-cupons-item-copy').forEach(button=>button.addEventListener('click',()=>sv_popup_cupons_copy_code(button,button.dataset.code||'')));
      return true;
    })
    .catch(err=>{console.error('[popup-cupons] load error:',err);list.innerHTML='<div class="popup-cupons-loading">Não foi possível carregar os cupons agora.</div>';return false;});
}
function sv_escape_html(str){const div=document.createElement('div');div.textContent=String(str??'');return div.innerHTML;}
function sv_format_discount(type,value){if(type==='percent')return value.toLocaleString('pt-BR',{maximumFractionDigits:2})+'% OFF';if(type==='fixed')return value.toLocaleString('pt-BR',{style:'currency',currency:'BRL'})+' OFF';if(type==='shipping')return 'FRETE GRÁTIS';return 'DESCONTO';}
function sv_format_date(value){const d=new Date(String(value).replace(' ','T'));return Number.isNaN(d.getTime())?'':d.toLocaleDateString('pt-BR');}
document.addEventListener('DOMContentLoaded',()=>{if(sv_popup_cupons_should_show())setTimeout(sv_popup_cupons_show,3000);});
</script>
HTML;
}
