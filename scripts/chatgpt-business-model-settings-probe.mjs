import { Cdp } from './chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs';

const CDP_URL = 'http://127.0.0.1:9555';
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

function safeTag(value) {
  return String(value || 'probe_failed').replace(/[^A-Za-z0-9_.\/-]+/g, '_').slice(0, 120);
}

function bool(value) { return value ? 'true' : 'false'; }

async function cdpHttp(path, options = {}) {
  const response = await fetch(CDP_URL + path, { ...options, signal: AbortSignal.timeout(5000) });
  if (!response.ok) throw new Error('cdp_http_' + response.status);
  return response;
}

async function openTarget(url) {
  const response = await cdpHttp('/json/new?' + encodeURIComponent(url), { method: 'PUT' });
  const target = await response.json();
  if (!target?.id || !target?.webSocketDebuggerUrl) throw new Error('cdp_target_missing');
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await Promise.race([
    new Promise((resolve, reject) => {
      ws.addEventListener('open', resolve, { once: true });
      ws.addEventListener('error', reject, { once: true });
    }),
    new Promise((_, reject) => setTimeout(() => reject(new Error('cdp_target_open_timeout')), 4000)),
  ]);
  const cdp = new Cdp(ws);
  await Promise.race([
    cdp.evaluate('true'),
    new Promise((_, reject) => setTimeout(() => reject(new Error('cdp_target_liveness_timeout')), 4000)),
  ]);
  return { cdp, target };
}

async function waitForDom(cdp) {
  await Promise.race([
    cdp.evaluate(`new Promise(resolve=>{
      if(document.readyState==='interactive'||document.readyState==='complete') return resolve(true);
      document.addEventListener('DOMContentLoaded',()=>resolve(true),{once:true});
      setTimeout(()=>resolve(true),10000);
    })`),
    new Promise((_, reject) => setTimeout(() => reject(new Error('dom_ready_timeout')), 12000)),
  ]);
  await sleep(1800);
}

async function navigate(cdp, url) {
  await cdp.evaluate('(()=>{location.href=' + JSON.stringify(url) + ';return true})()');
  await sleep(700);
  await waitForDom(cdp);
}

async function pageState(cdp) {
  return cdp.evaluate(`(()=>{
    const text=(document.body?.innerText||'').slice(0,30000);
    const links=Array.from(document.querySelectorAll('a[href]'))
      .map(a=>String(a.getAttribute('href')||''))
      .filter(href=>/(?:^|\\/)admin(?:\\/|$)|models?|modelos/i.test(href))
      .slice(0,80);
    return {
      href:String(location.href||''),
      text,
      links,
      password:Boolean(document.querySelector('input[type="password"]')),
    };
  })()`);
}

async function clickProfileMenu(cdp) {
  return cdp.evaluate(`(()=>{
    const selectors=[
      'button[data-testid="profile-button"]',
      'button[data-testid="accounts-profile-button"]',
      'button[aria-label*="profile" i]',
      'button[aria-label*="settings" i]',
      'button[aria-label*="configura" i]'
    ];
    let el=null;
    for(const selector of selectors){ el=document.querySelector(selector); if(el) break; }
    if(!el){
      const nodes=Array.from(document.querySelectorAll('button,[role="button"]'));
      el=nodes.find(node=>{
        const label=String(node.innerText||node.textContent||node.getAttribute('aria-label')||'').replace(/\\s+/g,' ').trim();
        return /^(profile|perfil|settings|configura[cç][oõ]es|profile and settings|perfil e configura[cç][oõ]es)$/i.test(label);
      })||null;
    }
    if(!el) return false;
    el.click();
    return true;
  })()`);
}

async function clickWorkspaceAdmin(cdp) {
  return cdp.evaluate(`(()=>{
    const nodes=Array.from(document.querySelectorAll('a,button,[role="button"],[role="link"],[role="menuitem"]'));
    const el=nodes.find(node=>{
      const label=String(node.innerText||node.textContent||node.getAttribute('aria-label')||'').replace(/\\s+/g,' ').trim();
      return /^(admin|admin console|workspace settings|manage workspace|configura[cç][oõ]es do workspace|gerenciar workspace)$/i.test(label);
    });
    if(!el) return false;
    el.click();
    return true;
  })()`);
}

