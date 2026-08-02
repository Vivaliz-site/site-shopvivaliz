(function () {
  'use strict';

  function initNewsletter(form) {
    if (!(form instanceof HTMLFormElement) || form.dataset.svNewsletterReady === '1') return;
    form.dataset.svNewsletterReady = '1';

    var email = form.querySelector('input[type="email"]');
    var button = form.querySelector('button[type="submit"]');
    if (!email || !button) return;

    var consent = document.createElement('label');
    consent.className = 'sv-newsletter-consent';
    consent.innerHTML = '<input type="checkbox" required> <span>Quero receber novidades e ofertas e li a <a href="/politica-privacidade">Política de Privacidade</a>.</span>';
    form.appendChild(consent);

    var honeypot = document.createElement('input');
    honeypot.type = 'text';
    honeypot.name = 'website';
    honeypot.tabIndex = -1;
    honeypot.autocomplete = 'off';
    honeypot.setAttribute('aria-hidden', 'true');
    honeypot.style.position = 'absolute';
    honeypot.style.left = '-9999px';
    form.appendChild(honeypot);

    var status = document.createElement('div');
    status.className = 'sv-newsletter-status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    form.appendChild(status);

    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      event.stopImmediatePropagation();

      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }

      var originalText = button.textContent;
      button.disabled = true;
      button.textContent = 'Enviando…';
      status.textContent = '';
      status.className = 'sv-newsletter-status';

      try {
        var response = await fetch('/api/newsletter/subscribe.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
          },
          body: JSON.stringify({
            email: email.value.trim(),
            consent: true,
            website: honeypot.value
          })
        });
        var data = await response.json().catch(function () { return {}; });
        if (!response.ok || !data.ok) {
          throw new Error(data.message || 'Não foi possível concluir o cadastro.');
        }
        status.classList.add('is-success');
        status.textContent = data.message || 'Enviamos um link de confirmação para o seu e-mail.';
        form.reset();
      } catch (error) {
        status.classList.add('is-error');
        status.textContent = error && error.message ? error.message : 'Falha de conexão. Tente novamente.';
      } finally {
        button.disabled = false;
        button.textContent = originalText;
      }
    }, true);
  }

  function init() {
    document.querySelectorAll('.newsletter-form').forEach(initNewsletter);
    if (!document.getElementById('sv-newsletter-style')) {
      var style = document.createElement('style');
      style.id = 'sv-newsletter-style';
      style.textContent = '.newsletter-form{position:relative}.sv-newsletter-consent{display:flex;align-items:flex-start;gap:8px;grid-column:1/-1;color:#475569;font-size:12px;line-height:1.4}.sv-newsletter-consent input{margin-top:2px}.sv-newsletter-consent a{color:inherit;text-decoration:underline}.sv-newsletter-status{grid-column:1/-1;min-height:20px;font-size:13px;font-weight:700}.sv-newsletter-status.is-success{color:#166534}.sv-newsletter-status.is-error{color:#b91c1c}';
      document.head.appendChild(style);
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
