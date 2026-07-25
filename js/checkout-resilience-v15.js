(function(){
  var form=document.getElementById('checkout-form');
  if(!form)return;

  var status=document.getElementById('checkout-status');
  var button=document.getElementById('checkout-submit')||document.getElementById('submit-btn')||form.querySelector('[type="submit"]');
  var messages={
    missing_required_fields:'Revise os dados obrigatórios antes de continuar.',
    insufficient_stock:'Um ou mais produtos ficaram sem estoque. Atualize o carrinho.',
    shipping_quote_expired:'A cotação de frete expirou. Volte ao carrinho e calcule novamente.',
    shipping_quote_invalid:'A cotação de frete mudou. Recalcule o frete no carrinho.',
    invalid_shipping_quote:'A cotação de frete está incompleta. Recalcule antes de finalizar.',
    product_not_found:'Um produto não está mais disponível.',
    provider_error:'O serviço externo está temporariamente indisponível.',
    rate_limited:'Muitas tentativas em sequência. Aguarde alguns instantes.',
    order_storage_unavailable:'O armazenamento do pedido está indisponível no momento.',
    order_write_failed:'Não foi possível salvar o pedido. Tente novamente em instantes.'
  };

  window.svCheckoutErrorMessage=function(code,fallback){
    return messages[code]||fallback||'Não foi possível concluir o pedido agora.';
  };

  form.addEventListener('submit',function(){
    if(button){
      button.dataset.originalText=button.dataset.originalText||button.textContent;
      setTimeout(function(){
        if(button.disabled&&(button.textContent==='Enviando...'||button.textContent==='Enviando…')){
          button.textContent='Processando com segurança...';
        }
      },2500);
    }
    if(status){
      status.setAttribute('role','status');
      status.setAttribute('aria-live','polite');
    }
  },true);

  window.addEventListener('offline',function(){
    if(!status)return;
    status.textContent='Você está sem conexão. O pedido não foi enviado.';
    status.className='status-message err';
  });
})();
