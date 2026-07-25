(function(){
  var cards=Array.prototype.slice.call(document.querySelectorAll('.category-slide'));
  if(!cards.length)return;

  function normalized(value){
    return String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').trim().toLowerCase();
  }

  function usableImage(value){
    var image=String(value||'').trim();
    if(!image)return false;
    if(image.indexOf('logo-vivaliz')!==-1)return false;
    if(image.indexOf('placeholder')!==-1)return false;
    return /^(https?:)?\/\//.test(image)||image.charAt(0)==='/';
  }

  function productScore(product){
    var score=0;
    if(Number(product.stock||0)>0)score+=4;
    if(Number(product.price||0)>0)score+=2;
    if(product.slug)score+=1;
    return score;
  }

  fetch('/api/catalog/fallback-products.json',{credentials:'same-origin',cache:'no-store'})
    .then(function(response){if(!response.ok)throw new Error('catalog_unavailable');return response.json();})
    .then(function(products){
      if(!Array.isArray(products))return;
      var byCategory={};
      products.forEach(function(product){
        if(!product||!usableImage(product.image_url))return;
        var key=normalized(product.category);
        if(!key)return;
        var current=byCategory[key];
        if(!current||productScore(product)>productScore(current))byCategory[key]=product;
      });

      cards.forEach(function(card){
        var title=card.querySelector('strong');
        var wrapper=card.querySelector('.category-slide-image-wrapper');
        if(!title||!wrapper)return;
        var product=byCategory[normalized(title.textContent)];
        if(!product)return;

        wrapper.innerHTML='';
        var image=document.createElement('img');
        image.className='category-slide-real-image';
        image.src=product.image_url;
        image.alt='Produto da categoria '+title.textContent.trim();
        image.loading='lazy';
        image.decoding='async';
        image.addEventListener('error',function(){card.remove();});
        wrapper.appendChild(image);
        card.dataset.categoryImageSku=String(product.sku||product.id||'');
      });
    })
    .catch(function(){
      cards.forEach(function(card){
        var placeholder=card.querySelector('.category-slide-icon');
        if(placeholder)card.remove();
      });
    });
})();
