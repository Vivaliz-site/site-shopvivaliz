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
  function installProductOffer(){
    var path=(location.pathname||'').replace(/\/$/,'');
    if(path!=='/produto' && path.indexOf('/produto/')!==0) return;
    var buy=document.getElementById('buy-now');
    if(!buy || document.getElementById('sv-product-sales-offer')) return;
    var ctx=window.ShopVivalizProductContext||{};
    var price=Number(ctx.price||0);
    var offer=document.createElement('div');
    offer.id='sv-product-sales-offer';
    offer.className='sv-sales-offer';
    var message=price>=100
      ? 'Este item já atinge o mínimo do cupom <code>VIVALIZ10</code>: 10% OFF no checkout.'
      : 'Use <code>VIVALIZ10</code> para 10% OFF quando o carrinho passar de R$ 100.';
    offer.innerHTML='<strong>Oferta disponível para sua compra</strong><span>'+message+'</span>';
    buy.parentNode.insertBefore(offer,buy);
    var note=document.createElement('div');
    note.className='sv-sales-assurance';
    note.textContent='“Comprar agora” adiciona o item ao carrinho; você ainda calcula o frete e revisa tudo antes do pagamento.';
    buy.insertAdjacentElement('afterend',note);
  }
  onReady(function(){ installCartOffer(); installProductOffer(); });
})();
