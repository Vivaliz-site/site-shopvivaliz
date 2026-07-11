(function(){
  var form=document.getElementById('checkout-form');
  if(!form)return;
  var validatedAt=Number(localStorage.getItem('shopvivaliz_cart_validated_at')||0);
  var stale=!validatedAt||(Date.now()-validatedAt)>15*60*1000;
  if(stale){
    var button=document.getElementById('submit-btn');
    if(button){button.disabled=true;button.textContent='Revise o carrinho';}
    var status=document.getElementById('checkout-status');
    if(status){status.textContent='O carrinho precisa ser validado novamente antes de finalizar.';status.className='checkout-status-msg err';}
    var link=document.createElement('a');link.href='/carrinho';link.className='btn btn-primary';link.textContent='Voltar ao carrinho';form.appendChild(link);
  }
})();