async function clickSettings(cdp) {
  return cdp.evaluate(`(()=>{
    const nodes=Array.from(document.querySelectorAll('a,button,[role="button"],[role="link"],[role="menuitem"]'));
    const el=nodes.find(node=>{
      const label=String(node.innerText||node.textContent||node.getAttribute('aria-label')||'').replace(/\\s+/g,' ').trim();
      return /^(settings|configura[cç][oõ]es|account settings|configura[cç][oõ]es da conta)$/i.test(label);
    });
    if(!el) return false;
    el.click();
    return true;
  })()`);
}

async function openSettingsNavigation(cdp) {
  const profileOpened = await clickProfileMenu(cdp).catch(() => false);
  if (profileOpened) await sleep(1200);
  return profileOpened;
}

async function openWorkspaceAdmin(cdp) {
  if (await clickWorkspaceAdmin(cdp).catch(() => false)) return true;
  const settingsOpened = await clickSettings(cdp).catch(() => false);
  if (!settingsOpened) return false;
  await sleep(1200);
  return clickWorkspaceAdmin(cdp).catch(() => false);
}

async function openModelsSection(cdp) {
  return cdp.evaluate(`(()=>{
    const nodes=Array.from(document.querySelectorAll('a,button,[role="button"],[role="link"],[role="menuitem"]'));
    const el=nodes.find(node=>{
      const label=String(node.innerText||node.textContent||node.getAttribute('aria-label')||'').replace(/\\s+/g,' ').trim();
      return /^(models?|modelos)$/i.test(label);
    });
    if(!el) return false;
    el.click();
    return true;
  })()`);
}

async function controlStats(cdp) {
  return cdp.evaluate(`(()=>{
    const nodes=Array.from(document.querySelectorAll('button,select,[role="button"],[role="combobox"],input'));
    const interesting=nodes.filter(el=>{
      const text=[el.textContent,el.getAttribute('aria-label'),el.getAttribute('name'),el.getAttribute('title'),el.getAttribute('data-testid')].filter(Boolean).join(' ');
      return /model|modelo|reasoning|thinking|effort|intelligence|intelig[eê]ncia|instant|medium|m[eé]dio|high|alto/i.test(text);
    });
    const disabled=interesting.filter(el=>el.hasAttribute('disabled')||el.getAttribute('aria-disabled')==='true');
    return {total:interesting.length,disabled:disabled.length};
  })()`);
}

let cdp = null;
let target = null;

