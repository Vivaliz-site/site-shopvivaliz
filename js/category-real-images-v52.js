(function(){
  var cards=Array.prototype.slice.call(document.querySelectorAll('.category-slide'));
  if(!cards.length)return;

  function normalize(value){
    return String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').trim().toLowerCase().replace(/\s+/g,' ');
  }

  var historical=[
    {
      category:'Ferramentas Manuais',
      aliases:['ferramentas manuais','ferramentas'],
      sku:'08R',
      product_name:'Caixa Ferramentas Sanfonada 5 Gavetas Reforç 50x20x21cm 08R Fercar',
      image_url:'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/7e4e48e34aee6640663aedd7ea28d01e.jpg'
    },
    {
      category:'Vasos Decorativos',
      aliases:['vasos decorativos','jardim & floreiras','jardim','floreiras'],
      sku:'VUNA16',
      product_name:'Vaso Plantas Unique 16 Azul - Japi',
      image_url:'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/5c3c7916e7ba4bd0c2814388fe924da7.jpeg'
    },
    {
      category:'Rodízios',
      aliases:['rodizios','rodizios & rodas','rodas'],
      sku:'1C7Q-LKUT-YLKI',
      product_name:'Rodízio Transparente 035mm Sem Freio Chapa Giratória Soprano',
      image_url:'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/fca5312c9f775715ef616d0331a7605c.jpg'
    },
    {
      category:'Carrinhos Fercar',
      aliases:['carrinhos fercar','organizacao','armarios e organizacao'],
      sku:'C08VM',
      product_name:'Carrinho Fechado Ferramentas C08 6 Gavetas Vermelh Cza Fercar',
      image_url:'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/60b01ba50aa4062d7ce323bab3158f89.jpg'
    },
    {
      category:'Banheiro',
      aliases:['banheiro','ferragens & fixacao','fixacao e ferragem'],
      sku:'TPJ/AS*BR1',
      product_name:'Assento Sanitário Oval Universal Soft Branco Astra',
      image_url:'https://s3.amazonaws.com/tiny-anexos-us/erp/MTI4NTQ1NjExNw/ee19ff5b3db206a3c25d5b779bacd30a.jpg'
    }
  ];

  var byAlias={};
  historical.forEach(function(row){
    row.aliases.forEach(function(alias){byAlias[normalize(alias)]=row;});
  });

  function chooseRow(title,index){
    var key=normalize(title||'');
    if(byAlias[key])return byAlias[key];
    for(var alias in byAlias){
      if(key.indexOf(alias)!==-1||alias.indexOf(key)!==-1)return byAlias[alias];
    }
    return historical[index]||null;
  }

  function apply(card,index){
    var title=card.querySelector('strong');
    var wrapper=card.querySelector('.category-slide-image-wrapper');
    if(!title||!wrapper)return;
    var row=chooseRow(title.textContent,index);
    if(!row||!row.image_url)return;

    var image=document.createElement('img');
    image.className='category-slide-real-image category-slide-img';
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
    image.addEventListener('error',function(){
      var fallback=wrapper.querySelector('img');
      if(fallback)fallback.style.display='';
    });
    card.dataset.categoryImageSku=row.sku;
    card.dataset.categoryImageProduct=row.product_name;
  }

  cards.slice(0,5).forEach(apply);
})();
