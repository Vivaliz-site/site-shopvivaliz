(() => {
  'use strict';
  if (location.pathname !== '/admin/catalog-optimization/admin_catalog.php') return;
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const FIELD_MAP = [
    {name:'optimized_title', key:'title', label:'Título'},
    {name:'optimized_description', key:'description', label:'Descrição'},
    {name:'bullet_points', key:'bullet_points', label:'Bullet points'},
    {name:'seo_keywords', key:'seo_keywords', label:'Termos de busca'},
    {name:'marketing_hooks', key:'marketing_hooks', label:'Hooks comerciais'},
    {name:'meta_title', key:'meta_title', label:'Meta title'},
    {name:'meta_description', key:'meta_description', label:'Meta description'}
  ];
  const PROMO = /(?:r\$|pre[cç]o|desconto|cupom|frete\s+gr[aá]tis|parcel(?:a|as|ado|amento)|imperd[ií]vel|corra|garanta\s+j[aá]|n[aã]o\s+perca|oferta|promo[cç][aã]o)/iu;
  const ORIGINAL_PLACEHOLDER = /^(?:—|nao existe valor externo separado|não existe valor externo separado|sem valor externo separado|campo interno|valor não disponível|valor nao disponivel)/iu;
  const CHANNELS = {
    ml:{label:'Mercado Livre',titleMax:60,titleMin:20,descMin:140,bullets:[3,5],focus:'Identidade do produto, marca/modelo e atributos reais. Sem preço, frete, promoção ou emoji.',priority:['Título','Descrição','Bullet points']},
    shopee:{label:'Shopee',titleMax:120,titleMin:35,descMin:220,bullets:[3,5],focus:'Leitura mobile, descoberta por busca e pontos de decisão escaneáveis. Sem urgência artificial.',priority:['Título','Descrição','Bullet points']},
    amazon:{label:'Amazon',titleMax:200,titleMin:45,descMin:220,bullets:[5,5],focus:'Título limpo, cinco bullets factuais e termos de busca complementares, sem repetição.',priority:['Título','Bullet points','Termos de busca']},
    tiktok:{label:'TikTok Shop',titleMax:300,titleMin:40,titleIdeal:150,descMin:300,bullets:[3,5],focus:'Identificação imediata e selling points factuais para mobile. Sem medo, escassez ou claims subjetivos.',priority:['Título','Descrição','Hooks comerciais']},
    site:{label:'ShopVivaliz',titleMax:70,titleMin:30,descMin:320,bullets:[3,5],metaTitleMax:60,metaDescriptionMax:160,focus:'SEO/GEO factual, resposta direta, semântica long-tail e metadados únicos.',priority:['Título','Descrição','Meta title','Meta description']},
    erp:{label:'Olist / Tiny',titleMax:120,titleMin:15,descMin:40,bullets:[0,8],focus:'Cadastro técnico padronizado, sem linguagem promocional. SEO e hooks devem permanecer vazios.',priority:['Título','Descrição']}
  };

  const style = document.createElement('style');
  style.textContent = `
    .ci-summary{margin:0 0 12px;padding:12px 13px;border:1px solid #cfe0ef;border-radius:11px;background:#f7fbff}.ci-summary-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}.ci-summary h3{margin:0;font-size:16px}.ci-summary p{margin:5px 0;color:#475569}.ci-chips,.ci-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap;margin-top:8px}.ci-chip,.ci-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:11px;font-weight:850;background:#eef2f7;color:#475569}.ci-chip.good,.ci-badge.changed{background:#dbeafe;color:#075985}.ci-chip.warn,.ci-badge.new{background:#fef3c7;color:#854d0e}.ci-chip.bad,.ci-badge.removed{background:#fee2e2;color:#991b1b}.ci-badge.same{background:#f1f5f9;color:#64748b}.ci-toggle,.ci-restore{border:0;border-radius:8px;padding:7px 9px;font-weight:800;cursor:pointer}.ci-toggle{background:#eef2f7;color:#334155}.ci-restore{background:#fff;color:#075985;border:1px solid #bfdbfe;margin-left:5px}.ci-field{border:1px solid transparent;border-radius:10px;padding:9px!important;margin:7px 0!important}.ci-field.changed{border-color:#93c5fd;background:#f8fbff}.ci-field.new{border-color:#f4dda0;background:#fffdf5}.ci-field.removed{border-color:#fecaca;background:#fff8f8}.ci-field.same{opacity:.82}.ci-label-line{display:flex;align-items:center;gap:6px;justify-content:space-between;flex-wrap:wrap}.ci-label-title{display:flex;align-items:center;gap:5px;flex-wrap:wrap}.ci-hint{font-size:12px;margin-top:5px;padding:7px 8px;border-radius:8px;background:#f8fafc;color:#475569;border:1px solid #e2e8f0}.ci-hint.good{background:#ecfdf3;color:#166534;border-color:#bbf7d0}.ci-hint.warn{background:#fff8e1;color:#6b5300;border-color:#f4dda0}.ci-hint.bad{background:#fff1f1;color:#991b1b;border-color:#fecaca}.ci-hidden{display:none!important}.ci-only-changed .ci-field.same{display:none!important}.ci-original-map{margin-top:8px;font-size:12px;color:#64748b}.ci-live-score{font-size:22px;font-weight:900}.ci-live-score.good{color:#166534}.ci-live-score.warn{color:#854d0e}.ci-live-score.bad{color:#991b1b}
  `;
  document.head.appendChild(style);

  function channelKey(article) {
    const text = $('.source-title .badge', article)?.textContent || article.dataset.svSearch || '';
    const s = text.toLocaleLowerCase('pt-BR');
    if (s.includes('mercado')) return 'ml';
    if (s.includes('shopee')) return 'shopee';
    if (s.includes('amazon')) return 'amazon';
    if (s.includes('tiktok')) return 'tiktok';
    if (s.includes('olist') || s.includes('tiny') || s.includes('erp')) return 'erp';
    return 'site';
  }
  function norm(value, list = false) {
    let text = String(value ?? '').replace(/\r/g, '').trim();
    if (list) return text.split(/[\n,|]+/).map((v)=>v.trim().replace(/^[-•]+\s*/, '')).filter(Boolean).map((v)=>v.toLocaleLowerCase('pt-BR')).sort().join('|');
    return text.replace(/\s+/g, ' ').toLocaleLowerCase('pt-BR');
  }
  function sourceOriginal(value) {
    const text = String(value ?? '').replace(/\r/g, '').trim();
    if (!text || ORIGINAL_PLACEHOLDER.test(text)) return '';
    return text;
  }
  function originals(article) {
    const values = {};
    const root = $('.sv-original-inner', article) || $('.compare>div:first-child', article);
    if (!root) return values;
    const labels = $$('.before-label', root);
    labels.forEach((label, index) => {
      const raw = label.nextElementSibling?.classList.contains('before') ? label.nextElementSibling.textContent : '';
      if (FIELD_MAP[index]) values[FIELD_MAP[index].key] = sourceOriginal(raw);
    });
    return values;
  }
  function listCount(value) { return String(value||'').split(/[\n,|]+/).map((v)=>v.trim()).filter(Boolean).length; }
  function repeatedWords(value) {
    const words = norm(value).split(/[^\p{L}\p{N}]+/u).filter((w)=>w.length>3);
    const counts = {}; words.forEach((w)=>counts[w]=(counts[w]||0)+1);
    return Object.entries(counts).filter(([,n])=>n>2).map(([w])=>w).slice(0,3);
  }
  function fieldState(original, current, list) {
    const a=norm(original,list), b=norm(current,list);
    if(a===b) return 'same';
    if(!a&&b) return 'new';
    if(a&&!b) return 'removed';
    return 'changed';
  }
  function stateLabel(state) { return state==='same'?'Sem mudança':state==='new'?'Novo':state==='removed'?'Removido':'Alterado'; }

  function analyze(field, value, rule) {
    const issues=[], good=[]; const len=String(value||'').trim().length;
    if (['optimized_title','optimized_description','meta_title','meta_description'].includes(field.name) && !len) issues.push('Campo vazio.');
    if (field.name==='optimized_title') {
      if (len>rule.titleMax) issues.push(`Título excede ${rule.titleMax} caracteres (${len}).`);
      else good.push(`Título dentro do teto de ${rule.titleMax} caracteres.`);
      if (len<rule.titleMin) issues.push(`Título curto para ${rule.label}; alvo mínimo editorial ${rule.titleMin}.`);
      if (rule.titleIdeal && len>rule.titleIdeal) issues.push(`Título válido, mas acima do alvo mobile de ${rule.titleIdeal} caracteres.`);
      const repeat=repeatedWords(value); if(repeat.length) issues.push(`Reduza repetição: ${repeat.join(', ')}.`);
      if (/[!?]{1,}|[🔥🚀⭐✅]/u.test(value)) issues.push('Remova pontuação promocional ou emojis do título.');
    }
    if (field.name==='optimized_description') {
      if (len<rule.descMin) issues.push(`Descrição com ${len} caracteres; aprofunde fatos comprovados para o canal (alvo ${rule.descMin}+).`);
      else good.push('Descrição possui profundidade editorial adequada.');
    }
    if (field.name==='bullet_points') {
      const n=listCount(value), [min,max]=rule.bullets;
      if(n<min||n>max) issues.push(`Use ${min===max?min:`${min} a ${max}`} bullet(s); atualmente ${n}.`); else good.push(`Quantidade de bullets adequada (${n}).`);
    }
    if (field.name==='meta_title' && rule.metaTitleMax) {
      if(len>rule.metaTitleMax) issues.push(`Meta title excede ${rule.metaTitleMax} caracteres.`); else if(len) good.push('Meta title dentro do limite.');
    }
    if (field.name==='meta_description' && rule.metaDescriptionMax) {
      if(len>rule.metaDescriptionMax) issues.push(`Meta description excede ${rule.metaDescriptionMax} caracteres.`); else if(len>=110) good.push('Meta description em faixa útil.'); else if(len) issues.push('Meta description curta; procure 110–160 caracteres factuais.');
    }
    if (PROMO.test(value) && field.name!=='marketing_hooks') issues.push('Detectada linguagem comercial protegida ou promocional; remova antes de aplicar.');
    if (rule===CHANNELS.erp && ['seo_keywords','marketing_hooks'].includes(field.name) && len) issues.push('No ERP técnico este campo deve ficar vazio.');
    return {issues,good};
  }

  function enhance(article) {
    if (article.dataset.ci === '1') return;
    article.dataset.ci='1';
    const edit=$('.edit',article); if(!edit) return;
    const rule=CHANNELS[channelKey(article)]||CHANNELS.site;
    const original=originals(article);
    const summary=document.createElement('section'); summary.className='ci-summary';
    summary.innerHTML=`<div class="ci-summary-head"><div><h3>Alterações e inteligência para ${esc(rule.label)}</h3><p>${esc(rule.focus)}</p></div><span class="ci-live-score warn" data-ci-score>—</span></div><div class="ci-chips" data-ci-fields></div><div class="ci-actions"><button type="button" class="ci-toggle" data-ci-only>Mostrar somente campos alterados</button><button type="button" class="ci-toggle" data-ci-open-original>Ver cadastro original</button></div><div class="ci-original-map">Prioridade neste canal: <strong>${esc(rule.priority.join(' → '))}</strong>. A validação do servidor continua sendo a autoridade final.</div>`;
    const quality=$('.sv-quality-friendly',article)||$('.quality',article); (quality||edit).insertAdjacentElement(quality?'afterend':'beforebegin',summary);
    const records=[];
    FIELD_MAP.forEach((field)=>{
      const input=$(`[name="${field.name}"]`,edit); if(!input)return;
      const label=input.closest('label'); if(!label)return;
      const list=['bullet_points','seo_keywords','marketing_hooks'].includes(field.name);
      input.dataset.ciOriginal=original[field.key]??'';
      label.classList.add('ci-field');
      const line=document.createElement('div'); line.className='ci-label-line';
      const title=document.createElement('span'); title.className='ci-label-title';
      title.textContent=field.label;
      const badge=document.createElement('span'); badge.className='ci-badge';
      const restore=document.createElement('button'); restore.type='button'; restore.className='ci-restore'; restore.textContent='Restaurar original';
      line.append(title,badge,restore); label.insertBefore(line,label.firstChild);
      const hint=document.createElement('div'); hint.className='ci-hint'; label.appendChild(hint);
      const record={field,input,label,badge,restore,hint,list,state:'same'}; records.push(record);
      restore.addEventListener('click',()=>{input.value=input.dataset.ciOriginal||'';input.dispatchEvent(new Event('input',{bubbles:true}));input.focus()});
    });

    function update() {
      let changed=0, hard=0, warnings=0; const chips=[];
      records.forEach((record)=>{
        const current=record.input.value||'', before=record.input.dataset.ciOriginal||'', state=fieldState(before,current,record.list), report=analyze(record.field,current,rule);
        record.state=state; record.label.classList.remove('same','changed','new','removed'); record.label.classList.add(state);
        record.badge.className=`ci-badge ${state}`; record.badge.textContent=stateLabel(state); record.restore.hidden=state==='same';
        if(state!=='same'){changed++;chips.push(`<span class="ci-chip ${state==='removed'?'bad':state==='new'?'warn':'good'}">${esc(record.field.label)}: ${esc(stateLabel(state))}</span>`)}
        const issue=report.issues[0], ok=report.good[0];
        record.hint.className=`ci-hint ${issue?(PROMO.test(current)?'bad':'warn'):'good'}`;
        record.hint.textContent=issue||ok||`Campo auxiliar para ${rule.label}; revise pertinência e factualidade.`;
        if(report.issues.length){warnings+=report.issues.length;if(PROMO.test(current)||!String(current).trim()&&['optimized_title','optimized_description'].includes(record.field.name))hard++}
      });
      const score=Math.max(0,100-hard*20-Math.max(0,warnings-hard)*5);
      const scoreEl=$('[data-ci-score]',summary); scoreEl.textContent=`Assistente ${score}/100`;scoreEl.className=`ci-live-score ${score>=85?'good':score>=65?'warn':'bad'}`;
      $('[data-ci-fields]',summary).innerHTML=chips.length?chips.join(''):'<span class="ci-chip">Nenhum campo editorial mudou.</span>';
      summary.dataset.changed=String(changed);
    }
    records.forEach((r)=>r.input.addEventListener('input',update));
    $('[data-ci-only]',summary).addEventListener('click',(event)=>{article.classList.toggle('ci-only-changed');event.currentTarget.textContent=article.classList.contains('ci-only-changed')?'Mostrar todos os campos':'Mostrar somente campos alterados'});
    $('[data-ci-open-original]',summary).addEventListener('click',()=>{const d=$('.sv-original-details',article);if(d){d.open=true;d.scrollIntoView({behavior:'smooth',block:'nearest'})}});
    update();
  }

  const boot=()=>$$('article.sv-review-card,article.item').forEach(enhance);
  boot();
  setTimeout(boot,50);
})();