try {
  ({ cdp, target } = await openTarget('https://chatgpt.com/'));
  await waitForDom(cdp);
  let state = await pageState(cdp);
  const authenticated = !/auth\.openai\.com|\/auth(?:[/?#]|$)|\/login(?:[/?#]|$)/i.test(state.href) && !state.password;
  let modelsPageReached = false;
  let selectedRoute = 'none';
  let bodyText = '';
  let profileMenuOpened = false;
  let workspaceAdminOpened = false;
  let modelsSectionOpened = false;

  if (authenticated) {
    const candidateUrls = [];
    for (const href of state.links || []) {
      try {
        const url = new URL(href, 'https://chatgpt.com/');
        if (url.protocol === 'https:' && url.hostname === 'chatgpt.com') candidateUrls.push(url.toString());
      } catch {}
    }
    candidateUrls.push('https://chatgpt.com/admin/models','https://chatgpt.com/admin/settings/models','https://chatgpt.com/admin');
    const seen = new Set();
    for (const candidate of candidateUrls) {
      if (seen.has(candidate)) continue;
      seen.add(candidate);
      await navigate(cdp, candidate).catch(() => {});
      state = await pageState(cdp);
      bodyText = state.text || '';
      if (/admin/i.test(state.href)) {
        const clicked = await openModelsSection(cdp).catch(() => false);
        if (clicked) {
          modelsSectionOpened = true;
          await sleep(1800);
          state = await pageState(cdp);
          bodyText = state.text || '';
        }
      }
      const looksLikeModels = /models?|modelos|model settings|recommended|recomendad|reasoning|thinking|effort|intelligence|intelig[eê]ncia/i.test(bodyText) && (/admin/i.test(state.href) || modelsSectionOpened);
      if (looksLikeModels) {
        modelsPageReached = true;
        try { selectedRoute = new URL(state.href).pathname || '/'; } catch { selectedRoute = 'unknown'; }
        break;
      }
    }

    if (!modelsPageReached) {
      await navigate(cdp, 'https://chatgpt.com/').catch(() => {});
      profileMenuOpened = await openSettingsNavigation(cdp).catch(() => false);
      workspaceAdminOpened = await openWorkspaceAdmin(cdp).catch(() => false);
      if (workspaceAdminOpened) await sleep(1800);
      modelsSectionOpened = await openModelsSection(cdp).catch(() => false);
      if (modelsSectionOpened) await sleep(1800);
      state = await pageState(cdp);
      bodyText = state.text || '';
      const uiLooksLikeModels = modelsSectionOpened && /models?|modelos|recommended|recomendad|reasoning|thinking|effort|intelligence|intelig[eê]ncia/i.test(bodyText);
      if (uiLooksLikeModels) {
        modelsPageReached = true;
        try { selectedRoute = new URL(state.href).pathname || '/'; } catch { selectedRoute = 'ui-navigation'; }
      }
    }
  }

  const normalized = String(bodyText || '').replace(/\s+/g, ' ').trim();
  const loadErrorPresent = /couldn.?t load model settings|could not load model settings|model settings.*try again|n[aã]o foi poss[ií]vel carregar.*model/i.test(normalized);
  const accessDeniedPresent = /access denied|insufficient permission|permission required|not authorized|forbidden|sem permiss[aã]o|acesso negado/i.test(normalized);
  const solRecommendedPresent = /5\.6\s+Sol.{0,80}Recommended|Recommended.{0,80}5\.6\s+Sol|5\.6\s+Sol.{0,80}Recomendad/i.test(normalized);
  const reasoningTextPresent = /Instant(?:aneous|âneo)?|M[eé]dio|Medium|High|Alto|Extra\s+high|Extra\s+alto|reasoning|thinking|effort|intelig[eê]ncia superior|higher intelligence/i.test(normalized);
  const stats = modelsPageReached ? await controlStats(cdp).catch(() => ({ total: 0, disabled: 0 })) : { total: 0, disabled: 0 };
  const controlsPresent = Number(stats.total || 0) > 0;
  const enabledCount = Math.max(0, Number(stats.total || 0) - Number(stats.disabled || 0));
  const controlsEnabled = controlsPresent && enabledCount > 0 && !loadErrorPresent && !accessDeniedPresent;

  console.log('CHATGPT_BUSINESS_MODELS_AUTHENTICATED=' + bool(authenticated));
  console.log('CHATGPT_BUSINESS_MODELS_PAGE_REACHED=' + bool(modelsPageReached));
  console.log('CHATGPT_BUSINESS_MODELS_ROUTE=' + safeTag(selectedRoute));
  console.log('CHATGPT_BUSINESS_MODELS_PROFILE_MENU_OPENED=' + bool(profileMenuOpened));
  console.log('CHATGPT_BUSINESS_MODELS_WORKSPACE_ADMIN_OPENED=' + bool(workspaceAdminOpened));
  console.log('CHATGPT_BUSINESS_MODELS_MODELS_SECTION_OPENED=' + bool(modelsSectionOpened));
  console.log('CHATGPT_BUSINESS_MODELS_LOAD_ERROR_PRESENT=' + bool(loadErrorPresent));
  console.log('CHATGPT_BUSINESS_MODELS_ACCESS_DENIED_PRESENT=' + bool(accessDeniedPresent));
  console.log('CHATGPT_BUSINESS_MODELS_SOL_RECOMMENDED_PRESENT=' + bool(solRecommendedPresent));
  console.log('CHATGPT_BUSINESS_MODELS_REASONING_TEXT_PRESENT=' + bool(reasoningTextPresent));
  console.log('CHATGPT_BUSINESS_MODELS_CONTROLS_PRESENT=' + bool(controlsPresent));
  console.log('CHATGPT_BUSINESS_MODELS_CONTROLS_ENABLED=' + bool(controlsEnabled));
  console.log('CHATGPT_BUSINESS_MODELS_CONTROL_COUNT=' + Math.min(99, Number(stats.total || 0)));
  console.log('CHATGPT_BUSINESS_MODELS_DISABLED_COUNT=' + Math.min(99, Number(stats.disabled || 0)));
  console.log('CHATGPT_BUSINESS_MODELS_DIRECT_TARGET=true');
  console.log('CHATGPT_BUSINESS_MODELS_PROBE=PASS');
} catch (error) {
  console.log('CHATGPT_BUSINESS_MODELS_PROBE=FAIL blocker=' + safeTag(error?.message));
  process.exitCode = 2;
} finally {
  try { cdp?.close(); } catch {}
  if (target?.id) {
    await fetch(CDP_URL + '/json/close/' + encodeURIComponent(target.id), { signal: AbortSignal.timeout(3000) }).catch(() => {});
  }
  process.exit(process.exitCode || 0);
}
