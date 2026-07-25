<?php
declare(strict_types=1);

/**
 * Popup promocional de cupons para a home
 * Mostra cupons ativos e permite copiar código
 */

function sv_popup_cupons_html(): string {
    return <<<'HTML'
<div id="popup-cupons-modal" class="popup-cupons-modal" style="display: none;">
  <div class="popup-cupons-overlay" onclick="sv_popup_cupons_close()"></div>
  <div class="popup-cupons-content">
    <button class="popup-cupons-close" onclick="sv_popup_cupons_close()">✕</button>

    <div class="popup-cupons-header">
      <h2>🎁 Cupons Promocionais</h2>
      <p>Use nossos cupons para economizar na sua compra!</p>
    </div>

    <div class="popup-cupons-list" id="popup-cupons-list">
      <div class="popup-cupons-loading">Carregando cupons...</div>
    </div>

    <div class="popup-cupons-footer">
      <label class="popup-cupons-checkbox">
        <input type="checkbox" id="popup-cupons-dont-show" onchange="sv_popup_cupons_save_preference()">
        Não mostrar novamente hoje
      </label>
    </div>
  </div>
</div>

<style>
.popup-cupons-modal {
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  z-index: 9999;
  display: flex;
  align-items: center;
  justify-content: center;
  animation: fadeIn 0.3s ease-in-out;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.popup-cupons-overlay {
  position: absolute;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.5);
  cursor: pointer;
}

.popup-cupons-content {
  position: relative;
  background: linear-gradient(135deg, #fff 0%, #f9f9f9 100%);
  border-radius: 12px;
  box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
  max-width: 500px;
  width: 90%;
  max-height: 80vh;
  overflow-y: auto;
  padding: 30px;
  animation: slideIn 0.4s ease-out;
}

@keyframes slideIn {
  from {
    transform: translateY(-50px);
    opacity: 0;
  }
  to {
    transform: translateY(0);
    opacity: 1;
  }
}

.popup-cupons-close {
  position: absolute;
  top: 15px;
  right: 15px;
  background: none;
  border: none;
  font-size: 28px;
  cursor: pointer;
  color: #999;
  transition: color 0.2s;
}

.popup-cupons-close:hover {
  color: #333;
}

.popup-cupons-header {
  text-align: center;
  margin-bottom: 25px;
}

.popup-cupons-header h2 {
  margin: 0 0 8px 0;
  color: #333;
  font-size: 24px;
}

.popup-cupons-header p {
  margin: 0;
  color: #666;
  font-size: 14px;
}

.popup-cupons-list {
  display: grid;
  gap: 12px;
  margin-bottom: 20px;
}

.popup-cupons-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 15px;
  background: white;
  border: 2px solid #e0e0e0;
  border-radius: 8px;
  transition: all 0.2s;
}

.popup-cupons-item:hover {
  border-color: #4CAF50;
  box-shadow: 0 4px 12px rgba(76, 175, 80, 0.15);
}

.popup-cupons-item-info {
  flex: 1;
}

.popup-cupons-item-code {
  font-size: 18px;
  font-weight: bold;
  color: #333;
  font-family: monospace;
  margin-bottom: 4px;
}

.popup-cupons-item-label {
  font-size: 12px;
  color: #666;
}

.popup-cupons-item-badge {
  display: inline-block;
  padding: 4px 8px;
  background: #4CAF50;
  color: white;
  border-radius: 4px;
  font-size: 11px;
  font-weight: bold;
  margin-left: 8px;
}

.popup-cupons-item-copy {
  padding: 8px 16px;
  background: #4CAF50;
  color: white;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  font-size: 12px;
  font-weight: bold;
  transition: background 0.2s;
  margin-left: 12px;
  white-space: nowrap;
}

.popup-cupons-item-copy:hover {
  background: #45a049;
}

.popup-cupons-item-copy.copied {
  background: #2196F3;
}

.popup-cupons-loading {
  text-align: center;
  padding: 20px;
  color: #999;
  font-size: 14px;
}

.popup-cupons-footer {
  border-top: 1px solid #e0e0e0;
  padding-top: 15px;
  text-align: center;
}

.popup-cupons-checkbox {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 12px;
  color: #666;
  cursor: pointer;
  user-select: none;
}

.popup-cupons-checkbox input {
  cursor: pointer;
}

@media (max-width: 600px) {
  .popup-cupons-content {
    width: 95%;
    padding: 20px;
  }

  .popup-cupons-item {
    flex-direction: column;
    align-items: flex-start;
  }

  .popup-cupons-item-copy {
    width: 100%;
    margin-left: 0;
    margin-top: 10px;
  }
}
</style>

<script>
function sv_popup_cupons_close() {
  const modal = document.getElementById('popup-cupons-modal');
  if (modal) modal.style.display = 'none';
}

function sv_popup_cupons_show() {
  const modal = document.getElementById('popup-cupons-modal');
  if (modal) modal.style.display = 'flex';
  sv_popup_cupons_load();
}

function sv_popup_cupons_save_preference() {
  const checkbox = document.getElementById('popup-cupons-dont-show');
  if (checkbox && checkbox.checked) {
    const now = new Date();
    const tomorrow = new Date(now.getTime() + 24 * 60 * 60 * 1000);
    localStorage.setItem('popup-cupons-hide-until', tomorrow.toISOString());
  }
}

function sv_popup_cupons_should_show() {
  const hideUntil = localStorage.getItem('popup-cupons-hide-until');
  if (!hideUntil) return true;
  return new Date(hideUntil) <= new Date();
}

function sv_popup_cupons_copy_code(button, code) {
  navigator.clipboard.writeText(code).then(() => {
    const original = button.textContent;
    button.textContent = '✓ Copiado!';
    button.classList.add('copied');
    setTimeout(() => {
      button.textContent = original;
      button.classList.remove('copied');
    }, 2000);
  });
}

function sv_popup_cupons_load() {
  const list = document.getElementById('popup-cupons-list');
  if (!list) return;

  fetch('/api/coupons/active.php')
    .then(r => r.json())
    .then(coupons => {
      if (!Array.isArray(coupons) || coupons.length === 0) {
        list.innerHTML = '<div class="popup-cupons-loading">Nenhum cupom disponível no momento.</div>';
        return;
      }

      list.innerHTML = coupons.map(c => `
        <div class="popup-cupons-item">
          <div class="popup-cupons-item-info">
            <div class="popup-cupons-item-code">${sv_escape_html(c.code)}</div>
            <div class="popup-cupons-item-label">
              ${sv_escape_html(c.label)}
              <span class="popup-cupons-item-badge">${sv_format_discount(c.type, c.value)}</span>
            </div>
          </div>
          <button class="popup-cupons-item-copy" onclick="sv_popup_cupons_copy_code(this, '${sv_escape_html(c.code)}')">Copiar</button>
        </div>
      `).join('');
    })
    .catch(err => {
      console.error('[popup-cupons] load error:', err);
      list.innerHTML = '<div class="popup-cupons-loading">Erro ao carregar cupons.</div>';
    });
}

function sv_escape_html(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

function sv_format_discount(type, value) {
  switch (type) {
    case 'percent':
      return value + '% OFF';
    case 'fixed':
      return 'R$ ' + value.toFixed(2) + ' OFF';
    case 'shipping':
      return 'FRETE GRÁTIS';
    default:
      return 'DESCONTO';
  }
}

// Mostrar popup ao carregar a página
document.addEventListener('DOMContentLoaded', () => {
  if (sv_popup_cupons_should_show()) {
    setTimeout(() => sv_popup_cupons_show(), 1500);
  }
});
</script>
HTML;
}
