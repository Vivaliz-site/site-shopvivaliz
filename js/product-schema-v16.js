(function(){
  if(!document.querySelector('.product-detail'))return;
  var title=document.querySelector('.product-detail-copy h1');
  var image=document.querySelector('.product-detail-image img');
  var price=document.querySelector('.product-price-label');
  var skuLine=document.querySelector('.product-sku-line');
  var description=document.querySelector('.product-description');
  if(!title)return;
  var numeric=price?parseFloat(price.textContent.replace(/[^0-9,]/g,'').replace('.','').replace(',','.'))||0:0;
  var sku=skuLine?skuLine.textContent.replace(/^SKU:\s*/i,'').trim():'';
  var schema={
    '@context':'https://schema.org',
    '@type':'Product',
    name:title.textContent.trim(),
    description:description?description.textContent.trim():'',
    image:image?[image.src]:[],
    sku:sku,
    brand:{'@type':'Brand',name:'Vivaliz'},
    offers:{'@type':'Offer',url:location.href,priceCurrency:'BRL',price:numeric||undefined,availability:'https://schema.org/InStock'}
  };
  var node=document.createElement('script');
  node.type='application/ld+json';
  node.textContent=JSON.stringify(schema);
  document.head.appendChild(node);
})();
