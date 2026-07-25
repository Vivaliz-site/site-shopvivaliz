(function(){
  var connection=navigator.connection||navigator.mozConnection||navigator.webkitConnection;
  var saveData=!!(connection&&connection.saveData);
  document.documentElement.classList.toggle('sv-save-data',saveData);
  document.querySelectorAll('img:not([loading])').forEach(function(img,index){
    if(index>1)img.loading='lazy';
    img.decoding='async';
    if(!img.getAttribute('width')&&!img.getAttribute('height'))img.style.aspectRatio=img.style.aspectRatio||'1 / 1';
  });
  document.querySelectorAll('iframe:not([loading])').forEach(function(frame){frame.loading='lazy';});
  if('requestIdleCallback' in window){
    requestIdleCallback(function(){
      document.querySelectorAll('a[href^="/produto/"]').forEach(function(link){link.addEventListener('mouseenter',function(){if(this.dataset.prefetched)return;this.dataset.prefetched='1';var p=document.createElement('link');p.rel='prefetch';p.href=this.href;document.head.appendChild(p);},{once:true});});
    },{timeout:2000});
  }
})();
