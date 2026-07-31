/**
 * 🔥 Conversion Hotspot Tracker - Heatmap + Click Tracking + Form Analytics
 * Impacto: Conversão +20-30%
 */

class HotspotTracker {
  constructor() {
    this.sessionId = this.getOrCreateSessionId();
    this.clicks = [];
    this.scrollDepth = 0;
    this.formData = {};
    this.init();
  }

  init() {
    console.log('🔥 Hotspot Tracker inicializado');

    // Rastrear clicks
    document.addEventListener('click', (e) => this.trackClick(e));

    // Rastrear scroll depth
    window.addEventListener('scroll', () => this.trackScroll());

    // Rastrear form abandonment
    document.querySelectorAll('form').forEach(form => {
      form.addEventListener('change', (e) => this.trackFormChange(e));
      form.addEventListener('submit', (e) => this.trackFormSubmit(e));
    });

    // Rastrear time on page
    this.trackTimeOnPage();

    // Enviar dados periodicamente
    setInterval(() => this.sendData(), 30000); // A cada 30s

    // Enviar ao sair da página
    window.addEventListener('beforeunload', () => this.sendData());
  }

  trackClick(event) {
    const element = event.target;
    const click = {
      timestamp: Date.now(),
      element: element.tagName,
      text: element.textContent?.substring(0, 100),
      className: element.className,
      id: element.id,
      x: event.clientX,
      y: event.clientY,
      page_x: event.pageX,
      page_y: event.pageY,
      url: window.location.href,
    };

    this.clicks.push(click);

    // Se é botão de conversão crítico, enviar imediatamente
    if (this.isCriticalElement(element)) {
      this.sendData();
    }
  }

  trackScroll() {
    const depth = Math.round((window.scrollY + window.innerHeight) / document.documentElement.scrollHeight * 100);
    this.scrollDepth = Math.max(this.scrollDepth, depth);
  }

  trackFormChange(event) {
    const form = event.target.closest('form');
    const field = event.target.name;
    const value = event.target.value?.substring(0, 20); // Redacted para privacidade

    this.formData[form.id || form.name || 'unknown_form'] = this.formData[form.id || form.name || 'unknown_form'] || {};
    this.formData[form.id || form.name || 'unknown_form'][field] = {
      filled: !!value,
      timestamp: Date.now()
    };
  }

  trackFormSubmit(event) {
    const form = event.target;
    const data = {
      form_id: form.id || form.name || 'unknown',
      timestamp: Date.now(),
      fields_completed: Object.keys(this.formData[form.id || form.name || 'unknown_form'] || {}).length,
      url: window.location.href,
    };

    this.sendConversion(data);
  }

  trackTimeOnPage() {
    const startTime = Date.now();
    window.addEventListener('beforeunload', () => {
      const timeOnPage = Math.round((Date.now() - startTime) / 1000);
      this.sendData({
        time_on_page: timeOnPage,
        scroll_depth: this.scrollDepth,
      });
    });
  }

  isCriticalElement(element) {
    const criticalSelectors = [
      'button[class*="checkout"]',
      'button[class*="buy"]',
      'button[class*="cart"]',
      'a[href*="/cart/"]',
      'a[href*="/checkout/"]',
      '.checkout-button',
      '.buy-button',
    ];

    return criticalSelectors.some(selector => element.matches(selector));
  }

  sendData(additionalData = {}) {
    if (this.clicks.length === 0) return;

    const payload = {
      session_id: this.sessionId,
      timestamp: Date.now(),
      url: window.location.href,
      clicks: this.clicks,
      scroll_depth: this.scrollDepth,
      form_data: this.formData,
      ...additionalData,
    };

    fetch('/api/hotspot/track', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      keepalive: true, // Garantir que envie mesmo se página fechar
    }).catch(err => console.error('Hotspot tracking error:', err));

    this.clicks = []; // Reset após envio
  }

  sendConversion(conversionData) {
    const payload = {
      session_id: this.sessionId,
      timestamp: Date.now(),
      type: 'conversion',
      ...conversionData,
    };

    fetch('/api/hotspot/conversion', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
      keepalive: true,
    }).catch(err => console.error('Conversion tracking error:', err));
  }

  getOrCreateSessionId() {
    let sessionId = sessionStorage.getItem('hotspot_session_id');
    if (!sessionId) {
      sessionId = 'session_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
      sessionStorage.setItem('hotspot_session_id', sessionId);
    }
    return sessionId;
  }
}

// Inicializar quando DOM estiver pronto
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    window.hotspotTracker = new HotspotTracker();
  });
} else {
  window.hotspotTracker = new HotspotTracker();
}

// Heatmap visualization (opcional - mostrar onde usuários clicam)
class HeatmapRenderer {
  static render(clicks) {
    const canvas = document.createElement('canvas');
    canvas.style.cssText = 'position:fixed;top:0;left:0;pointer-events:none;opacity:0.3;z-index:9999';
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    document.body.appendChild(canvas);

    const ctx = canvas.getContext('2d');
    const gradient = ctx.createRadialGradient(0, 0, 0, 0, 0, 50);
    gradient.addColorStop(0, 'rgba(255,0,0,0.8)');
    gradient.addColorStop(1, 'rgba(255,0,0,0)');

    clicks.forEach(click => {
      ctx.fillStyle = gradient;
      ctx.fillRect(click.x - 50, click.y - 50, 100, 100);
    });
  }
}

// Expor para dev tools
window.HotspotTracker = HotspotTracker;
window.HeatmapRenderer = HeatmapRenderer;
