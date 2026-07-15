(function(){
  var form=document.getElementById('checkout-form');
  if(!form)return;

  var submit=document.getElementById('checkout-submit')||document.getElementById('submit-btn')||form.querySelector('[type="submit"]');
  var status=document.getElementById('checkout-status');
  var total=document.getElementById('checkout-total')||document.getElementById('cart-total');
  var summary=document.querySelector('.summary-panel')||document.querySelector('.checkout-summary-card');

  function setStatus(message,type){
    if(!status)return;
    status.textContent=message;
    status.className='status-message'+(type?' '+type:'');
  }

  function getCart(){
    try{return JSON.parse(localStorage.getItem('shopvivaliz_cart')||'[]');}
    catch(e){return[];}
  }

  function digits(v){return String(v||'').replace(/\D/g,'');}

  function getField(){
    for(var i=0;i<arguments.length;i++){
      if(form.elements[arguments[i]])return form.elements[arguments[i]];
    }
    return null;
  }

  function clearErrors(){
    form.querySelectorAll('.is-invalid').forEach(function(el){el.classList.remove('is-invalid');});
    form.querySelectorAll('.sv-field-error').forEach(function(el){el.remove();});
  }

  function addError(el,msg){
    el.classList.add('is-invalid');
    var error=document.createElement('small');
    error.className='sv-field-error';
    error.textContent=msg;
    el.insertAdjacentElement('afterend',error);
  }

  function validate(){
    clearErrors();
    var first=null;
    var checks=[
      {el:getField('nome','customer_name'),msg:'Informe seu nome completo.',ok:function(v){return v.length>=5&&v.indexOf(' ')>0;}},
      {el:getField('email','customer_email'),msg:'Informe um e-mail válido.',ok:function(v){return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);}},
      {el:getField('telefone','customer_phone'),msg:'Informe um telefone com DDD.',ok:function(v){return digits(v).length>=10;}},
      {el:getField('cep'),msg:'Informe um CEP válido.',ok:function(v){return digits(v).length===8;}},
      {el:getField('endereco','address'),msg:'Informe o endereço completo.',ok:function(v){return v.length>=8;}}
    ];

    checks.forEach(function(check){
      if(!check.el)return;
      var value=String(check.el.value||'').trim();
      if(!check.ok(value)){
        addError(check.el,check.msg);
        if(!first)first=check.el;
      }
    });

    if(first){
      first.focus();
      first.scrollIntoView({behavior:'smooth',block:'center'});
      setStatus('Revise os campos destacados antes de continuar.','err');
      return false;
    }

    if(!getCart().length){
      setStatus('Seu carrinho está vazio. Adicione um produto antes de finalizar.','err');
      return false;
    }

    return true;
  }

  form.addEventListener('submit',function(e){
    if(!validate()){
      e.preventDefault();
      e.stopImmediatePropagation();
    }
  },true);

  [['telefone','customer_phone'],['cep']].forEach(function(names){
    var el=getField.apply(null,names);
    if(!el)return;
    el.addEventListener('input',function(){
      var value=digits(this.value);
      if(names[0]==='cep'){
        value=value.slice(0,8);
        this.value=value.length>5?value.slice(0,5)+'-'+value.slice(5):value;
      }else{
        value=value.slice(0,11);
        this.value=value.length>10?'('+value.slice(0,2)+') '+value.slice(2,7)+'-'+value.slice(7):value.length>6?'('+value.slice(0,2)+') '+value.slice(2,6)+'-'+value.slice(6):value;
      }
    });
  });

  form.querySelectorAll('input,textarea').forEach(function(el){
    el.addEventListener('input',function(){
      this.classList.remove('is-invalid');
      var next=this.nextElementSibling;
      if(next&&next.classList.contains('sv-field-error'))next.remove();
    });
  });

  var methods=form.querySelectorAll('input[name="payment_method"]');
  methods.forEach(function(radio){
    radio.addEventListener('change',function(){
      var note=document.querySelector('.sv-checkout-note');
      if(note)note.remove();
      var text='';
      if(this.value==='pix')text='O pedido será registrado e os dados do PIX aparecerão após a confirmação.';
      else if(this.value==='boleto')text='O boleto será emitido após a conferência de estoque e frete.';
      else if(this.value==='whatsapp')text='A equipe continuará a finalização pelo WhatsApp.';
      else text='Os dados bancários serão confirmados pela equipe comercial.';
      var box=document.createElement('div');
      box.className='sv-checkout-note';
      box.textContent=text;
      var anchor=document.querySelector('.payment-options');
      if(anchor)anchor.insertAdjacentElement('afterend',box);
    });
  });

  var checked=form.querySelector('input[name="payment_method"]:checked');
  if(checked)checked.dispatchEvent(new Event('change'));

  var mobile=document.createElement('div');
  mobile.className='sv-checkout-mobile-total';
  mobile.innerHTML='<div><strong>'+(total?total.textContent:'—')+'</strong><small>Total estimado</small></div><button type="button">Finalizar pedido</button>';
  document.body.appendChild(mobile);

  var mobileStrong=mobile.querySelector('strong');
  if(total){
    new MutationObserver(function(){mobileStrong.textContent=total.textContent;}).observe(total,{childList:true,characterData:true,subtree:true});
  }

  mobile.querySelector('button').addEventListener('click',function(){
    if(submit)submit.click();
  });

  if(!getCart().length&&submit){
    submit.disabled=true;
    submit.textContent='Carrinho vazio';
  }

  if(summary){
    var edit=document.createElement('a');
    edit.href='/carrinho';
    edit.textContent='Editar carrinho';
    edit.style.cssText='display:inline-flex;margin-top:12px;color:#0b4f88;font-weight:800;text-decoration:none';
    summary.appendChild(edit);
  }
})();
