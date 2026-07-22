(function(){
  var detail=document.querySelector('.product-detail');
  if(!detail)return;
  var badge=detail.querySelector('.out-of-stock-badge');
  var buy=document.getElementById('buy-now');
  var out=!!badge||!!(buy&&buy.disabled);
  detail.classList.toggle('has-no-stock',out);
  detail.classList.toggle('has-stock',!out);
  if(out&&buy){buy.disabled=true;buy.textContent='Esgotado';buy.setAttribute('aria-disabled','true');}
})();
