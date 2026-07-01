(function () {
  const key = 'autodev_session_id';
  let sessionId = localStorage.getItem(key);
  if (!sessionId) {
    sessionId = 'ad_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
    localStorage.setItem(key, sessionId);
  }

  function send(event, data) {
    const body = JSON.stringify({ event: event, data: data || {} });
    const headers = { 'Content-Type': 'application/json', 'X-AutoDev-Session': sessionId };
    if (navigator.sendBeacon) {
      try {
        const blob = new Blob([body], { type: 'application/json' });
        navigator.sendBeacon('/api/autodev/track.php', blob);
        return;
      } catch (error) {}
    }
    fetch('/api/autodev/track.php', { method: 'POST', headers: headers, body: body, keepalive: true }).catch(function () {});
  }

  window.AutoDev = {
    track: send,
    sessionId: sessionId
  };

  send('page_view', { path: window.location.pathname, title: document.title });
})();
