(function(){
'use strict';
function esc(v){return String(v||'').replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[c]})}
function initials(name){return String(name||'Cliente').trim().split(/\s+/).slice(0,2).map(function(p){return p.charAt(0).toUpperCase()}).join('')||'C'}
function stars(n){n=Math.max(1,Math.min(5,Number(n)||5));return '★'.repeat(n)+'☆'.repeat(5-n)}
function installTestimonials(){
 var section=document.querySelector('.home-testimonials'); if(!section)return;
 var grid=section.querySelector('.testimonials-grid'); if(!grid)return;
 var intro=section.querySelector('.section-heading p'); if(intro)intro.textContent='Avaliações enviadas por clientes e publicadas somente após moderação da equipe.';
 grid.innerHTML='<div class="sv-testimonial-empty">Carregando avaliações reais...</div>';
 fetch('/api/testimonials.php',{headers:{Accept:'application/json'}}).then(function(r){return r.json()}).then(function(data){
   var items=data&&Array.isArray(data.items)?data.items:[];
   if(!items.length){grid.innerHTML='<div class="sv-testimonial-empty"><strong>Ainda não há avaliações publicadas.</strong><br>Se você já comprou na ShopVivaliz, compartilhe sua experiência para análise da equipe.</div>';}
   else{grid.innerHTML=items.slice(0,6).map(function(item){var city=String(item.city||'').trim();return '<article class="testimonial-card"><div class="testimonial-stars" aria-label="'+esc(item.rating)+' de 5 estrelas">'+esc(stars(item.rating))+'</div><p>“'+esc(item.message)+'”</p><div class="testimonial-author"><span class="testimonial-avatar sv-initials" aria-hidden="true">'+esc(initials(item.name))+'</span><div><strong>'+esc(item.name)+'</strong>'+(city?'<span>'+esc(city)+'</span>':'')+'<small class="sv-moderated-label">✓ Avaliação moderada</small></div></div></article>'}).join('');}
   var actions=document.createElement('div');actions.className='sv-testimonial-actions';actions.innerHTML='<a class="sv-testimonial-primary" href="/avaliacoes.php">Enviar minha avaliação</a><a class="sv-testimonial-secondary" href="/avaliacoes.php#como-funciona">Como funciona a moderação</a>';grid.insertAdjacentElement('afterend',actions);
 }).catch(function(){grid.innerHTML='<div class="sv-testimonial-empty">Não foi possível carregar as avaliações agora. Tente novamente mais tarde.</div>'});
}
function installSupport(){
 if(document.querySelector('.sv-support-dock'))return;
 var cfg=window.ShopVivalizPublicConfig||{};
 var dock=document.createElement('div');dock.className='sv-support-dock';dock.setAttribute('aria-label','Canais de atendimento');
 var wa=cfg.whatsappUrl||'/contato';
 dock.innerHTML='<button class="sv-support-button sv-support-liz" type="button" aria-controls="sv-liz-panel" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v12H7l-3 3V4z"/><path d="M8 9h8M8 12h5"/></svg><span class="sv-support-label">Falar com a Liz</span></button><a class="sv-support-button sv-support-whatsapp" href="'+esc(wa)+'" target="_blank" rel="noopener noreferrer" aria-label="Falar com a ShopVivaliz pelo WhatsApp"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M20.52 3.48A11.87 11.87 0 0 0 12.05 0C5.5 0 .16 5.33.16 11.89c0 2.1.55 4.14 1.6 5.93L.06 24l6.32-1.66a11.86 11.86 0 0 0 5.67 1.45h.01c6.55 0 11.89-5.33 11.89-11.89 0-3.18-1.22-6.17-3.43-8.42Zm-8.47 18.3a9.85 9.85 0 0 1-5.02-1.38l-.36-.21-3.75.98 1-3.65-.23-.37a9.86 9.86 0 1 1 8.36 4.63Zm5.4-7.38c-.3-.15-1.75-.86-2.02-.96-.27-.1-.47-.15-.67.15-.2.3-.77.96-.94 1.16-.17.2-.35.22-.64.07-.3-.15-1.25-.46-2.38-1.47-.88-.78-1.47-1.75-1.64-2.05-.17-.3-.02-.46.13-.61.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.02-.52-.08-.15-.67-1.61-.92-2.21-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.08-.79.37-.27.3-1.04 1.02-1.04 2.48 0 1.46 1.07 2.88 1.22 3.08.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.69.63.71.23 1.36.2 1.87.12.57-.08 1.75-.72 2-1.41.25-.69.25-1.28.17-1.41-.07-.12-.27-.2-.57-.35Z"/></svg><span class="sv-support-label">WhatsApp</span></a>';
 document.body.appendChild(dock);
 var panel=document.createElement('section');panel.className='sv-liz-panel';panel.id='sv-liz-panel';panel.setAttribute('aria-label','Chat com a Liz');panel.innerHTML='<header class="sv-liz-header"><div><strong>Liz · Assistente virtual</strong><span>Ajuda com produtos, pedidos e dúvidas</span></div><button class="sv-liz-close" type="button" aria-label="Fechar chat">×</button></header><div class="sv-liz-messages" aria-live="polite"><div class="sv-liz-message assistant">Olá! Eu sou a Liz. Como posso ajudar?</div></div><form class="sv-liz-form"><input name="message" maxlength="1000" autocomplete="off" placeholder="Digite sua mensagem" aria-label="Mensagem para a Liz" required><button type="submit">Enviar</button></form>';
 document.body.appendChild(panel);
 var openBtn=dock.querySelector('.sv-support-liz'),closeBtn=panel.querySelector('.sv-liz-close'),form=panel.querySelector('form'),input=form.querySelector('input'),messages=panel.querySelector('.sv-liz-messages'),history=[];
 function toggle(open){panel.classList.toggle('is-open',open);openBtn.setAttribute('aria-expanded',open?'true':'false');if(open)setTimeout(function(){input.focus()},50)}
 openBtn.addEventListener('click',function(){toggle(!panel.classList.contains('is-open'))});closeBtn.addEventListener('click',function(){toggle(false)});document.addEventListener('keydown',function(e){if(e.key==='Escape')toggle(false)});
 function add(text,who,extra){var el=document.createElement('div');el.className='sv-liz-message '+who+(extra?' '+extra:'');el.textContent=text;messages.appendChild(el);messages.scrollTop=messages.scrollHeight;return el}
 form.addEventListener('submit',function(e){e.preventDefault();var text=input.value.trim();if(!text)return;add(text,'user');history.push({role:'user',content:text});input.value='';var wait=add('Digitando...','assistant');form.querySelector('button').disabled=true;fetch('/api/liz-intelligent.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({message:text,history:history.slice(-10)})}).then(function(r){return r.json()}).then(function(d){wait.remove();var answer=d.answer||d.error||'Não consegui responder agora.';add(answer,d.ok===false?'assistant error':'assistant');if(d.ok!==false)history.push({role:'assistant',content:answer})}).catch(function(){wait.remove();add('A Liz está temporariamente indisponível. Use o WhatsApp para falar com a equipe.','assistant','error')}).finally(function(){form.querySelector('button').disabled=false;input.focus()})});
}
function init(){installTestimonials();installSupport()}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
