(() => {
  const API = '/api/liz-intelligent.php';
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
  const submitButton = root.querySelector('.sv-form button[type="submit"]');
  const quickButtons = Array.from(root.querySelectorAll('.sv-quick button'));
  const conversation = [];
  let requestInFlight = false;

  function setOpen(open) {
    panel.classList.toggle('open', open);
    root.classList.toggle('sv-liz-is-open', open);
    launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open && !requestInFlight) setTimeout(() => input.focus(), 60);
  }

  function setBusy(busy) {
    requestInFlight = busy;
    input.disabled = busy;
    submitButton.disabled = busy;
    quickButtons.forEach(button => { button.disabled = busy; });
    root.dataset.loading = busy ? 'true' : 'false';
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

  async function ask(rawText) {
    const text = rawText.trim();
    if (!text || requestInFlight) return;

    const history = conversation.slice(-12);
    conversation.push({ role: 'user', content: text });
    add(text, 'sv-user');
    const waiting = add('Liz está pensando...', 'sv-bot');
    setBusy(true);

    try {
      const response = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          message: text,
          history,
          context: 'site-shopvivaliz',
        }),
      });

      const data = await response.json().catch(() => ({}));
      root.dataset.provider = data.provider || 'none';

      if (!response.ok || data.ok === false) {
        const backendMessage = data.error || data.answer || data.message;
        if (backendMessage) {
          waiting.textContent = backendMessage;
        } else if (response.status === 429) {
          waiting.textContent = 'A Liz recebeu muitas mensagens agora. Aguarde alguns instantes e tente novamente.';
        } else if (response.status === 503) {
          waiting.textContent = 'A Liz está temporariamente indisponível. Tente novamente em alguns instantes.';
        } else {
          waiting.textContent = 'Não foi possível concluir sua solicitação agora. Tente novamente.';
        }
        return;
      }

      const answer = String(data.answer || data.reply || data.message || data.response || '').trim();
      if (!answer) {
        waiting.textContent = 'A Liz não recebeu uma resposta completa. Tente novamente em alguns instantes.';
        return;
      }

      waiting.textContent = answer;
      conversation.push({ role: 'assistant', content: answer });
    } catch (error) {
      console.error('Liz error:', error);
      waiting.textContent = 'Não foi possível conectar à Liz agora. Verifique sua conexão e tente novamente.';
      root.dataset.provider = 'none';
    } finally {
      setBusy(false);
      input.focus();
    }
  }

  root.querySelector('form').addEventListener('submit', event => {
    event.preventDefault();
    const text = input.value.trim();
    if (!text || requestInFlight) return;
    input.value = '';
    ask(text);
  });

  quickButtons.forEach(button => {
    button.addEventListener('click', () => ask(button.textContent.trim()));
  });

  fetch(`${API}?health=1`, { cache: 'no-store' })
    .then(response => response.json())
    .then(health => {
      const hasProvider = health.providers && Object.values(health.providers).some(Boolean);
      root.dataset.health = health.ok === true && health.endpoint === 'liz-intelligent' && hasProvider
        ? 'ok'
        : 'degraded';
    })
    .catch(() => {
      root.dataset.health = 'offline';
    });

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
      } catch (error) {
        console.debug('Liz cart recovery unavailable:', error);
      }
    }
  });
})();
