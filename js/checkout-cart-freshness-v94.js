(function(){
  var form=document.getElementById('checkout-form');
  if(!form)return;

  var validatedAt=Number(localStorage.getItem('shopvivaliz_cart_validated_at')||0);
  var stale=!validatedAt||(Date.now()-validatedAt)>15*60*1000;
  if(!stale)return;

  var button=document.getElementById('checkout-submit')||document.getElementById('submit-btn')||form.querySelector('[type="submit"]');
  if(button){
    button.disabled=false;
    if(!button.dataset.originalText)button.dataset.originalText=button.textContent||'Confirmar pedido';
    button.textContent=button.dataset.originalText;
  }

  var status=document.getElementById('checkout-status');
  if(status){
    status.textContent='Seu carrinho foi alterado recentemente. Revise os dados, depois confirme o pedido.';
    status.className='status-message warn';
  }

  if(!form.querySelector('.sv-cart-freshness-link')){
    var link=document.createElement('a');
    link.href='/carrinho';
    link.className='sv-cart-freshness-link';
    link.textContent='Voltar ao carrinho';
    link.style.cssText='display:inline-flex;margin-top:12px;color:#0b4f88;font-weight:800;text-decoration:none';
    form.appendChild(link);
  }
})();
