import fs from 'node:fs';

const code = fs.readFileSync(0, 'utf8').trim();
if (!/^\d{6}$/.test(code)) throw new Error('invalid secret input');

const { Cdp } = await import(
  'file:///home/ubuntu/.local/share/shopvivaliz-chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs'
);
const tabs = await (await fetch('http://127.0.0.1:9555/json/list')).json();
const page = tabs.find(
  tab => tab.type === 'page' && String(tab.url || '').includes('auth.openai.com/email-verification'),
);
if (!page) throw new Error('verification page missing');

const ws = new WebSocket(page.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
  ws.addEventListener('open', resolve, { once: true });
  ws.addEventListener('error', reject, { once: true });
});
const cdp = new Cdp(ws);

try {
  const expr =
    "(()=>{const input=document.querySelector('input[name=code]');"
    + "if(!input)return false;"
    + "const setter=Object.getOwnPropertyDescriptor(HTMLInputElement.prototype,'value').set;"
    + "setter.call(input," + JSON.stringify(code) + ");"
    + "input.dispatchEvent(new Event('input',{bubbles:true}));"
    + "input.dispatchEvent(new Event('change',{bubbles:true}));"
    + "const button=Array.from(document.querySelectorAll('button')).find(node=>/^Continue$/i.test(String(node.textContent||'').trim()));"
    + "if(!button||button.disabled)return false;"
    + "button.click();return true;})()";
  const submitted = await cdp.evaluate(expr);
  if (!submitted) throw new Error('verification submit unavailable');

  await new Promise(resolve => setTimeout(resolve, 10000));
  const state = await cdp.evaluate(
    "(()=>({host:location.hostname,path:location.pathname,title:document.title}))()",
  );
  const host = String(state?.host || '');
  const path = String(state?.path || '');
  if (host !== 'chatgpt.com' || path === '/auth/login') {
    throw new Error('verification did not reach ChatGPT');
  }

  console.log('CHATGPT_OTP_SUBMIT_HOST=' + host);
  console.log('CHATGPT_OTP_SUBMIT_PATH=' + path.slice(0, 160));
  console.log('CHATGPT_OTP_SUBMIT=PASS');
} finally {
  cdp.close();
}
