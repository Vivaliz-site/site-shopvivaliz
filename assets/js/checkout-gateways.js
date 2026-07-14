(function(){
'use strict';
if(!/\/checkout(?:\.php)?(?:\?|$)/.test(location.pathname+location.search)) return;
function ready(fn){document.readyState==='loading'?document.addEventListener('DOMContentLoaded',fn):fn();}
ready(function(){
  var form=document.getElementById('checkout-form');
  var cep=document.getElementById('cep-input');
  var address=document.getElement