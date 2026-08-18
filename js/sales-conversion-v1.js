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
  function installCheckoutClarity(){
    if((location.pathname||'').replace(/\/$/,'')!=='/checkout') return;
    var form=document.getElementById('checkout-form');
    if(!form || document.getElementById('sv-checkout-sales-clarity')) return;

    var title=document.querySelector('#checkout-section .checkout-title');
    if(title) title.textContent='Finalize sem cadastro';

    var cpfField=document.getElementById('boleto-cpf-field');
    if(cpfField){
      var helper=document.createElement('small');
      helper.id='sv-checkout-sales-clarity';
      helper.style.display='block';
      helper.style.marginTop='6px';
      helper.style.color='#64748b';
      helper.textContent='Documento necessário para validar o pedido e processar o pagamento com segurança.';
      cpfField.appendChild(helper);
    }

    var notes=form.querySelector('textarea[name="notes"]');
    var notesLabel=notes && notes.closest('label.form-group');
    if(notesLabel && !notesLabel.closest('details')){
      var details=document.createElement('details');
      details.style.margin='10px 0 16px';
      var summary=document.createElement('summary');
      summary.textContent='Adicionar observação ao pedido (opcional)';
      summary.style.cursor='pointer';
      summary.style.fontWeight='800';
      summary.style.color='#173b63';
      notesLabel.parentNode.insertBefore(details,notesLabel);
      details.appendChild(summary);
      details.appendChild(notesLabel);
      var span=notesLabel.querySelector('span');
      if(span) span.textContent='Observações do pedido';
    }

    var submit=document.getElementById('submit-btn');
    if(submit){
      submit.textContent='Continuar para pagamento seguro';
      if(!document.getElementById('sv-checkout-submit-note')){
        var note=document.createElement('div');
        note.id='sv-checkout-submit-note';
        note.className='sv-sales-assurance';
        note.textContent='Nenhuma cobrança acontece antes de você confirmar no gateway escolhido.';
        submit.insertAdjacentElement('afterend',note);
      }
    }
  }
  onReady(function(){ installCartOffer(); installProductOffer(); installCheckoutClarity(); });
})();
