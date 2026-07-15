(function(){
  var form=document.getElementById('checkout-form');
  if(!form)return;
  function quote(){try{return JSON.parse(localStorage.getItem('shopvivaliz_shipping_quote')||'null');}catch(e){return null;}}
  function ensureHidden(name,value){var input=form.querySelector('input[name="'+name+'"]');if(!input){input=document.createElement('input');input.type='hidden';input.name=name;form.appendChild(input);}input.value=value==null?'':String(value);}
  function syncQuote(){var q=quote();if(!q)return false;var now=Math.floor(Date.now()/1000);if(q.expires_at&&q.expires_at<now){localStorage.removeItem('shopvivaliz_shipping_quote');return false;}ensureHidden('shipping_total',Number(q.total)||0);ensureHidden('shipping_label',q.label||'');ensureHidden('shipping_service',q.option&&q.option.id?q.option.id:'');ensureHidden('shipping_cep',q.cep||'');ensureHidden('shipping_quote_id',q.quote_id||'');ensureHidden('shipping_expires_at',q.expires_at||'');return true;}
  syncQuote();
  form.addEventListener('submit',function(event){var q=quote();if(q&&q.expires_at&&q.expires_at<Math.floor(Date.now()/1000)){event.preventDefault();event.stopImmediatePropagation();localStorage.removeItem('shopvivaliz_shipping_quote');var status=document.getElementById('checkout-status');if(status){status.textContent='Sua cotação de frete expirou. Volte ao carrinho e calcule novamente.';status.className='checkout-status-msg err';}return;}syncQuote();},true);
})();
