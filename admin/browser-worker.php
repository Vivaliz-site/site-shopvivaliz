<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Cache-Control: no-store, private');
header('Pragma: no-cache');

const BW_BASE = 'http://127.0.0.1:17777';
const BW_SCOPE = 'admin-browser-worker';

function bw_id(string $value): string
{
    $value = strtolower(trim($value));
    if (!preg_match('/^[0-9a-f-]{36}$/', $value)) {
        throw new RuntimeException('Sessão inválida.');
    }
    return $value;
}

function bw_request(string $method, string $path, ?array $payload = null): array
{
    if (!preg_match('#^/(health|sessions(?:/[0-9a-f-]{36}/(?:screenshot|renew|action|close))?)$#', $path)) {
        throw new RuntimeException('Rota não permitida.');
    }

    $ch = curl_init(BW_BASE . $path);
    if ($ch === false) {
        throw new RuntimeException('Falha ao inicializar conexão.');
    }

    $headers = ['Accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '') {
        return ['status' => 502, 'type' => 'application/json', 'body' => json_encode(['ok' => false, 'error' => 'browser_worker_unavailable'])];
    }
    return ['status' => $status ?: 502, 'type' => $type ?: 'application/octet-stream', 'body' => $body];
}

function bw_json_response(array $response): never
{
    http_response_code((int)$response['status']);
    header('Content-Type: application/json; charset=UTF-8');
    echo (string)$response['body'];
    exit;
}

$api = (string)($_GET['api'] ?? '');
if ($api !== '') {
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $api === 'health') {
            bw_json_response(bw_request('GET', '/health'));
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $api === 'sessions') {
            bw_json_response(bw_request('GET', '/sessions'));
        }
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $api === 'screenshot') {
            $id = bw_id((string)($_GET['id'] ?? ''));
            $response = bw_request('GET', '/sessions/' . $id . '/screenshot');
            http_response_code((int)$response['status']);
            header('Content-Type: ' . ((int)$response['status'] === 200 ? 'image/png' : 'application/json; charset=UTF-8'));
            echo (string)$response['body'];
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            header('Allow: GET, POST');
            exit;
        }

        $csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!sv_csrf_valid(BW_SCOPE, $csrf)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => 'csrf_invalid']);
            exit;
        }

        $raw = file_get_contents('php://input');
        $input = json_decode($raw !== false ? $raw : '', true);
        if (!is_array($input)) {
            $input = [];
        }

        if ($api === 'create') {
            $url = trim((string)($input['url'] ?? 'about:blank'));
            if ($url !== 'about:blank' && !filter_var($url, FILTER_VALIDATE_URL)) {
                throw new RuntimeException('URL inválida.');
            }
            $payload = [
                'url' => $url,
                'label' => substr((string)($input['label'] ?? 'mfa'), 0, 80),
                'origin' => 'shopvivaliz-admin',
                'persistent' => !empty($input['persistent']),
                'profile' => substr((string)($input['profile'] ?? 'manual'), 0, 80),
                'ttl_seconds' => 7200,
            ];
            bw_json_response(bw_request('POST', '/sessions', $payload));
        }

        $id = bw_id((string)($input['id'] ?? ''));
        if ($api === 'close') {
            bw_json_response(bw_request('POST', '/sessions/' . $id . '/close', []));
        }
        if ($api === 'renew') {
            bw_json_response(bw_request('POST', '/sessions/' . $id . '/renew', ['ttl_seconds' => 7200]));
        }
        if ($api === 'action') {
            $action = (string)($input['action'] ?? '');
            $allowed = ['goto', 'click', 'type', 'key', 'scroll', 'new_page'];
            if (!in_array($action, $allowed, true)) {
                throw new RuntimeException('Ação não permitida.');
            }
            $payload = ['action' => $action, 'ttl_seconds' => 7200];
            foreach (['url', 'text', 'key', 'x', 'y', 'dy'] as $field) {
                if (array_key_exists($field, $input)) {
                    $payload[$field] = $input[$field];
                }
            }
            bw_json_response(bw_request('POST', '/sessions/' . $id . '/action', $payload));
        }

        http_response_code(404);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'api_not_found']);
        exit;
    } catch (Throwable $e) {
        error_log('[BrowserWorkerAdmin] ' . $e->getMessage());
        http_response_code(400);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'error' => 'request_invalid']);
        exit;
    }
}

