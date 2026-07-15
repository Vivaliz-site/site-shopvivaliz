(function(){
  var detail=document.querySelector('.product-detail');
  if(!detail)return;
  var price=document.querySelector('.product-price-label');
  var buy=document.getElementById('buy-now');
  var text=price?price.textContent.trim():'';
  var valid=/R\$\s*[0-9]/.test(text)&&!/R\$\s*0([,.]00)?\b/.test(text);
  if(valid){detail.classList.add('has-valid-price');return;}
  detail.classList.add('has-invalid-price');
  if(price)price.textContent='Preço indisponível';
  if(buy){buy.disabled=true;buy.textContent='Preço indisponível';buy.classList.add('btn-disabled');}
  var actions=document.querySelector('.produto-actions');
  if(actions&&!actions.querySelector('.sv-price-contact')){
    var link=document.createElement('a');
    link.className='btn btn-primary sv-price-contact';
    link.href='/contato';
    link.textContent='Consultar valor';
    actions.prepend(link);
  }
})();
