(function(){
  var button=document.getElementById('btn-frete');
  var input=document.getElementById('frete-cep');
  var status=document.getElementById('frete-status');
  var frete=document.getElementById('cart-frete');
  var totalEl=document.getElementById('cart-total');
  if(!button||!input)return;
  function cart(){try{return JSON.parse(localStorage.getItem('shopvivaliz_cart')||'[]');}catch(e){return[];}}
  function money(v){return 'R$ '+Number(v||0).toFixed(2).replace('.',',').replace(/\B(?=(\d{3})+(?!\d))/g,'.');}
  function subtotal(){return cart().reduce(function(sum,item){return sum+(Number(item.price)||0)*(Number(item.quantity)||1);},0);}
  function save(quote){localStorage.setItem('shopvivaliz_shipping_quote',JSON.stringify(quote));}
  function loadQuote(){try{return JSON.parse(localStorage.getItem('shopvivaliz_shipping_quote')||'null');}catch(e){return null;}}
  function makeQuote(option,cep){return{cep:cep,total:Number(option.price)||0,option:option,label:(option.company?option.company+' - ':'')+(option.name||'Frete'),quote_id:option.quote_id||'',expires_at:Number(option.expires_at)||0,provider:'melhorenvio'};}
  function escHtml(str){var d=document.createElement('div');d.textContent=String(str||'');return d.innerHTML;}
  function renderOptions(options,cep){
    if(!status)return;
    status.innerHTML='';
    var wrap=document.createElement('div');
    wrap.className='sv-shipping-options';
    options.forEach(function(option,index){
      var label=document.createElement('label');
      label.className='sv-shipping-option';
      var input=document.createElement('input');
      input.type='radio';
      input.name='sv_shipping_option';
      if(index===0)input.checked=true;
      var span=document.createElement('span');
      var strong=document.createElement('strong');
      strong.textContent=option.name||option.company||'Frete';
      span.appendChild(strong);
      var small=document.createElement('small');
      small.textContent=option.delivery_time?('Entrega em até '+option.delivery_time+' dias úteis'):'Prazo informado no checkout';
      span.appendChild(small);
      var priceB=document.createElement('b');
      priceB.textContent=money(option.price);
      label.appendChild(input);
      label.appendChild(span);
      label.appendChild(priceB);
      input.addEventListener('change',function(){
        var quote=makeQuote(option,cep);
        save(quote);
        if(frete)frete.textContent=money(quote.total);
        if(totalEl)totalEl.textContent=money(subtotal()+quote.total);
      });
      wrap.appendChild(label);
    });
    status.appendChild(wrap);
    var validity=document.createElement('div');
    validity.className='sv-shipping-validity';
    validity.textContent='Cotação válida por 30 minutos para o CEP '+cep.replace(/(\d{5})(\d{3})/,'$1-$2')+'.';
    status.appendChild(validity);
  }
  function refreshIfExpired(){
    var quote=loadQuote();
    var now=Math.floor(Date.now()/1000);
    var cep=input.value.replace(/\D/g,'');
    if(quote&&quote.expires_at&&quote.expires_at<now){
      localStorage.removeItem('shopvivaliz_shipping_quote');
      if(cep.length===8){button.click();return true;}
      if(status)status.textContent='Sua cotação de frete expirou. Calcule novamente.';
      if(frete)frete.textContent='A calcular';
      return true;
    }
    return false;
  }
  refreshIfExpired();
  button.addEventListener('click',function(event){
    event.preventDefault();event.stopImmediatePropagation();
    var items=cart();
    if(!items.length){if(status)status.textContent='Seu carrinho está vazio.';return;}
    var cep=input.value.replace(/\D/g,'');
    if(cep.length!==8){if(status)status.textContent='Informe um CEP válido com 8 números.';input.focus();return;}
    button.disabled=true;button.textContent='Calculando…';if(status)status.textContent='Consultando transportadoras…';
    fetch('/api/melhorenvio/shipping-check-v2.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({cep:cep,items:items.map(function(item){return{sku:item.sku||'',product_id:item.id||'',olist_product_id:item.olist_product_id||'',quantity:item.quantity||1};})})})
      .then(function(response){return response.json().then(function(data){return{ok:response.ok,data:data};});})
      .then(function(result){
        button.disabled=false;button.textContent='Calcular';
        if(!result.ok||!result.data.ok){localStorage.removeItem('shopvivaliz_shipping_quote');if(frete)frete.textContent='Indisponível';if(status)status.textContent=result.data.message||'Não foi possível calcular o frete agora.';return;}
        var options=result.data.shipping_options||[];
        var selected=result.data.selected_option||options[0];
        var quote=makeQuote(selected,cep);
        save(quote);
        if(frete)frete.textContent=money(quote.total);
        if(totalEl)totalEl.textContent=money(subtotal()+quote.total);
        renderOptions(options,cep);
      })
      .catch(function(){button.disabled=false;button.textContent='Calcular';if(status)status.textContent='Falha de conexão ao calcular o frete.';});
  },true);
})();
