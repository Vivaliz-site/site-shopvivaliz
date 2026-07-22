(function(){
  var cards=Array.prototype.slice.call(document.querySelectorAll('.product-card'));
  if(!cards.length)return;
  cards.forEach(function(card){
    var badge=card.querySelector('.out-of-stock-badge');
    var buy=card.querySelector('.buy-button');
    var out=card.classList.contains('is-out-of-stock')||!!badge;
    if(out){
      card.classList.add('has-no-stock');
      if(buy){buy.disabled=true;buy.textContent='Esgotado';buy.setAttribute('aria-disabled','true');}
    }else{
      card.classList.add('has-stock');
    }
  });
})();
