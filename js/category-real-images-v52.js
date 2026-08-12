(function(){
  var cards=Array.prototype.slice.call(document.querySelectorAll('.category-slide'));
  if(!cards.length)return;

  function normalize(value){
    return String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').trim().toLowerCase().replace(/\s+/g,' ');
  }

  function hasValidRenderedImage(wrapper){
    if(!wrapper)return false;
    var current=wrapper.querySelector('img');
    if(!current)return false;
    var src=String(current.getAttribute('src')||'').trim();
    if(!src)return false;
    var lower=src.toLowerCase();
    return lower.indexOf('placeholder')===-1 && lower.indexOf('logo-vivaliz')===-1;
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

      // A home ja renderiza no servidor os assets curados para os grupos
      // principais (mesmo comportamento da referencia de 10/08/2026).
      // Nunca substituir uma imagem valida ja presente por uma escolha
      // dinamica do runtime, pois isso fazia as categorias mudarem apos deploy.
      if(hasValidRenderedImage(wrapper)){
        card.classList.add('has-real-image');
        return;
      }

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
      image.addEventListener('load',function(){
        wrapper.replaceChildren(image);
        card.classList.add('has-real-image');
      });
      image.addEventListener('error',function(){});
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
