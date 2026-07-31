(() => {
  const params = new URLSearchParams(window.location.search || '');
  window.ShopVivalizLizConfig = window.ShopVivalizLizConfig || {};
  window.ShopVivalizLizConfig.baseApi = '/api/liz-router.php';
  window.ShopVivalizLizConfig.knowledgeApi = '/api/liz/intelligent-knowledge';
  window.ShopVivalizLizConfig.knowledgeEnabled = window.ShopVivalizLizConfig.knowledgeEnabled === true
    || params.get('lizKnowledge') === '1';
})();
