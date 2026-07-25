(function(){
  var cards=Array.prototype.slice.call(document.querySelectorAll('.product-card'));
  if(!cards.length)return;
  cards.forEach(function(card){
    var price=card.querySelector('.product-price');
    var buy=card.querySelector('.buy-button');
    var text=price?price.textContent.trim():'';
    var valid=/R\$\s*[0-9]/.test(text)&&!/R\$\s*0([,.]00)?\b/.test(text);
    if(!valid){
      card.classList.add('has-invalid-price');
      if(price)price.textContent='Preço indisponível';
      if(buy){buy.disabled=true;buy.textContent='Indisponível';}
    }else{
      card.classList.add('has-valid-price');
    }
  });
})();
