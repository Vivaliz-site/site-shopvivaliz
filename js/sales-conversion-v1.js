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
  function shippingClearPendingPayment(){
    try{localStorage.removeItem('shopvivaliz_pending_payment');}catch(e){}
  }
  function shippingOptionExpired(option){
    var expiresAt=Number(option&&option.expires_at||0);
    if(!expiresAt) return true;
    var now=expiresAt>1000000000000?Date.now():Math.floor(Date.now()/1000);
    return expiresAt<=now;
  }
  function shippingQuote(option,cep){
    return {cep:cep,total:Number(option.price)||0,option:option,label:(option.company?option.company+' - ':'')+(option.name||'Frete'),quote_id:option.quote_id||'',expires_at:Number(option.expires_at)||0,provider:'melhorenvio'};
  }
  function shippingRecalculateCheckout(message){
    var input=document.getElementById('cep-input');
    var cep=String(input&&input.value||'').replace(/\D/g,'').slice(0,8);
    try{localStorage.removeItem('shopvivaliz_shipping_quote');}catch(e){}
    shippingClearPendingPayment();
    var status=document.getElementById('checkout-shipping-status');
    if(status){status.hidden=false;status.textContent=message||'A cotação expirou. Recalculando o frete…';}
    if(input&&cep.length===8){
      try{input.dispatchEvent(new Event('input',{bubbles:true}));}catch(e){input.dispatchEvent(new Event('input'));}
    }
  }
  function shippingSaveCheckoutChoice(option,cep){
    if(document.documentElement.dataset.svCheckoutSubmitting==='1') return false;
    if(shippingOptionExpired(option)){shippingRecalculateCheckout();return false;}
    var previous=shippingReadQuote();
    var previousTotal=previous?Number(previous.total)||0:0;
    var quote=shippingQuote(option,cep);
    try{localStorage.setItem('shopvivaliz_shipping_quote',JSON.stringify(quote));}catch(e){}
    shippingClearPendingPayment();
    var shippingEl=document.getElementById('cart-shipping');
    var totalEl=document.getElementById('cart-total');
    if(shippingEl) shippingEl.textContent=shippingMoney(quote.total);
    if(totalEl){
      var currentTotal=shippingParseMoney(totalEl.textContent);
      if(currentTotal!==null) totalEl.textContent=shippingMoney(Math.max(0,currentTotal-previousTotal+quote.total));
    }
    return true;
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
    options.slice(0,5).forEach(function(option){
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
    var validOptions=options.filter(function(option){return !shippingOptionExpired(option);}).slice(0,5);
    if(!validOptions.length){shippingRecalculateCheckout();return;}
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
    validOptions.forEach(function(option,index){
      var label=document.createElement('label');
      label.className='sv-shipping-choice';
      var input=document.createElement('input');
      input.type='radio';input.name='sv_checkout_shipping_option';input.value=String(option.id||index);
      input.checked=currentId?String(option.id||'')===currentId:index===0;
      input.disabled=document.documentElement.dataset.svCheckoutSubmitting==='1';
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
    var isProduct=path==='/produto'||path==='/produto.php'||path.indexOf('/produto/')===0;
    var isCheckout=path==='/checkout';
    if((!isProduct&&!isCheckout)||typeof window.fetch!=='function') return;
    var pending=null;
    var latestShippingRequest=0;
    var activeShippingController=null;
    var cartRecalcTimer=null;
    function currentCheckoutCep(){
      var input=document.getElementById('cep-input');
      return String(input&&input.value||'').replace(/\D/g,'').slice(0,8);
    }
    function checkoutHasItems(){
      try{
        if(window.ShopVivalizCart&&typeof window.ShopVivalizCart.get==='function') return window.ShopVivalizCart.get().length>0;
        return JSON.parse(localStorage.getItem('shopvivaliz_cart')||'[]').length>0;
      }catch(e){return false;}
    }
    function staleShippingError(){
      var error=new Error('superseded_shipping_quote');
      error.name='AbortError';
      return error;
    }
    function setCheckoutSubmitting(active){
      if(!isCheckout) return;
      if(active) document.documentElement.dataset.svCheckoutSubmitting='1';
      else delete document.documentElement.dataset.svCheckoutSubmitting;
      document.querySelectorAll('input[name="sv_checkout_shipping_option"]').forEach(function(input){input.disabled=!!active;});
      var cepInput=document.getElementById('cep-input');
      if(cepInput){cepInput.readOnly=!!active;cepInput.setAttribute('aria-disabled',active?'true':'false');}
      document.querySelectorAll('#cart-items .qty-btn,#cart-items .btn-remove').forEach(function(button){button.disabled=!!active;});
    }
    function guardNativeShippingResponse(response,requestVersion,requestCep){
      if(!isCheckout||typeof Proxy!=='function') return response;
      return new Proxy(response,{
        get:function(target,prop){
          if(prop==='json'){
            return function(){
              return target.json().then(function(data){
                var currentCep=currentCheckoutCep();
                if(requestVersion!==latestShippingRequest||(requestCep&&currentCep&&requestCep!==currentCep)) throw staleShippingError();
                return data;
              });
            };
          }
          var value=Reflect.get(target,prop,target);
          return typeof value==='function'?value.bind(target):value;
        }
      });
    }
    function invalidateCheckoutChoices(message){
      if(!isCheckout||document.documentElement.dataset.svCheckoutSubmitting==='1') return;
      pending=null;
      latestShippingRequest+=1;
      if(activeShippingController){try{activeShippingController.abort();}catch(e){}activeShippingController=null;}
      shippingClearPendingPayment();
      try{localStorage.removeItem('shopvivaliz_shipping_quote');}catch(e){}
      if(cartRecalcTimer) clearTimeout(cartRecalcTimer);
      cartRecalcTimer=setTimeout(function(){
        if(!checkoutHasItems()){
          var emptyStatus=document.getElementById('checkout-shipping-status');
          if(emptyStatus){emptyStatus.hidden=true;emptyStatus.innerHTML='';}
          return;
        }
        shippingRecalculateCheckout(message||'Carrinho alterado. Recalculando o frete…');
      },0);
    }
    if(isCheckout){
      var checkoutForm=document.getElementById('checkout-form');
      var checkoutSubmit=document.getElementById('submit-btn');
      if(checkoutForm){
        checkoutForm.addEventListener('submit',function(){
          setCheckoutSubmitting(true);
          setTimeout(function(){if(!checkoutSubmit||!checkoutSubmit.disabled)setCheckoutSubmitting(false);},0);
        },true);
      }
      if(checkoutSubmit&&typeof MutationObserver==='function'){
        new MutationObserver(function(){if(!checkoutSubmit.disabled)setCheckoutSubmitting(false);})
          .observe(checkoutSubmit,{attributes:true,attributeFilter:['disabled']});
      }
      var cartItems=document.getElementById('cart-items');
      if(cartItems){
        cartItems.addEventListener('click',function(event){
          var target=event.target&&event.target.closest?event.target.closest('.qty-btn,.btn-remove'):null;
          if(target) invalidateCheckoutChoices('Itens alterados. Recalculando o frete…');
        });
      }
    }
    function renderPending(){
      if(!pending) return;
      if(isProduct) renderProductShipping(pending.options);
      if(isCheckout){
        var currentCep=currentCheckoutCep();
        if(!currentCep||currentCep!==pending.cep) return;
        renderCheckoutShipping(pending.options,pending.cep);
      }
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
      if(url.indexOf('/api/melhorenvio/shipping-check-v2.php')===-1) return original.apply(this,arguments);
      var requestCep=shippingCepFromRequest(init);
      var requestVersion=++latestShippingRequest;
      pending=null;
      var request;
      if(isCheckout){
        shippingClearPendingPayment();
        try{localStorage.removeItem('shopvivaliz_shipping_quote');}catch(e){}
        if(activeShippingController){try{activeShippingController.abort();}catch(e){}}
        if(typeof AbortController==='function'){
          activeShippingController=new AbortController();
          var guardedInit=Object.assign({},init||{});
          guardedInit.signal=activeShippingController.signal;
          request=original.call(this,resource,guardedInit);
        }else{
          activeShippingController=null;
          request=original.apply(this,arguments);
        }
      }else{
        request=original.apply(this,arguments);
      }
      return request.then(function(response){
        if(requestVersion!==latestShippingRequest) throw staleShippingError();
        try{
          response.clone().json().then(function(data){
            if(requestVersion!==latestShippingRequest) return;
            var options=data&&Array.isArray(data.shipping_options)?data.shipping_options.slice(0,5):[];
            if(!data||!data.ok||!options.length){pending=null;return;}
            var input=isProduct?document.getElementById('p-frete-cep'):document.getElementById('cep-input');
            var cep=requestCep||String(input&&input.value||'').replace(/\D/g,'').slice(0,8);
            if(isCheckout&&currentCheckoutCep()&&cep!==currentCheckoutCep()){pending=null;return;}
            pending={options:options,cep:cep,requestVersion:requestVersion};
            renderPending();
          }).catch(function(){if(requestVersion===latestShippingRequest)pending=null;});
        }catch(e){if(requestVersion===latestShippingRequest)pending=null;}
        return guardNativeShippingResponse(response,requestVersion,requestCep);
      },function(error){
        if(requestVersion===latestShippingRequest) pending=null;
        throw error;
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
    if(path!=='/produto'&&path!=='/produto.php'&&path.indexOf('/produto/')!==0) return;
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
    buyTarget.parentNode.insertBefore(offer,buyTarget);
    var note=document.createElement('div');
    note.className='sv-sales-assurance';
    note.textContent='“Comprar agora” adiciona o item ao carrinho; você ainda calcula o frete e revisa tudo antes do pagamento.';
    buyTarget.insertAdjacentElement('afterend',note);
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
  onReady(function(){installShippingOptions();installCartOffer();installProductOffer();installCheckoutClarity();});
})();
