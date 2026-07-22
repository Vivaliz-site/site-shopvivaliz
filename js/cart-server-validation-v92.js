(function(){
  var checkout=document.querySelector('.btn-checkout');
  if(!checkout)return;
  checkout.addEventListener('click',function(event){
    var cart=[];try{cart=JSON.parse(localStorage.getItem('shopvivaliz_cart')||'[]');}catch(e){}
    event.preventDefault();checkout.disabled=true;checkout.classList.add('sv-button-loading');
    fetch('/api/cart/validate.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({items:cart})})
      .then(function(response){return response.json().then(function(data){return{ok:response.ok,data:data};});})
      .then(function(result){if(!result.ok)throw result.data;localStorage.setItem('shopvivaliz_cart_validated_at',String(Date.now()));location.href='/checkout';})
      .catch(function(error){
        var messages={product_not_found:'não está mais disponível',insufficient_stock:'não tem estoque suficiente',invalid_price:'está com preço inválido no momento'};
        var detail='Revise preço, estoque e itens do carrinho antes de continuar.';
        if(error&&Array.isArray(error.errors)&&error.errors.length){
          detail=error.errors.map(function(e){
            var reason=messages[e.error]||e.error;
            var stock=e.error==='insufficient_stock'&&typeof e.available==='number'?' (estoque atual: '+e.available+')':'';
            return 'Item "'+e.sku+'" '+reason+stock+'.';
          }).join(' ');
        }
        var live=document.getElementById('svLiveRegion');if(live)live.textContent=detail;
        var visible=document.getElementById('checkout-validate-status');if(visible)visible.textContent=detail;
        checkout.disabled=false;checkout.classList.remove('sv-button-loading');console.warn('cart_validation_failed',error);
      });
  },true);
})();
