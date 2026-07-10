(function () {
  var toggle = document.getElementById('menuToggle');
  var menu = document.getElementById('navMenu');
  if (toggle && menu && !toggle.dataset.svBound) {
    toggle.dataset.svBound = '1';
    toggle.addEventListener('click', function () {
      var open = menu.classList.toggle('active');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    menu.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () {
        menu.classList.remove('active');
        toggle.setAttribute('aria-expanded', 'false');
      });
    });
  }

  var root = document.querySelector('.sv-liz-assistant');
  if (!root) return;
  var trigger = root.querySelector('.sv-liz-trigger');
  var panel = root.querySelector('.sv-liz-panel');
  var close = root.querySelector('.sv-liz-close');
  var form = root.querySelector('.sv-liz-form');
  var input = root.querySelector('input');
  var messages = root.querySelector('.sv-liz-messages');
  var endpoint = root.getAttribute('data-endpoint') || '/api/agent/squad-chat.php';

  function setOpen(open) {
    panel.hidden = !open;
    trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) setTimeout(function () { input.focus(); }, 50);
  }
  trigger.addEventListener('click', function () { setOpen(panel.hidden); });
  close.addEventListener('click', function () { setOpen(false); });

  function addMessage(text, type) {
    var item = document.createElement('div');
    item.className = 'sv-liz-message ' + (type === 'user' ? 'is-user' : 'is-liz');
    item.textContent = text;
    messages.appendChild(item);
    messages.scrollTop = messages.scrollHeight;
    return item;
  }

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    var text = input.value.trim();
    if (!text) return;
    addMessage(text, 'user');
    input.value = '';
    var loading = addMessage('Só um instante, estou verificando para você…', 'liz');
    try {
      var response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: text, source: 'site-assistant' })
      });
      if (!response.ok) throw new Error('HTTP ' + response.status);
      var data = await response.json();
      loading.textContent = data.reply || data.message || data.response || 'Recebi sua mensagem. Nossa equipe pode continuar o atendimento com você.';
    } catch (error) {
      loading.textContent = 'Não consegui conectar agora. Você pode continuar pelo formulário de contato que eu encaminho sua dúvida para a equipe.';
    }
  });
})();