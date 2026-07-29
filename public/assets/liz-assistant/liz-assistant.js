(() => {
  const CONFIG_URL = '/public/assets/liz-assistant/liz-config.js?v=1.0.0';

  function loadLizConfig() {
    if (window.ShopVivalizLizConfig) return Promise.resolve();

    return new Promise(resolve => {
      const script = document.createElement('script');
      script.src = CONFIG_URL;
      script.async = false;
      script.onload = () => resolve();
      script.onerror = () => resolve();
      document.head.appendChild(script);
    });
  }

  function initLizAssistant() {
    const lizConfig = window.ShopVivalizLizConfig || {};
    const lizParams = new URLSearchParams(window.location.search || '');
    const storageKey = 'shopvivaliz_liz_knowledge';
    const storedKnowledge = (() => {
      try {
        return window.localStorage ? window.localStorage.getItem(storageKey) : null;
      } catch (error) {
        return null;
      }
    })();
    const lizKnowledgeEnabled = lizConfig.knowledgeEnabled === true || lizParams.get('lizKnowledge') === '1' || storedKnowledge === '1';
    const API = lizKnowledgeEnabled && lizConfig.knowledgeApi ? lizConfig.knowledgeApi : (lizKnowledgeEnabled ? '/api/liz/intelligent-knowledge' : (lizConfig.baseApi || '/api/liz-intelligent.php'));
    const HEALTH_API = lizConfig.baseApi || '/api/liz-intelligent.php';
    const root = document.createElement('div');

    function localGreeting(date = new Date()) {
      const hour = Number(new Intl.DateTimeFormat('pt-BR', {
        hour: '2-digit',
        hour12: false,
        timeZone: 'America/Sao_Paulo',
      }).format(date));
      if (hour >= 5 && hour < 12) return 'Bom dia';
      if (hour >= 12 && hour < 18) return 'Boa tarde';
      return 'Boa noite';
    }

    function normalizeCatalogQuestion(text) {
      const replacements = [
        [/\brod[ií]zios\b/giu, 'rodízio'],
        [/\bpuxadores\b/giu, 'puxador'],
        [/\bdobradiças\b/giu, 'dobradiça'],
        [/\bcorrediças\b/giu, 'corrediça'],
        [/\bfechaduras\b/giu, 'fechadura'],
      ];
      return replacements.reduce((value, [pattern, replacement]) => value.replace(pattern, replacement), text);
    }

    function cleanAssistantText(text) {
      return text.replace(/\*\*/g, '').trim();
    }

    const greeting = localGreeting();

    root.innerHTML = `
      <button id="sv-liz-launcher" type="button" aria-label="Abrir assistente Liz" aria-controls="sv-liz-panel" aria-expanded="false">
        <img src="/public/assets/liz-assistant/liz-avatar.png" alt="Liz">
      </button>
      <div id="sv-liz-bubble">Posso ajudar com os produtos do seu carrinho. O cupom VOLTEI5 oferece 5% de desconto na primeira compra.</div>
      <section id="sv-liz-panel" role="dialog" aria-modal="false" aria-label="Liz - Assistente Virtual">
        <div class="sv-head">
          <img src="/public/assets/liz-assistant/logo-oficial.svg" alt="ShopVivaliz">
          <strong>Liz - Assistente Virtual</strong>
          <button class="sv-close" type="button" aria-label="Fechar assistente">×</button>
        </div>
        <div class="sv-hero">
          <video autoplay muted loop playsinline src="/public/assets/liz-assistant/liz-acenando.webm"></video>
        </div>
        <div class="sv-msgs" aria-live="polite" aria-relevant="additions text">
          <div class="sv-msg sv-bot">${greeting}! Eu sou a Liz, assistente virtual da ShopVivaliz. Posso ajudar a encontrar produtos, acompanhar uma compra ou esclarecer dúvidas.</div>
        </div>
        <form class="sv-form">
          <label for="sv-liz-input" class="sv-sr-only">Digite sua pergunta</label>
          <input id="sv-liz-input" placeholder="Digite sua pergunta" autocomplete="off" maxlength="4000">
          <button type="submit">Enviar</button>
        </form>
      </section>`;

    document.body.append(root);
    root.dataset.knowledge = lizKnowledgeEnabled ? 'enabled' : 'disabled';
    root.dataset.knowledgeSource = lizParams.get('lizKnowledge') === '1' ? 'query' : (storedKnowledge === '1' ? 'localStorage' : (lizConfig.knowledgeEnabled === true ? 'config' : 'default'));
    root.dataset.configLoaded = window.ShopVivalizLizConfig ? 'true' : 'false';

    const launcher = root.querySelector('#sv-liz-launcher');
    const panel = root.querySelector('#sv-liz-panel');
    const close = root.querySelector('.sv-close');
    const msgs = root.querySelector('.sv-msgs');
    const input = root.querySelector('input');
    const submitButton = root.querySelector('.sv-form button[type="submit"]');
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

      const history = conversation.slice(-16);
      conversation.push({ role: 'user', content: text });
      add(text, 'sv-user');
      const waiting = add('Estou consultando as informações para você...', 'sv-bot');
      setBusy(true);

      try {
        const response = await fetch(API, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            message: normalizeCatalogQuestion(text),
            history,
            context: 'site-shopvivaliz',
            clientTimeZone: Intl.DateTimeFormat().resolvedOptions().timeZone,
          }),
        });

        const data = await response.json().catch(() => ({}));
        root.dataset.provider = data.provider || 'none';
        if (typeof data.knowledge_found !== 'undefined') root.dataset.knowledgeFound = String(data.knowledge_found);
        if (data.knowledge_mode) root.dataset.knowledgeMode = String(data.knowledge_mode);

        if (!response.ok || data.ok === false) {
          const backendMessage = data.error || data.answer || data.message;
          if (backendMessage) waiting.textContent = cleanAssistantText(String(backendMessage));
          else if (response.status === 429) waiting.textContent = 'Recebemos muitas mensagens agora. Aguarde alguns instantes e tente novamente.';
          else if (response.status === 503) waiting.textContent = 'A Liz está temporariamente indisponível. Tente novamente em alguns instantes ou fale conosco pelo WhatsApp (37) 99937-4112.';
          else waiting.textContent = 'Não foi possível concluir sua solicitação agora. Tente novamente.';
          return;
        }

        const answer = cleanAssistantText(String(data.answer || data.reply || data.message || data.response || ''));
        if (!answer) {
          waiting.textContent = 'Não recebi uma resposta completa. Tente novamente ou fale conosco pelo WhatsApp (37) 99937-4112.';
          return;
        }

        waiting.textContent = answer;
        conversation.push({ role: 'assistant', content: answer });
      } catch (error) {
        console.error('Liz error:', error);
        waiting.textContent = 'Não foi possível conectar à Liz agora. Verifique sua conexão ou fale conosco pelo WhatsApp (37) 99937-4112.';
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

    fetch(`${HEALTH_API}?health=1`, { cache: 'no-store' })
      .then(response => response.json())
      .then(health => {
        root.dataset.health = health.ok === true && health.endpoint === 'liz-intelligent' ? 'ok' : 'degraded';
      })
      .catch(() => { root.dataset.health = 'offline'; });

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
  }

  loadLizConfig().then(initLizAssistant);
})();
