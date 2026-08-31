(function(){
  'use strict';
  function onReady(fn){ if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',fn,{once:true});}else{fn();} }
  function shippingMoney(value){
    return 'R$ '+Number(value||0).toFixed(2).replace('.',',').replace(/\B(?=(\d{3})+(?!\d))/g,'.');
  }
  function shippingParseMoney(value){
    var normalized=String(value||'').replace(/[^0-9,.-]/g,'').replace(/\./g,'').replace(',','.');
    var parsed=Number(normalized);
    return Number.isFinite(parsed)?parsed:null;
  }
  function shippingReadQuote(){
    try{return JSON.parse(localStorage.getItem('shopvivaliz_shipping_quote')||'null');}catch(e){return null;}
  }
  function shippingQuote(option,cep){
    return {cep:cep,total:Number(option.price)||0,option:option,label:(option.company?option.company+' - ':'')+(option.name||'Frete'),quote_id:option.quote_id||'',expires_at:Number(option.expires_at)||0,provider:'melhorenvio'};
  }
  function shippingSaveCheckoutChoice(option,cep){
    var previous=shippingReadQuote();
    var previousTotal=previous?Number(previous.total)||0:0;
    var quote=shippingQuote(option,cep);
    try{localStorage.setItem('shopvivaliz_shipping_quote',JSON.stringify(quote));}catch(e){}
    try{localStorage.removeItem('shopvivaliz_pending_payment');}catch(e){}
    var shippingEl=document.getElementById('cart-shipping');
    var totalEl=document.getElementById('cart-total');
    if(shippingEl) shippingEl.textContent=shippingMoney(quote.total);
    if(totalEl){
      var currentTotal=shippingParseMoney(totalEl.textContent);
      if(currentTotal!==null) totalEl.textContent=shippingMoney(Math.max(0,currentTotal-previousTotal+quote.total));
    }
  }
  function shippingCepFromRequest(init){
    try{
      var body=init&&typeof init.body==='string'?JSON.parse(init.body):null;
      return body&&body.cep?String(body.cep).replace(/\D/g,'').slice(0,8):'';
    }catch(e){return '';}
  }
  function renderProductShipping(options){
    var result=document.getElementById('p-frete-result');
    if(!result) return;
    result.dataset.svShippingRendering='1';
    result.innerHTML='';
    var list=document.createElement('div');
    list.className='frete-results-list';
    list.dataset.svFiveShipping='1';
    options.slice(0, 5).forEach(function(option){
      var row=document.createElement('div');
      row.className='frete-result-item';
      var name=document.createElement('span');
      name.className='frete-item-name';
      var label=(option.company?option.company+' - ':'')+(option.name||'Entrega Padrão');
      if(option.delivery_time) label+=' (até '+option.delivery_time+' dias úteis)';
      name.textContent=label;
      var price=document.createElement('span');
      price.className='frete-item-price';
      var strong=document.createElement('strong');
      strong.textContent=shippingMoney(option.price);
      price.appendChild(strong);
      row.appendChild(name);row.appendChild(price);list.appendChild(row);
    });
    result.appendChild(list);
    delete result.dataset.svShippingRendering;
  }
  function renderCheckoutShipping(options,cep){
    var status=document.getElementById('checkout-shipping-status');
    if(!status) return;
    status.dataset.svShippingRendering='1';
    status.hidden=false;
    status.innerHTML='';
    var title=document.createElement('strong');
    title.className='sv-shipping-choice-title';
    title.textContent='Escolha o frete';
    status.appendChild(title);
    var list=document.createElement('div');
    list.className='sv-shipping-choice-list';
    list.dataset.svFiveShipping='1';
    var current=shippingReadQuote();
    var currentId=current&&current.option?String(current.option.id||''):'';
    options.slice(0, 5).forEach(function(option,index){
      var label=document.createElement('label');
      label.className='sv-shipping-choice';
      var input=document.createElement('input');
      input.type='radio';input.name='sv_checkout_shipping_option';input.value=String(option.id||index);
      input.checked=currentId?String(option.id||'')===currentId:index===0;
      var info=document.createElement('span');
      var name=document.createElement('strong');
      name.textContent=(option.company?option.company+' - ':'')+(option.name||'Frete');
      var time=document.createElement('small');
      time.textContent=option.delivery_time?'Entrega em até '+option.delivery_time+' dias úteis':'Prazo informado pela transportadora';
      info.appendChild(name);info.appendChild(time);
      var price=document.createElement('b');price.textContent=shippingMoney(option.price);
      input.addEventListener('change',function(){shippingSaveCheckoutChoice(option,cep);});
      label.appendChild(input);label.appendChild(info);label.appendChild(price);list.appendChild(label);
    });
    status.appendChild(list);
    delete status.dataset.svShippingRendering;
  }
  function installShippingOptions(){
    var path=(location.pathname||'').replace(/\/$/,'');
    var isProduct=path==='/produto'||path.indexOf('/produto/')===0;
    var isCheckout=path==='/checkout';
    if((!isProduct&&!isCheckout)||typeof window.fetch!=='function') return;
    var pending=null;
    function renderPending(){
      if(!pending) return;
      if(isProduct) renderProductShipping(pending.options);
      if(isCheckout) renderCheckoutShipping(pending.options,pending.cep);
    }
    var observed=isProduct?document.getElementById('p-frete-result'):document.getElementById('checkout-shipping-status');
    if(observed&&typeof MutationObserver==='function'){
      new MutationObserver(function(){
        if(observed.dataset.svShippingRendering==='1') return;
        if(pending&&(observed.hidden||!observed.querySelector('[data-sv-five-shipping="1"]'))) renderPending();
      }).observe(observed,{childList:true,subtree:true,attributes:true,attributeFilter:['hidden']});
    }
    var original=window.fetch;
    if(original.__svFiveShippingWrapped) return;
    function wrappedFetch(resource,init){
      var url=typeof resource==='string'?resource:(resource&&resource.url?String(resource.url):'');
      var request=original.apply(this,arguments);
      if(url.indexOf('/api/melhorenvio/shipping-check-v2.php')===-1) return request;
      var requestCep=shippingCepFromRequest(init);
      return request.then(function(response){
        try{
          response.clone().json().then(function(data){
            var options=data&&Array.isArray(data.shipping_options)?data.shipping_options.slice(0, 5):[];
            if(!data||!data.ok||!options.length) return;
            var input=isProduct?document.getElementById('p-frete-cep'):document.getElementById('cep-input');
            var cep=requestCep||String(input&&input.value||'').replace(/\D/g,'').slice(0,8);
            pending={options:options,cep:cep};
            renderPending();
          }).catch(function(){});
        }catch(e){}
        return response;
      });
    }
    wrappedFetch.__svFiveShippingWrapped=true;
    wrappedFetch.__svFiveShippingOriginal=original;
    window.fetch=wrappedFetch;
  }
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
    var buyTarget = buy.closest('.product-buy-group') || buy.closest('.produto-actions') || buy;
    buyTarget.parentNode.insertBefore(offer, buyTarget);
    var note=document.createElement('div');
    note.className='sv-sales-assurance';
    note.textContent='“Comprar agora” adiciona o item ao carrinho; você ainda calcula o frete e revisa tudo antes do pagamento.';
    buyTarget.insertAdjacentElement('afterend', note);
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
  onReady(function(){ installShippingOptions(); installCartOffer(); installProductOffer(); installCheckoutClarity(); });
})();
