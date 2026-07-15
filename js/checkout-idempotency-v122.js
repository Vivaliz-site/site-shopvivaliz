(function(){
  var form=document.getElementById('checkout-form');
  if(!form)return;
  var keyName='shopvivaliz_order_idempotency_key';
  function createKey(){
    if(window.crypto&&crypto.randomUUID)return crypto.randomUUID();
    return 'sv-'+Date.now()+'-'+Math.random().toString(16).slice(2);
  }
  var key=sessionStorage.getItem(keyName)||createKey();
  sessionStorage.setItem(keyName,key);
  var hidden=form.querySelector('input[name="idempotency_key"]');
  if(!hidden){hidden=document.createElement('input');hidden.type='hidden';hidden.name='idempotency_key';form.appendChild(hidden);}
  hidden.value=key;
  form.addEventListener('submit',function(){hidden.value=sessionStorage.getItem(keyName)||key;},true);
  window.addEventListener('shopvivaliz:order-success',function(){sessionStorage.removeItem(keyName);});
})();
