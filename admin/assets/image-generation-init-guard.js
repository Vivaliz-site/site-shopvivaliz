(() => {
  'use strict';

  if (location.pathname.replace(/\/+$/, '') !== '/admin/ai-image-studio/admin_dashboard.php') return;
  if (window.__svImageGenerationInitGuardInstalled) return;
  window.__svImageGenerationInitGuardInstalled = true;

  const targetListenerSignature = ['initialized = false', 'initialize()', 'updateResume()'];

  function armChannel(channel) {
    if (!channel || channel.dataset.svImageInitGuard === 'armed' || channel.dataset.svImageInitGuard === 'done') return;
    channel.dataset.svImageInitGuard = 'armed';

    const originalAddEventListener = channel.addEventListener;
    channel.addEventListener = function guardedAddEventListener(type, listener, options) {
      if (type === 'change' && typeof listener === 'function') {
        let source = '';
        try { source = Function.prototype.toString.call(listener); } catch (_) {}
        const isDuplicateInitializer = targetListenerSignature.every((part) => source.includes(part));
        if (isDuplicateInitializer) {
          channel.dataset.svImageInitGuard = 'done';
          channel.addEventListener = originalAddEventListener;
          window.dispatchEvent(new CustomEvent('sv:image-init-guard', {
            detail: { blocked: 'duplicate-channel-reinitialize' }
          }));
          return;
        }
      }
      return originalAddEventListener.call(this, type, listener, options);
    };
  }

  const existing = document.querySelector('#iv-channel');
  if (existing) armChannel(existing);

  const observer = new MutationObserver(() => {
    const channel = document.querySelector('#iv-channel');
    if (!channel) return;
    armChannel(channel);
    if (channel.dataset.svImageInitGuard === 'done') observer.disconnect();
  });
  observer.observe(document.documentElement, { childList: true, subtree: true });

  setTimeout(() => observer.disconnect(), 15000);
})();
