<?php
declare(strict_types=1);

function gam_root(): string
{
    return __DIR__;
}

function gam_read_json(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function gam_first_hero_image(): string
{
    $catalog = gam_read_json(gam_root() . '/api/catalog/fallback-products.json');
    foreach ($catalog as $row) {
        if (!is_array($row)) {
            continue;
        }
        $url = trim((string)($row['image_url'] ?? ''));
        if ($url !== '') {
            return $url;
        }
    }
    return 'https://images.unsplash.com/photo-1512436991641-6745cdb1723f?auto=format&fit=crop&w=1600&q=80';
}

$heroImage = gam_first_hero_image();
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gamificacao ShopVivaliz</title>
  <meta name="robots" content="noindex,nofollow">
  <style>
    :root {
      color-scheme: light;
      --bg: #f5f7fb;
      --panel: #ffffff;
      --line: #d8e1ec;
      --text: #122033;
      --muted: #5b6b7f;
      --accent: #0f7b6c;
      --accent-2: #1e4bd1;
      --good: #1b8f5a;
      --shadow: 0 16px 40px rgba(17, 33, 58, .08);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      background: var(--bg);
      color: var(--text);
    }
    .hero {
      min-height: 42vh;
      display: grid;
      align-items: end;
      background:
        linear-gradient(180deg, rgba(8, 19, 35, .10), rgba(8, 19, 35, .72)),
        url('<?php echo htmlspecialchars($heroImage, ENT_QUOTES, 'UTF-8'); ?>') center/cover no-repeat;
      padding: 32px clamp(20px, 4vw, 48px);
      color: #fff;
    }
    .hero-inner {
      max-width: 1180px;
      width: 100%;
      margin: 0 auto;
      display: grid;
      gap: 14px;
    }
    .eyebrow {
      text-transform: uppercase;
      letter-spacing: 0;
      font-size: 12px;
      font-weight: 700;
      opacity: .9;
    }
    h1 {
      margin: 0;
      font-size: clamp(32px, 4vw, 52px);
      line-height: 1.05;
      max-width: 12ch;
    }
    .sub {
      margin: 0;
      max-width: 58ch;
      font-size: 16px;
      line-height: 1.5;
      color: rgba(255,255,255,.88);
    }
    .shell {
      max-width: 1180px;
      margin: -24px auto 0;
      padding: 0 clamp(20px, 4vw, 48px) 40px;
      display: grid;
      gap: 18px;
    }
    .panel {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 8px;
      box-shadow: var(--shadow);
      padding: 18px;
    }
    .metrics {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
    }
    .metric {
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 14px;
      min-height: 92px;
    }
    .metric span {
      display: block;
      color: var(--muted);
      font-size: 12px;
      margin-bottom: 10px;
    }
    .metric strong {
      font-size: 28px;
      line-height: 1;
    }
    .grid {
      display: grid;
      grid-template-columns: 1.1fr .9fr;
      gap: 18px;
    }
    .section-title {
      margin: 0 0 14px;
      font-size: 18px;
    }
    .badge-list, .leaderboard {
      display: grid;
      gap: 10px;
    }
    .badge {
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 12px 14px;
      background: #fbfcfe;
    }
    .badge-head, .leader-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .badge-title, .name {
      font-weight: 700;
    }
    .badge-desc, .meta, .small {
      color: var(--muted);
      font-size: 13px;
      line-height: 1.45;
    }
    .pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 76px;
      padding: 6px 10px;
      border-radius: 999px;
      background: rgba(15, 123, 108, .10);
      color: var(--accent);
      font-size: 12px;
      font-weight: 700;
    }
    .pill.dim {
      background: rgba(30, 75, 209, .08);
      color: var(--accent-2);
    }
    .bar {
      height: 8px;
      border-radius: 999px;
      background: #e7edf4;
      overflow: hidden;
      margin-top: 10px;
    }
    .bar > i {
      display: block;
      height: 100%;
      width: 0;
      background: linear-gradient(90deg, var(--accent), var(--accent-2));
    }
    .loading, .error {
      padding: 14px;
      border-radius: 8px;
      border: 1px solid var(--line);
      background: #fff;
      color: var(--muted);
    }
    .error { border-color: #efc6c6; color: #8d2c2c; background: #fff7f7; }
    @media (max-width: 980px) {
      .metrics, .grid { grid-template-columns: 1fr; }
      h1 { max-width: 16ch; }
    }
  </style>
</head>
<body>
  <header class="hero">
    <div class="hero-inner">
      <div class="eyebrow">ShopVivaliz</div>
      <h1>Gamificacao</h1>
      <p class="sub">Badges, progresso mensal e ranking leve para dar ritmo as compras e ao feedback sem mexer em precos ou campanhas.</p>
    </div>
  </header>

  <main class="shell">
    <section class="metrics" id="metrics">
      <div class="metric"><span>Pedidos totais</span><strong>...</strong></div>
      <div class="metric"><span>Pedidos no mes</span><strong>...</strong></div>
      <div class="metric"><span>Feedbacks</span><strong>...</strong></div>
      <div class="metric"><span>Clientes ativos</span><strong>...</strong></div>
    </section>

    <section class="grid">
      <article class="panel">
        <h2 class="section-title">Badges</h2>
        <div id="badges" class="badge-list"><div class="loading">Carregando badges...</div></div>
      </article>
      <article class="panel">
        <h2 class="section-title">Leaderboard do mes</h2>
        <div id="leaderboard" class="leaderboard"><div class="loading">Carregando ranking...</div></div>
      </article>
    </section>
  </main>

  <script>
    const metricsEl = document.getElementById('metrics');
    const badgesEl = document.getElementById('badges');
    const leaderboardEl = document.getElementById('leaderboard');

    function fmtMoney(value) {
      return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value || 0);
    }

    function metricValue(index, value) {
      const node = metricsEl.children[index];
      if (node && node.querySelector('strong')) {
        node.querySelector('strong').textContent = value;
      }
    }

    function escHtml(str) {
      const div = document.createElement('div');
      div.textContent = String(str || '');
      return div.innerHTML;
    }

    function badgeHtml(badge) {
      const state = badge.earned ? 'Conquistado' : `${Math.max(0, Math.min(100, badge.progress || 0))}%`;
      return `
        <div class="badge">
          <div class="badge-head">
            <div>
              <div class="badge-title">${escHtml(badge.title)}</div>
              <div class="badge-desc">${escHtml(badge.description || '')}</div>
            </div>
            <span class="pill ${badge.earned ? '' : 'dim'}">${state}</span>
          </div>
          <div class="bar"><i style="width:${Math.max(0, Math.min(100, badge.progress || 0))}%"></i></div>
        </div>
      `;
    }

    function leaderHtml(item, index) {
      return `
        <div class="badge">
          <div class="leader-row">
            <div>
              <div class="name">#${index + 1} ${escHtml(item.display_name || 'Cliente')}</div>
              <div class="meta">${item.orders_count || 0} pedidos no mes</div>
            </div>
            <span class="pill dim">${fmtMoney(item.total_spent || 0)}</span>
          </div>
        </div>
      `;
    }

    async function loadGamification() {
      try {
        const res = await fetch('/api/gamification/status.php', { headers: { 'Accept': 'application/json' } });
        const data = await res.json();
        if (!res.ok || !data.ok) throw new Error('Falha ao carregar gamificacao.');

        metricValue(0, data.summary.orders_count ?? 0);
        metricValue(1, data.summary.monthly_orders_count ?? 0);
        metricValue(2, data.summary.feedback_count ?? 0);
        metricValue(3, data.summary.active_customers_count ?? 0);

        badgesEl.innerHTML = (data.badges || []).map(badgeHtml).join('') || '<div class="loading">Sem badges no momento.</div>';
        leaderboardEl.innerHTML = (data.leaderboard || []).map(leaderHtml).join('') || '<div class="loading">Ranking ainda vazio.</div>';
      } catch (err) {
        const message = err && err.message ? err.message : 'Erro inesperado.';
        badgesEl.innerHTML = '<div class="error">' + message + '</div>';
        leaderboardEl.innerHTML = '<div class="error">' + message + '</div>';
      }
    }

    loadGamification();
  </script>
</body>
</html>
