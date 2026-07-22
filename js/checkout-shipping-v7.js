(function(){
  var form=document.getElementById('checkout-form');
  if(!form)return;
  function quote(){try{return JSON.parse(localStorage.getItem('shopvivaliz_shipping_quote')||'null');}catch(e){return null;}}
  function cart(){try{return JSON.parse(localStorage.getItem('shopvivaliz_cart')||'[]');}catch(e){return[];}}
  function digits(value){return String(value||'').replace(/\D/g,'');}
  function ensureHidden(name,value){var input=form.querySelector('input[name="'+name+'"]');if(!input){input=document.createElement('input');input.type='hidden';input.name=name;form.appendChild(input);}input.value=value==null?'':String(value);}
  function syncQuoteObject(q){
    if(!q)return false;
    ensureHidden('shipping_total',Number(q.total)||0);
    ensureHidden('shipping_label',q.label||'');
    ensureHidden('shipping_service',q.option&&q.option.id?q.option.id:'');
    ensureHidden('shipping_cep',q.cep||'');
    ensureHidden('shipping_quote_id',q.quote_id||'');
    ensureHidden('shipping_expires_at',q.expires_at||'');
    return true;
  }
  function syncQuote(){var q=quote();if(!q)return false;var now=Math.floor(Date.now()/1000);if(q.expires_at&&q.expires_at<now){localStorage.removeItem('shopvivaliz_shipping_quote');return false;}return syncQuoteObject(q);}
  function currentCep(){var input=form.querySelector('#cep-input,[name="cep"]');return digits(input&&input.value).slice(0,8);}
  function calculateShipping(cep){
    var items=cart();
    var statusEl=document.getElementById('checkout-shipping-status');
    if(cep.length!==8||!items.length)return Promise.resolve(false);
    if(statusEl){statusEl.hidden=false;statusEl.textContent='Recalculando frete para '+cep.substring(0,5)+'-'+cep.substring(5,8)+'…';}
    return fetch('/api/melhorenvio/shipping-check-v2.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({cep:cep,items:items.map(function(item){return{sku:item.sku||'',product_id:item.id||'',olist_product_id:item.olist_product_id||'',quantity:item.quantity||1};})})})
      .then(function(response){return response.json().then(function(data){return{ok:response.ok,data:data};});})
      .then(function(result){
        if(!result.ok||!result.data.ok){
          localStorage.removeItem('shopvivaliz_shipping_quote');
          if(statusEl){statusEl.hidden=false;statusEl.textContent=result.data.message||'Não foi possível calcular o frete para este CEP.';}
          return false;
        }
        var options=result.data.shipping_options||[];
        var selected=result.data.selected_option||options[0];
        if(!selected){
          localStorage.removeItem('shopvivaliz_shipping_quote');
          if(statusEl){statusEl.hidden=false;statusEl.textContent='Nenhuma opção de frete disponível para este CEP.';}
          return false;
        }
        var quoteData={cep:cep,total:Number(selected.price)||0,option:selected,label:(selected.company?selected.company+' - ':'')+(selected.name||'Frete'),quote_id:selected.quote_id||'',expires_at:Number(selected.expires_at)||0,provider:'melhorenvio'};
        localStorage.setItem('shopvivaliz_shipping_quote',JSON.stringify(quoteData));
        syncQuoteObject(quoteData);
        if(statusEl){statusEl.hidden=true;statusEl.textContent='';}
        return true;
      })
      .catch(function(){
        if(statusEl){statusEl.hidden=false;statusEl.textContent='Falha de conexão ao calcular o frete.';}
        return false;
      });
  }
  function refreshExpiredQuote(){
    var q=quote();
    var cep=currentCep();
    var now=Math.floor(Date.now()/1000);
    if(q&&q.expires_at&&q.expires_at<now){
      localStorage.removeItem('shopvivaliz_shipping_quote');
      if(cep.length===8){return calculateShipping(cep);}
      return false;
    }
    if(q)return syncQuoteObject(q);
    if(cep.length===8){return calculateShipping(cep);}
    return false;
  }
  syncQuote();
  refreshExpiredQuote();
  form.addEventListener('input',function(event){
    if(event.target&&event.target.id==='cep-input'){
      var cep=digits(event.target.value).slice(0,8);
      if(cep.length===8){calculateShipping(cep);}
    }
  },true);
  form.addEventListener('blur',function(event){
    if(event.target&&event.target.id==='cep-input'){
      var cep=digits(event.target.value).slice(0,8);
      if(cep.length===8){var existing=quote();if(!existing||existing.cep!==cep|| (existing.expires_at&&existing.expires_at<Math.floor(Date.now()/1000))){calculateShipping(cep);}}
    }
  },true);
  form.addEventListener('submit',function(event){var q=quote();if(q&&q.expires_at&&q.expires_at<Math.floor(Date.now()/1000)){event.preventDefault();event.stopImmediatePropagation();localStorage.removeItem('shopvivaliz_shipping_quote');var status=document.getElementById('checkout-status');var cep=currentCep();if(status){status.textContent='Sua cotação de frete expirou. Recalculando agora…';status.className='checkout-status-msg err';}if(cep.length===8){calculateShipping(cep);}return;}syncQuote();},true);
})();
