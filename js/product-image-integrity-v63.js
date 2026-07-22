(function(){
  var detail=document.querySelector('.product-detail');
  if(!detail)return;
  var box=detail.querySelector('.product-detail-image');
  var image=box?box.querySelector('img'):null;
  if(!box||!image)return;
  var fallback='/images/logo-vivaliz-square.png';

  function invalid(src){
    var value=String(src||'').toLowerCase();
    return !value||value.indexOf('placeholder')!==-1||value.indexOf('logo-vivaliz')!==-1;
  }

  function unavailable(){
    box.classList.add('product-image-unavailable');
    if(image.getAttribute('src')!==fallback)image.setAttribute('src',fallback);
    var schema=document.querySelector('script[type="application/ld+json"]');
    if(schema&&schema.textContent.indexOf('"@type": "Product"')!==-1){
      try{var data=JSON.parse(schema.textContent);delete data.image;schema.textContent=JSON.stringify(data); }catch(e){}
    }
  }

  if(invalid(image.getAttribute('src'))){unavailable();return;}
  image.addEventListener('error',unavailable,{once:true});
})();
