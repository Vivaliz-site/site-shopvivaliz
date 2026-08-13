(function(){
  'use strict';
  if(window.__svCategoryAliasRepairStarted)return;
  window.__svCategoryAliasRepairStarted=true;
  window.__svCategoryAliasRepairDone=false;

  function normalize(value){
    return String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').trim().toLowerCase().replace(/\s+/g,' ');
  }

  var aliases={
    'rodizios & rodas':'rodizios',
    'ferramentas':'ferramentas',
    'organizacao':'armarios e organizacao',
    'jardim & floreiras':'floreiras e jardim',
    'ferragens & fixacao':'fixacao e ferragem'
  };

  function isRealImage(url){
    var value=String(url||'');
    return !!value
      && !/unsplash\.com|placeholder|logo-vivaliz/i.test(value)
      && value.indexOf('/public/assets/category-images/')===-1;
  }

  function finish(){window.__svCategoryAliasRepairDone=true;}

  function repair(cards){
    fetch('/api/catalog/category-images.php',{headers:{Accept:'application/json'},credentials:'same-origin'})
      .then(function(response){
        if(!response.ok)throw new Error('category_images_request_failed');
        return response.json();
      })
      .then(function(data){
        var rows=data&&Array.isArray(data.categories)?data.categories:[];
        var byCategory={};
        rows.forEach(function(row){byCategory[normalize(row&&row.category)]=row;});
        var used={};

        cards.forEach(function(card){
          var title=card.querySelector('strong');
          var image=card.querySelector('img.category-slide-img');
          if(!title||!(image instanceof HTMLImageElement))return;
          if(image.dataset.svCategorySource==='catalog'){
            used[image.currentSrc||image.src]=true;
            return;
          }

          var label=String(title.textContent||'').trim();
          var key=aliases[normalize(label)]||normalize(label);
          var row=byCategory[key];
          var url=row&&String(row.image_url||'').trim();
          if(!row||!isRealImage(url)||used[url])return;

          var fallback=image.currentSrc||image.getAttribute('src')||'';
          image.onerror=function(){
            image.onerror=null;
            image.src=fallback;
            image.dataset.svCategorySource='local-fallback';
            delete image.dataset.svProductSku;
          };
          image.src=url;
          image.alt=String(row.product_name||('Produto da categoria '+label));
          image.dataset.svCategorySource='catalog';
          image.dataset.svProductSku=String(row.sku||'');
          image.loading='lazy';
          image.decoding='async';
          used[url]=true;
          card.classList.add('has-real-image');
        });
      })
      .catch(function(){
        // public-experience-v1.js ja mantem fallback local seguro.
      })
      .then(finish,finish);
  }

  function waitForPrimary(){
    var cards=Array.prototype.slice.call(document.querySelectorAll('.home-categories .category-slide'));
    if(!cards.length){finish();return;}
    var ready=cards.every(function(card){
      var image=card.querySelector('img.category-slide-img');
      return image instanceof HTMLImageElement&&!!image.dataset.svCategorySource;
    });
    if(ready){repair(cards);return;}
    window.setTimeout(waitForPrimary,120);
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',waitForPrimary,{once:true});
  else waitForPrimary();
})();
