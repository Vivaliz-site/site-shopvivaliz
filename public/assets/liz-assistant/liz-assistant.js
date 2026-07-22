(() => {
  const API = '/api/agent/squad-chat.php';
  const userIdKey = 'shopvivaliz_liz_user_id';
  let userId = `liz_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 10)}`;
  try {
    userId = localStorage.getItem(userIdKey) || userId;
    localStorage.setItem(userIdKey, userId);
  } catch (error) {
    // Keep chat usable even when storage is blocked.
  }

  const root = document.createElement('div');

  root.innerHTML = `
    <button id="sv-liz-launcher" type="button" aria-label="Abrir assistente Liz" aria-controls="sv-liz-panel" aria-expanded="false">
      <img src="/public/assets/liz-assistant/liz-avatar.png" alt="Liz">
    </button>
    <div id="sv-liz-bubble">Ei! Vi que você tem produtos no carrinho. Finalize agora e use o cupom VOLTEI5 para 5% de desconto! 💸</div>
    <section id="sv-liz-panel" role="dialog" aria-modal="false" aria-label="Liz - Assistente Virtual">
      <div class="sv-head">
        <img src="/public/assets/liz-assistant/logo-oficial.svg" alt="ShopVivaliz">
        <strong>Liz - Assistente Virtual</strong>
        <button class="sv-close" type="button" aria-label="Fechar assistente">×</button>
      </div>
      <div class="sv-hero">
        <video autoplay muted loop playsinline src="/public/assets/liz-assistant/liz-acenando.webm"></video>
      </div>
      <div class="sv-msgs">
        <div class="sv-msg sv-bot">Oi! Eu sou a Liz. Posso ajudar você a encontrar um produto, acompanhar uma compra ou tirar dúvidas.</div>
      </div>
      <div class="sv-quick">
        <button type="button">Encontrar produto</button>
        <button type="button">Compra segura</button>
        <button type="button">Entrega</button>
        <button type="button">Ofertas</button>
      </div>
      <form class="sv-form">
        <input placeholder="Digite sua pergunta" autocomplete="off">
        <button type="submit">Enviar</button>
      </form>
    </section>`;

  document.body.append(root);

  const launcher = root.querySelector('#sv-liz-launcher');
  const panel = root.querySelector('#sv-liz-panel');
  const close = root.querySelector('.sv-close');
  const msgs = root.querySelector('.sv-msgs');
  const input = root.querySelector('input');

  function setOpen(open) {
    panel.classList.toggle('open', open);
    root.classList.toggle('sv-liz-is-open', open);
    launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      setTimeout(() => input.focus(), 60);
    }
  }

  launcher.addEventListener('click', () => setOpen(!panel.classList.contains('open')));
  close.addEventListener('click', () => setOpen(false));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && panel.classList.contains('open')) setOpen(false);
  });

  const add = (text, className) => {
    const item = document.createElement('div');
    item.className = `sv-msg ${className}`;
    item.textContent = text;
    msgs.append(item);
    msgs.scrollTop = msgs.scrollHeight;
    return item;
  };

  async function ask(text) {
    add(text, 'sv-user');
    const waiting = add('Só um instante...', 'sv-bot');

    try {
      const response = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: text, context: 'site-shopvivaliz', user_id: userId }),
      });
      const data = await response.json();
      waiting.textContent = data.answer || data.reply || data.message || data.response || 'Recebi sua mensagem. Nossa equipe pode continuar o atendimento com você.';
    } catch (error) {
      waiting.textContent = 'Não consegui conectar. Tente novamente em instantes.';
    }
  }

  root.querySelector('form').addEventListener('submit', event => {
    event.preventDefault();
    const text = input.value.trim();
    if (!text) return;
    input.value = '';
    ask(text);
  });

  root.querySelectorAll('.sv-quick button').forEach(button => {
    button.addEventListener('click', () => ask(button.textContent.trim()));
  });

  fetch(`${API}?health=1`)
    .then(response => response.json())
    .then(health => {
      root.dataset.health = (health.ok === true && health.endpoint === 'squad-chat' && health.providers) ? 'ok' : 'degraded';
    })
    .catch(() => {
      root.dataset.health = 'offline';
    });

  // Cart Abandonment Recovery (Exit Intent)
  let abandonmentTriggered = false;
  document.addEventListener('mouseleave', event => {
    if (event.clientY <= 0 && !abandonmentTriggered && !panel.classList.contains('open')) {
      try {
        const cart = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]');
        if (cart.length > 0) {
          abandonmentTriggered = true;
          const bubble = root.querySelector('#sv-liz-bubble');
          if (bubble) {
            bubble.classList.add('show-bubble');
            setTimeout(() => bubble.classList.remove('show-bubble'), 8000);
          }
        }
      } catch (err) {}
    }
  });

})();
