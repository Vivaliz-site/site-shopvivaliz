/* Preserve only the delivery postcode within this tab. Quotes are always
 * calculated by the existing shipping handlers; never copy freight totals. */
(function () {
  'use strict';
  var KEY = 'shopvivaliz_postcode_session_v1';
  var MAX_AGE_MS = 30 * 60 * 1000;

  function normalize(value) {
    var cep = String(value || '').replace(/\D/g, '');
    return /^\d{8}$/.test(cep) && !/^(\d)\1{7}$/.test(cep) ? cep : '';
  }
  function clear() {
    try { window.sessionStorage.removeItem(KEY); } catch (error) {}
  }
  function remember(value) {
    var cep = normalize(value);
    if (!cep) { clear(); return; }
    try {
      window.sessionStorage.setItem(KEY, JSON.stringify({cep: cep, saved_at: Date.now()}));
    } catch (error) {}
  }
  function recall() {
    try {
      var raw = window.sessionStorage.getItem(KEY);
      if (!raw) return '';
      var saved = JSON.parse(raw);
      var age = saved ? Date.now() - Number(saved.saved_at) : NaN;
      if (!saved || typeof saved.cep !== 'string' || saved.cep !== normalize(saved.cep)
          || !Number.isFinite(age) || age < 0 || age > MAX_AGE_MS) {
        clear(); return '';
      }
      return saved.cep;
    } catch (error) { clear(); return ''; }
  }
  function init() {
    var input = document.getElementById('p-frete-cep')
      || document.getElementById('frete-cep')
      || document.getElementById('cep-input');
    if (!input || input.dataset.svPostcodeBound === '1') return;
    input.dataset.svPostcodeBound = '1';
    input.addEventListener('input', function () { remember(input.value); });
    input.addEventListener('change', function () { remember(input.value); });
    // An existing checkout draft, autofill or partial edit takes precedence.
    if (String(input.value || '').trim() !== '') {
      if (normalize(input.value)) remember(input.value);
      return;
    }
    var cep = recall();
    if (!cep) return;
    input.value = cep.slice(0, 5) + '-' + cep.slice(5);
    // Reuse normal address lookup and live quote validation, not cached totals.
    input.dispatchEvent(new Event('input', {bubbles: true}));
  }
  function afterPageHandlers() { setTimeout(init, 0); }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', afterPageHandlers, {once: true});
  } else {
    afterPageHandlers();
  }
})();
