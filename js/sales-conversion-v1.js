(function(){
  'use strict';
  function onReady(fn){ if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',fn,{once:true});}else{fn();} }
  function installCartOffer(){
    if((location.pathname||'').replace(/\/$/,'')!=='/carrinho') return;
    var checkout=document.getElementById('btn-checkout');
    if(!checkout || document.getElementById('sv-cart-sales-offer')) return;
    var box=document.createElement('div');
    box.id='sv-cart-sales-offer';
    box.className='sv-sales-offer';
    box.innerHTML='<strong>Economize antes de finalizar</strong><span>Use <code>VIVALIZ10</code> no checkout para 10% OFF em compras acima de R$ 100.</span>';
    checkout.parentNode.insertBefore(box,checkout);
    checkout.textContent='Finalizar pedido • sem cadastro';
    var note=document.createElement('div');
    note.className='sv-sales-assurance';
    note.textContent='Você revisa frete e total antes de ir para o pagamento.';
    checkout.insertAdjacentElement('afterend',note);
  }
  onReady(installCartOffer);
})();