$csrf = sv_csrf_token(BW_SCOPE);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Navegador da VM | ShopVivaliz Admin</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#0f172a;color:#e2e8f0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
.wrap{max-width:1500px;margin:auto;padding:18px}.top{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}
.card{background:#172033;border:1px solid #334155;border-radius:14px;padding:16px;margin-top:14px}.grid{display:grid;grid-template-columns:360px 1fr;gap:14px}
@media(max-width:900px){.grid{grid-template-columns:1fr}}input,select,button{font:inherit;border-radius:8px;border:1px solid #475569;padding:10px;background:#0f172a;color:#e2e8f0}
input,select{width:100%;margin:5px 0 10px}button{cursor:pointer;background:#2563eb;border-color:#2563eb}button.secondary{background:#334155;border-color:#475569}button.danger{background:#991b1b;border-color:#b91c1c}
.row{display:flex;gap:8px;flex-wrap:wrap}.row>*{flex:1}.status{font-size:13px;color:#94a3b8}.ok{color:#4ade80}.bad{color:#f87171}
.screen{background:#020617;border:1px solid #334155;border-radius:10px;overflow:auto;min-height:300px;text-align:center}
#shot{display:block;max-width:100%;height:auto;margin:auto;cursor:crosshair;touch-action:manipulation}.meta{font-size:13px;word-break:break-all;color:#94a3b8}
.keybar button{min-width:64px}.hint{font-size:12px;color:#94a3b8;line-height:1.45}.back{color:#93c5fd;text-decoration:none}.badge{display:inline-block;border-radius:999px;padding:4px 9px;background:#334155;font-size:12px}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div>
      <a class="back" href="/admin/">← Admin</a>
      <h1 style="margin:.4rem 0">Navegador da VM</h1>
      <div class="status" id="health">Verificando worker…</div>
    </div>
    <span class="badge">backend 10.0.1.38 · acesso via túnel privado</span>
  </div>

  <div class="grid">
    <section class="card">
      <h2 style="margin-top:0">Sessão</h2>
      <label>Ativa</label>
      <select id="sessions"><option value="">Nenhuma sessão</option></select>

      <label>Endereço inicial</label>
      <input id="start-url" value="https://www.google.com/" autocomplete="off">
      <label>Identificação</label>
      <input id="label" value="mfa-manual" maxlength="80">
      <label>Perfil persistente</label>
      <input id="profile" value="manual" maxlength="80">
      <label style="display:flex;gap:8px;align-items:center;margin:4px 0 12px"><input id="persistent" type="checkbox" style="width:auto;margin:0" checked> Manter cookies/login neste perfil</label>
      <button id="create">Criar sessão</button>

      <hr style="border:0;border-top:1px solid #334155;margin:18px 0">
      <label>Navegar para</label>
      <input id="goto-url" placeholder="https://…">
      <div class="row"><button id="goto">Abrir</button><button class="secondary" id="new-page">Nova aba</button></div>

      <label style="margin-top:12px;display:block">Digitar no campo ativo</label>
      <input id="type-text" autocomplete="off" placeholder="Texto">
      <button id="type">Digitar</button>

      <div class="row keybar" style="margin-top:10px">
        <button class="secondary key" data-key="Tab">Tab</button>
        <button class="secondary key" data-key="Enter">Enter</button>
        <button class="secondary key" data-key="Escape">Esc</button>
        <button class="secondary key" data-key="Backspace">⌫</button>
      </div>
      <div class="row" style="margin-top:10px">
        <button class="secondary scroll" data-dy="-700">↑ Rolar</button>
        <button class="secondary scroll" data-dy="700">↓ Rolar</button>
      </div>
      <div class="row" style="margin-top:14px">
        <button class="secondary" id="renew">Renovar 2h</button>
        <button class="danger" id="close">Encerrar</button>
      </div>
      <p class="hint">Sessões expiram em 2 horas sem renovação. Ao clicar na imagem, o clique é enviado ao Chromium real da VM. Nenhuma porta de navegador/VNC é publicada diretamente na internet.</p>
    </section>

    <section class="card">
      <div class="top">
        <h2 style="margin:0">Tela do Chromium</h2>
        <span class="status" id="selected-meta">Selecione ou crie uma sessão.</span>
      </div>
      <div class="screen" style="margin-top:12px">
        <img id="shot" alt="Captura do navegador" hidden>
      </div>
      <p class="meta" id="page-meta"></p>
    </section>
  </div>
</div>
<script>
const CSRF = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const $ = (id) => document.getElementById(id);
let current = '';
let sessions = [];
let refreshing = false;

async function api(name, body) {
  const opt = body === undefined ? {} : {
    method:'POST',
    headers:{'content-type':'application/json','x-csrf-token':CSRF},
    body:JSON.stringify(body)
  };
  const res = await fetch('/admin/browser-worker.php?api=' + encodeURIComponent(name), opt);
  const data = await res.json().catch(() => ({ok:false,error:'invalid_response'}));
  if (!res.ok || data.ok === false) throw new Error(data.error || ('HTTP ' + res.status));
  return data;
}

async function health() {
  try {
    const d = await api('health');
    $('health').className='status ok';
    $('health').textContent = 'Online · ' + (d.chromium || 'Chromium') + ' · sessões: ' + d.active_sessions;
  } catch (e) {
    $('health').className='status bad';
    $('health').textContent='Worker indisponível';
  }
}

function renderSessions() {
  const select=$('sessions');
  const keep=current;
  select.innerHTML='<option value="">Nenhuma sessão</option>';
  for (const s of sessions) {
    const o=document.createElement('option');
    o.value=s.id;
    o.textContent=s.label + ' · ' + (s.title || s.url || s.id.slice(0,8));
    select.appendChild(o);
  }
  if (sessions.some(s=>s.id===keep)) select.value=keep;
  else if (sessions.length && !current) { current=sessions[0].id; select.value=current; }
  updateMeta();
}

function updateMeta() {
  const s=sessions.find(x=>x.id===current);
  if (!s) {
    $('selected-meta').textContent='Selecione ou crie uma sessão.';
    $('page-meta').textContent='';
    $('shot').hidden=true;
    return;
  }
  $('selected-meta').textContent=s.label + (s.persistent ? ' · persistente' : ' · temporária');
  $('page-meta').textContent=(s.title || '') + (s.url ? ' — ' + s.url : '') + ' · expira ' + new Date(s.expires_at).toLocaleString();
}

async function refreshSessions() {
  try {
    const d=await api('sessions');
    sessions=d.sessions || [];
    renderSessions();
  } catch {}
}

async function refreshShot() {
  if (!current || refreshing) return;
  refreshing=true;
  const img=$('shot');
  const next='/admin/browser-worker.php?api=screenshot&id='+encodeURIComponent(current)+'&t='+Date.now();
  const probe=new Image();
  probe.onload=()=>{ img.src=next; img.hidden=false; refreshing=false; };
  probe.onerror=()=>{ refreshing=false; };
  probe.src=next;
}

async function action(payload) {
  if (!current) throw new Error('Selecione uma sessão.');
  const d=await api('action', {id:current, ...payload});
  await refreshSessions();
  await refreshShot();
  return d;
}

$('sessions').addEventListener('change',()=>{current=$('sessions').value;updateMeta();refreshShot();});
$('create').addEventListener('click',async()=>{
  try {
    const d=await api('create',{
      url:$('start-url').value,label:$('label').value,profile:$('profile').value,persistent:$('persistent').checked
    });
    current=d.session.id;
    await refreshSessions(); await refreshShot();
  } catch(e){alert('Falha ao criar sessão: '+e.message);}
});
$('goto').addEventListener('click',()=>action({action:'goto',url:$('goto-url').value}).catch(e=>alert(e.message)));
$('new-page').addEventListener('click',()=>action({action:'new_page'}).catch(e=>alert(e.message)));
$('type').addEventListener('click',()=>action({action:'type',text:$('type-text').value}).catch(e=>alert(e.message)));
document.querySelectorAll('.key').forEach(b=>b.addEventListener('click',()=>action({action:'key',key:b.dataset.key}).catch(e=>alert(e.message))));
document.querySelectorAll('.scroll').forEach(b=>b.addEventListener('click',()=>action({action:'scroll',dy:Number(b.dataset.dy)}).catch(e=>alert(e.message))));
$('renew').addEventListener('click',()=>current && api('renew',{id:current}).then(refreshSessions).catch(e=>alert(e.message)));
$('close').addEventListener('click',async()=>{
  if(!current || !confirm('Encerrar esta sessão do navegador?')) return;
  try{await api('close',{id:current});current='';await refreshSessions();updateMeta();}catch(e){alert(e.message);}
});
$('shot').addEventListener('click',async(ev)=>{
  if(!current) return;
  const rect=ev.currentTarget.getBoundingClientRect();
  const x=(ev.clientX-rect.left)*(ev.currentTarget.naturalWidth/rect.width);
  const y=(ev.clientY-rect.top)*(ev.currentTarget.naturalHeight/rect.height);
  try{await action({action:'click',x,y});}catch(e){alert(e.message);}
});

health();refreshSessions();
setInterval(health,15000);
setInterval(refreshSessions,5000);
setInterval(refreshShot,1800);
</script>
</body>
</html>
