(() => {
  'use strict';
  if (window.location.pathname !== '/admin/catalog-optimization/admin_catalog.php') return;

  const list = document.getElementById('candidate-list');
  const load = document.getElementById('load-candidates');
  if (!list || !load) return;

  let timer = 0;
  const restoreUniqueList = () => {
    if (!list.querySelector('.candidate-item:not(details)')) return;
    window.clearTimeout(timer);
    timer = window.setTimeout(() => load.click(), 40);
  };

  const observer = new MutationObserver(restoreUniqueList);
  observer.observe(list, { childList: true, subtree: true });
  restoreUniqueList();

  window.setTimeout(() => load.click(), 350);
})();
