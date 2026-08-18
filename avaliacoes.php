<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Avaliações de clientes | ShopVivaliz</title><meta name="description" content="Envie sua avaliação sobre a experiência de compra na ShopVivaliz."><link rel="stylesheet" href="/css/style.css"><link rel="stylesheet" href="/css/footer-pages.css?v=20260728-3"><style>.review-form{max-width:720px;margin:0 auto}.review-form label{display:block;font-weight:800;color:#173b63;margin:14px 0 6px}.review-form input,.review-form select,.review-form textarea{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:10px;padding:12px;font:inherit}.review-form textarea{min-height:150px;resize:vertical}.review-form button{margin-top:16px;border:0;border-radius:10px;padding:12px 18px;background:#0b4f88;color:#fff;font-weight:800}.review-note{font-size:13px;color:#64748b}.hp{position:absolute;left:-9999px}.review-how{margin-top:22px;padding:18px;border-radius:14px;background:#f4f8fc;border:1px solid #dbe5ef}.review-how h2{margin-top:0;color:#0b4f88}.review-how ol{padding-left:20px;line-height:1.7}</style><?php require_once __DIR__.'/includes/head-analytics.php'; ?></head><body>
<?php $svNavCurrent='avaliacoes'; include __DIR__.'/includes/navbar.php'; ?>
<main class="legal-container"><header class="legal-header"><h1>Conte sua experiência</h1><p>A Liz analisa automaticamente sua avaliação antes da publicação. Casos sensíveis ou duvidosos seguem para revisão da equipe.</p></header><section class="legal-body review-form" id="review-list-section" style="max-width:720px;margin:0 auto 32px"><h2 style="color:#0b4f88;margin:0 0 12px">Avaliações publicadas</h2><div id="review-list" aria-live="polite" style="display:grid;gap:14px"><p style="color:#64748b">Carregando avaliações...</p></div></section><section class="legal-body review-form"><div id="review-message" aria-live="polite"></div><form id="review-form"><label>Seu nome<input name="name" maxlength="80" autocomplete="name" required></label><label>Cidade e estado<input name="city" maxlength="100" placeholder="Ex.: Divinópolis - MG"></label><label>Nota<select name="rating" required><option value="5">5 — Excelente</option><option value="4">4 — Muito bom</option><option value="3">3 — Bom</option><option value="2">2 — Regular</option><option value="1">1 — Ruim</option></select></label><label>Número do pedido (opcional)<input name="order_reference" maxlength="80" inputmode="text"></label><label>Comentário<textarea name="message" minlength="20" maxlength="1000" required></textarea></label><label class="hp">Site<input name="website" tabindex="-1" autocomplete="off"></label><p class="review-note">Não publique telefone, endereço, CPF, dados bancários ou outras informações sensíveis. Avaliações legítimas podem ser positivas, neutras ou negativas; conteúdo inadequado, ofensivo, repetido ou sem relação com uma experiência real poderá ser retido.</p><button type="submit">Enviar para análise da Liz</button></form><div class="review-how" id="como-funciona"><h2>Como funciona a moderação</h2><ol><li>Você envia a avaliação.</li><li>A Liz faz uma análise automática de segurança e relevância.</li><li>Avaliações apropriadas são liberadas; casos sensíveis ou incertos ficam pendentes para revisão da equipe.</li><li>Somente avaliações aprovadas são exibidas publicamente.</li></ol></div></section></main>
<?php include __DIR__.'/includes/footer.php'; ?><script>(function(){
// Ate 2026-08-18 esta pagina so tinha o formulario de envio -- nenhuma
// avaliacao aprovada era listada aqui, apesar do endpoint GET
// /api/testimonials.php ja devolver `items` (a home ja usa esse mesmo
// endpoint via js/public-experience-v1.js). Reaproveita o mesmo fetch
// aqui. Ver relatorio da Rodada 1 de melhoria continua.
var listEl = document.getElementById('review-list');
if (listEl) {
    fetch('/api/testimonials.php', {headers: {Accept: 'application/json'}})
        .then(function(r){ return r.json(); })
        .then(function(data){
            var items = (data && data.ok && Array.isArray(data.items)) ? data.items : [];
            if (!items.length) {
                listEl.innerHTML = '<p style="color:#64748b">Ainda não há avaliações publicadas. Seja o primeiro a avaliar!</p>';
                return;
            }
            var esc = function(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
            listEl.innerHTML = items.map(function(it){
                var stars = '★★★★★☆☆☆☆☆'.slice(5 - (parseInt(it.rating, 10) || 0), 10 - (parseInt(it.rating, 10) || 0));
                var city = it.city ? ' - ' + esc(it.city) : '';
                return '<div style="padding:14px 16px;border:1px solid #e2e8f0;border-radius:12px;background:#fff">' +
                    '<div style="color:#f59e0b;letter-spacing:2px" aria-hidden="true">' + stars + '</div>' +
                    '<p style="margin:8px 0;color:#334155">' + esc(it.message) + '</p>' +
                    '<p style="margin:0;font-weight:700;color:#173b63;font-size:14px">' + esc(it.name) + city + '</p>' +
                    '</div>';
            }).join('');
        })
        .catch(function(){ listEl.innerHTML = '<p style="color:#64748b">Não foi possível carregar as avaliações agora.</p>'; });
}
var f=document.getElementById('review-form'),m=document.getElementById('review-message');if(!f)return;f.addEventListener('submit',async function(e){e.preventDefault();var b=f.querySelector('button');b.disabled=true;m.textContent='Enviando...';try{var r=await fetch('/api/testimonials.php',{method:'POST',body:new FormData(f),headers:{Accept:'application/json'}});var d=await r.json();m.textContent=d.message||d.error||'Resposta recebida.';if(d.ok)f.reset()}catch(err){m.textContent='Não foi possível enviar agora.'}finally{b.disabled=false}})})();</script></body></html>
