(function(){
  var cards=Array.prototype.slice.call(document.querySelectorAll('.category-slide'));
  if(!cards.length)return;

  function normalize(value){
    return String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').trim().toLowerCase().replace(/\s+/g,' ');
  }

  function removePlaceholders(){
    cards.forEach(function(card){
      var icon=card.querySelector('.category-slide-icon');
      if(icon)icon.remove();
    });
  }

  function render(rows){
    var byCategory={};
    rows.forEach(function(row){byCategory[normalize(row.category)]=row;});
    cards.forEach(function(card){
      var title=card.querySelector('strong');
      var wrapper=card.querySelector('.category-slide-image-wrapper');
      var row=title?byCategory[normalize(title.textContent)]:null;
      if(!title||!wrapper||!row||!row.image_url){return;}
      var image=document.createElement('img');
      image.className='category-slide-real-image';
      image.src=row.image_url;
      image.alt='Produto real da categoria '+title.textContent.trim();
      image.loading='lazy';
      image.decoding='async';
      image.width=320;
      image.height=220;
      image.addEventListener('load',function(){card.classList.add('has-real-image');});
      image.addEventListener('error',function(){});
      wrapper.replaceChildren(image);
      card.dataset.categoryImageSku=String(row.sku||'');
      card.dataset.categoryImageProduct=String(row.product_name||'');
    });
  }

  removePlaceholders();
  fetch('/api/catalog/category-images.php',{credentials:'same-origin',cache:'no-store'})
    .then(function(response){if(!response.ok)throw new Error('category_images_unavailable');return response.json();})
    .then(function(payload){render(Array.isArray(payload.categories)?payload.categories:[]);})
    .catch(function(){});
})();
