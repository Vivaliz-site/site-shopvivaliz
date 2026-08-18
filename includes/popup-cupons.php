<?php
declare(strict_types=1);

/**
 * Popup promocional de cupons para a home.
 * Exibe apenas cupons retornados pelo endpoint validado.
 */
function sv_popup_cupons_html(): string {
    return <<<'HTML'
<div id="popup-cupons-modal" class="popup-cupons-modal" style="display:none" aria-hidden="true">
  <section class="popup-cupons-content" role="region" aria-labelledby="popup-cupons-title">
    <button type="button" class="popup-cupons-close" onclick="sv_popup_cupons_close()" aria-label="Fechar">&times;</button>

    <div class="popup-cupons-banner" aria-hidden="true">
      <div class="popup-cupons-banner-icon">🎁</div>
      <div>
        <strong>Um cupom para sua compra</strong>
        <span>Use ofertas ativas e válidas no checkout.</span>
      </div>
    </div>

    <header class="popup-cupons-header">
      <span class="popup-cupons-kicker">Ofertas ativas</span>
      <h2 id="popup-cupons-title">Cupons disponíveis</h2>
      <p id="popup-cupons-count" aria-live="polite">Consultando ofertas ativas...</p>
    </header>

    <div class="popup-cupons-status" id="popup-cupons-status">Apenas cupons ativos aparecem aqui</div>
    <div class="popup-cupons-list" id="popup-cupons-list">
      <div class="popup-cupons-loading">Carregando cupons...</div>
    </div>

    <div class="popup-cupons-footer">
      <label class="popup-cupons-checkbox">
        <input type="checkbox" id="popup-cupons-dont-show" onchange="sv_popup_cupons_save_preference()">
        <span>Não mostrar novamente hoje</span>
      </label>
    </div>
  </section>
</div>

<style>
.popup-cupons-modal{position:fixed;right:18px;bottom:calc(18px + var(--sv-bottom-ui-offset,0px) + env(safe-area-inset-bottom));z-index:1001;display:flex;align-items:flex-end;justify-content:flex-end;pointer-events:none;font-family:inherit}
.popup-cupons-content{position:relative;width:min(390px,calc(100vw - 32px));max-height:min(66vh,540px);overflow:auto;pointer-events:auto;background:linear-gradient(180deg,#fff 0%,#fbfdfc 100%);border:1px solid rgba(11,79,136,.12);border-radius:22px;box-shadow:0 22px 54px rgba(7,52,93,.26);padding:0 20px 18px;transform:translateY(14px) scale(.98);opacity:0;transition:transform .24s ease,opacity .24s ease}
.popup-cupons-modal.is-visible .popup-cupons-content{transform:none;opacity:1}
.popup-cupons-close{position:absolute;top:14px;right:14px;z-index:2;width:40px;height:40px;border:1px solid rgba(255,255,255,.65);border-radius:50%;background:rgba(255,255,255,.92);color:#173042;font-size:26px;line-height:1;cursor:pointer;box-shadow:0 5px 16px rgba(16,42,61,.15)}
.popup-cupons-banner{margin:0 -20px 16px;padding:17px 60px 17px 18px;display:flex;align-items:center;gap:12px;background:linear-gradient(135deg,#083c5d 0%,#0e6f67 58%,#2da44e 100%);color:#fff;border-radius:22px 22px 16px 16px;overflow:hidden;position:relative}
.popup-cupons-banner:after{content:'';position:absolute;right:-34px;bottom:-48px;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,.11)}
.popup-cupons-banner-icon{display:grid;place-items:center;flex:0 0 46px;width:46px;height:46px;border-radius:14px;background:rgba(255,255,255,.17);font-size:25px;box-shadow:inset 0 0 0 1px rgba(255,255,255,.16)}
.popup-cupons-banner strong{display:block;font-size:16px;line-height:1.2;margin-bottom:3px}
.popup-cupons-banner span{display:block;font-size:12px;line-height:1.35;color:rgba(255,255,255,.88)}
.popup-cupons-header{padding-right:40px;margin-bottom:12px}
.popup-cupons-kicker{display:inline-flex;padding:6px 10px;border-radius:999px;background:#eaf8ed;color:#23843a;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}
.popup-cupons-header h2{margin:8px 0 4px;color:#102a3d;font-size:24px;line-height:1.08}
.popup-cupons-header p{margin:0;color:#667782;font-size:13px}
.popup-cupons-status{display:flex;align-items:center;gap:8px;margin-bottom:11px;padding:9px 11px;border:1px solid #bfe8c7;border-radius:12px;background:#f3fbf5;color:#23753a;font-size:12px;font-weight:700}
.popup-cupons-status:before{content:'✓';display:grid;place-items:center;width:21px;height:21px;border-radius:50%;background:#39ad52;color:#fff;font-size:12px}
.popup-cupons-list{display:grid;gap:12px}
.popup-cupons-item{position:relative;display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center;padding:13px;border:1px solid #dfe6ea;border-left:4px solid #39ad52;border-radius:14px;background:#fff;box-shadow:0 6px 16px rgba(16,42,61,.07)}
.popup-cupons-item-info{min-width:0}
.popup-cupons-item-top{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:7px}
.popup-cupons-item-code{font-size:17px;font-weight:900;color:#102a3d;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.02em;overflow-wrap:anywhere}
.popup-cupons-item-badge{display:inline-flex;padding:6px 9px;background:#39ad52;color:#fff;border-radius:8px;font-size:12px;font-weight:900;white-space:nowrap}
.popup-cupons-item-label{color:#5f707b;font-size:14px;line-height:1.35}
.popup-cupons-item-expiry{margin-top:7px;color:#7b8a93;font-size:12px}
.popup-cupons-item-copy{min-width:112px;padding:10px 12px;background:linear-gradient(135deg,#36b653,#258f3e);color:#fff;border:0;border-radius:11px;cursor:pointer;font-size:13px;font-weight:900;transition:transform .15s ease,filter .15s ease;box-shadow:0 8px 18px rgba(50,169,75,.22)}
.popup-cupons-item-copy:hover{filter:brightness(.95);transform:translateY(-1px)}
.popup-cupons-item-copy.copied{background:#1769aa}
.popup-cupons-loading{text-align:center;padding:26px 10px;color:#71818a;font-size:14px}
.popup-cupons-footer{margin-top:17px;padding-top:14px;border-top:1px solid #edf0f2}
.popup-cupons-checkbox{display:flex;align-items:center;justify-content:center;gap:9px;color:#687983;font-size:13px;cursor:pointer;user-select:none}
.popup-cupons-checkbox input{width:19px;height:19px;accent-color:#32a94b}
@media(max-width:600px){
  .popup-cupons-modal{right:10px;bottom:calc(92px + env(safe-area-inset-bottom))}
  .popup-cupons-content{width:min(100%,420px);max-height:72vh;border-radius:20px;padding:0 16px 17px}
  .popup-cupons-banner{margin:0 -16px 20px;padding:20px 60px 20px 18px;border-radius:24px 24px 16px 16px}
  .popup-cupons-banner-icon{width:48px;height:48px;flex-basis:48px;font-size:26px}
  .popup-cupons-header h2{font-size:25px}
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
    const closeButton=modal.querySelector('.popup-cupons-close');
    if(closeButton)closeButton.focus({preventScroll:true});
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
  const count=document.getElementById('popup-cupons-count');
  if(!list)return Promise.resolve(false);
  return fetch('/api/coupons/active.php',{cache:'no-store'})
    .then(r=>{if(!r.ok)throw new Error('HTTP '+r.status);return r.json();})
    .then(coupons=>{
      if(!Array.isArray(coupons)||coupons.length===0){
        if(count)count.textContent='Nenhum cupom ativo no momento.';
        list.innerHTML='<div class="popup-cupons-loading">Novas ofertas aparecerão aqui em breve.</div>';
        return false;
      }
      if(count)count.textContent=coupons.length+' '+(coupons.length===1?'cupom ativo disponível hoje.':'cupons ativos disponíveis hoje.');
      list.innerHTML=coupons.map(c=>{
        const expiry=c.ends_at?'<div class="popup-cupons-item-expiry">Válido até '+sv_format_date(c.ends_at)+'</div>':'';
        return '<article class="popup-cupons-item"><div class="popup-cupons-item-info"><div class="popup-cupons-item-top"><div class="popup-cupons-item-code">'+sv_escape_html(c.code)+'</div><span class="popup-cupons-item-badge">'+sv_format_discount(c.type,Number(c.value||0))+'</span></div><div class="popup-cupons-item-label">'+sv_escape_html(c.label)+'</div>'+expiry+'</div><button type="button" class="popup-cupons-item-copy" data-code="'+sv_escape_html(c.code)+'">Copiar cupom</button></article>';
      }).join('');
      list.querySelectorAll('.popup-cupons-item-copy').forEach(button=>button.addEventListener('click',()=>sv_popup_cupons_copy_code(button,button.dataset.code||'')));
      return true;
    })
    .catch(err=>{
      console.error('[popup-cupons] load error:',err);
      if(count)count.textContent='Não foi possível consultar as ofertas agora.';
      list.innerHTML='<div class="popup-cupons-loading">Tente novamente em alguns instantes.</div>';
      return false;
    });
}
function sv_escape_html(str){const div=document.createElement('div');div.textContent=String(str??'');return div.innerHTML;}
function sv_format_discount(type,value){if(type==='percent')return value.toLocaleString('pt-BR',{maximumFractionDigits:2})+'% OFF';if(type==='fixed')return value.toLocaleString('pt-BR',{style:'currency',currency:'BRL'})+' OFF';if(type==='shipping')return 'FRETE GRÁTIS';return 'DESCONTO';}
function sv_format_date(value){const d=new Date(String(value).replace(' ','T'));return Number.isNaN(d.getTime())?'':d.toLocaleDateString('pt-BR');}
document.addEventListener('DOMContentLoaded',()=>{
  if(!sv_popup_cupons_should_show())return;
  let armed=false;
  const activate=()=>{
    if(armed)return;
    armed=true;
    window.removeEventListener('scroll',onScroll);
    document.removeEventListener('focusin',onFocus);
    setTimeout(sv_popup_cupons_show,1200);
  };
  const onScroll=()=>{if(window.scrollY>180)activate();};
  const onFocus=(event)=>{if(event.target&&event.target.matches('input,select,textarea'))activate();};
  window.addEventListener('scroll',onScroll,{passive:true});
  document.addEventListener('focusin',onFocus);
  document.addEventListener('keydown',(event)=>{if(event.key==='Escape')sv_popup_cupons_close();});
});
</script>
HTML;
}
