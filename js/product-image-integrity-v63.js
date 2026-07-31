(function(){
  var detail=document.querySelector('.product-detail');
  if(!detail)return;
  var box=detail.querySelector('.product-detail-image');
  var image=box?box.querySelector('img'):null;
  if(!box||!image)return;

  function invalid(src){
    var value=String(src||'').toLowerCase();
    return !value||value.indexOf('placeholder')!==-1||value.indexOf('logo-vivaliz')!==-1;
  }

  function unavailable(){
    box.innerHTML='';
    box.classList.add('product-image-unavailable');
    var panel=document.createElement('div');
    panel.className='product-image-unavailable-panel';
    panel.innerHTML='<strong>Imagem indisponível</strong><span>Este produto ainda não possui uma imagem validada no catálogo.</span>';
    box.appendChild(panel);
    var schema=document.querySelector('script[type="application/ld+json"]');
    if(schema&&schema.textContent.indexOf('"@type": "Product"')!==-1){
      try{var data=JSON.parse(schema.textContent);delete data.image;schema.textContent=JSON.stringify(data); }catch(e){}
    }
  }

  if(invalid(image.getAttribute('src'))){unavailable();return;}
  image.addEventListener('error',unavailable,{once:true});
})();
