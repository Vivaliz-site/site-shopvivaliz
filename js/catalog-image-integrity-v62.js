(function(){
  var cards=Array.prototype.slice.call(document.querySelectorAll('.product-card'));
  if(!cards.length)return;

  function invalid(src){
    var value=String(src||'').toLowerCase();
    return !value||value.indexOf('placeholder')!==-1||value.indexOf('logo-vivaliz')!==-1;
  }

  cards.forEach(function(card){
    var image=card.querySelector('.product-image img');
    if(!image)return;
    if(invalid(image.getAttribute('src'))){card.remove();return;}
    image.addEventListener('error',function(){card.remove();});
    image.addEventListener('load',function(){card.classList.add('has-real-product-image');});
  });

  var grid=document.getElementById('product-grid');
  if(grid){
    var observer=new MutationObserver(function(){
      var remaining=grid.querySelectorAll('.product-card').length;
      grid.classList.toggle('is-empty-after-image-validation',remaining===0);
    });
    observer.observe(grid,{childList:true});
  }
})();
