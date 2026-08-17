(function(){
  if(window.__svCatalogConversionV4Initialized)return;

  var page=document.querySelector('.catalog-page');
  if(!page)return;
  window.__svCatalogConversionV4Initialized=true;

  document.body.classList.add('sv-page-catalog');
  var header=document.querySelector('.catalog-header');
  var syncCatalogTop=function(){
    var isTop=header?header.getBoundingClientRect().bottom>160:window.scrollY<240;
    document.body.classList.toggle('sv-catalog-top',isTop);
  };
  syncCatalogTop();
  window.addEventListener('scroll',syncCatalogTop,{passive:true});
  window.addEventListener('resize',syncCatalogTop,{passive:true});

  var searchInput=document.getElementById('catalog-search');
  var categorySelect=document.getElementById('catalog-category-select');
  if(searchInput&&!searchInput.getAttribute('placeholder')){
    searchInput.setAttribute('placeholder','Busque por produto, categoria ou SKU');
  }

  var tools=document.querySelector('.catalog-tools');
  var filters=document.querySelector('.category-filters');
  var grid=document.getElementById('product-grid');
  if(!tools||!grid)return;

  var toolbars=Array.prototype.slice.call(document.querySelectorAll('.sv-catalog-toolbar'));
  var toolbar=toolbars.shift()||null;
  toolbars.forEach(function(extra){extra.remove();});

  if(!toolbar){
    toolbar=document.createElement('div');
    toolbar.className='sv-catalog-toolbar';
    toolbar.innerHTML='<div class="sv-catalog-toolbar-left"><button class="sv-filter-toggle" type="button" aria-expanded="false">☰ Categorias</button></div><label class="sv-sort-wrap"><span>Ordenar por</span><select aria-label="Ordenar produtos"><option value="relevance">Relevância</option><option value="price-asc">Menor preço</option><option value="price-desc">Maior preço</option><option value="name">Nome A–Z</option></select></label>';
    tools.insertBefore(toolbar,tools.firstChild);
  }

  var toggle=toolbar.querySelector('.sv-filter-toggle');
  if(toggle&&filters&&!toggle.dataset.svBound){
    toggle.dataset.svBound='1';
    toggle.addEventListener('click',function(){
      var open=filters.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded',open?'true':'false');
    });
  }

  var cards=Array.prototype.slice.call(grid.querySelectorAll('.product-card'));
  cards.forEach(function(card,index){
    if(!card.dataset.originalIndex)card.dataset.originalIndex=String(index);
    var priceEl=card.querySelector('.product-price');
    var titleEl=card.querySelector('h2');
    var text=priceEl?priceEl.textContent:'';
    var numeric=parseFloat(text.replace(/[^0-9,]/g,'').replace('.','').replace(',','.'))||0;
    card.dataset.price=String(numeric);
    card.dataset.name=(titleEl?titleEl.textContent:'').trim().toLocaleLowerCase('pt-BR');
    if(priceEl&&numeric>0&&!card.querySelector('.sv-installment')){
      var installment=document.createElement('span');
      installment.className='sv-installment';
      installment.textContent='Condições de pagamento no checkout';
      priceEl.insertAdjacentElement('afterend',installment);
    }
  });

  var select=toolbar.querySelector('select');
  if(select&&!select.dataset.svBound){
    select.dataset.svBound='1';
    select.addEventListener('change',function(){
      var sorted=cards.slice();
      if(this.value==='price-asc'){
        sorted.sort(function(a,b){return Number(a.dataset.price)-Number(b.dataset.price);});
      }else if(this.value==='price-desc'){
        sorted.sort(function(a,b){return Number(b.dataset.price)-Number(a.dataset.price);});
      }else if(this.value==='name'){
        sorted.sort(function(a,b){return a.dataset.name.localeCompare(b.dataset.name,'pt-BR');});
      }else{
        sorted.sort(function(a,b){return Number(a.dataset.originalIndex)-Number(b.dataset.originalIndex);});
      }
      sorted.forEach(function(card){grid.appendChild(card);});
    });
  }

  if(!cards.length&&!grid.querySelector('.sv-catalog-empty')){
    var empty=document.createElement('div');
    empty.className='sv-catalog-empty';
    empty.innerHTML='<h2>Nenhum produto encontrado</h2><p>Tente outro termo ou fale com a Liz para localizar o item certo.</p>';
    grid.appendChild(empty);
  }

  var bottoms=Array.prototype.slice.call(document.querySelectorAll('.sv-mobile-bottom-nav'));
  var bottom=bottoms.shift()||null;
  bottoms.forEach(function(extra){extra.remove();});

  if(!bottom){
    bottom=document.createElement('nav');
    bottom.className='sv-mobile-bottom-nav';
    bottom.setAttribute('aria-label','Navegação rápida');
    bottom.innerHTML='<a href="/"><b>⌂</b><span>Início</span></a><button type="button" data-action="search"><b>⌕</b><span>Buscar</span></button><button type="button" data-action="filter"><b>☷</b><span>Categorias</span></button><a href="/carrinho"><b>🛒</b><span>Carrinho</span></a><a href="/auth/login.php"><b>◉</b><span>Conta</span></a>';
    document.body.appendChild(bottom);
  }

  if(!bottom.dataset.svBound){
    bottom.dataset.svBound='1';
    bottom.addEventListener('click',function(e){
      var button=e.target.closest('button');
      if(!button)return;
      if(button.dataset.action==='search'&&searchInput){
        searchInput.scrollIntoView({behavior:'smooth',block:'center'});
        setTimeout(function(){searchInput.focus();},300);
      }
      if(button.dataset.action==='filter'){
        if(categorySelect){
          categorySelect.scrollIntoView({behavior:'smooth',block:'center'});
          setTimeout(function(){categorySelect.focus();},300);
        }else if(toggle){
          toggle.click();
          tools.scrollIntoView({behavior:'smooth',block:'start'});
        }
      }
    });
  }
})();
