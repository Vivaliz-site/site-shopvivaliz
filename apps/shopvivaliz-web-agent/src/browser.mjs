import {resolve} from 'node:path';
import {mkdirSync} from 'node:fs';
import {safePublicUrl, sanitizedLocation} from './url-safety.mjs';

// WebSocket upgrades are not covered by context.route('**/*').  Reject
// non-public destinations before opening a Chrome WebSocket connection.
export async function guardWebSocketRoute(route, validate=safePublicUrl) {
  try {
    const u=new URL(route.url());
    if (u.protocol==='wss:') u.protocol='https:';
    else if (u.protocol==='ws:') u.protocol='http:';
    else throw Error('unsupported_websocket_protocol');
    await validate(u.toString());
    return await route.connectToServer();
  } catch {
    return route.close({code:1008,reason:'private_destination_blocked'});
  }
}

export class BrowserDriver {
  constructor({profileDir=process.env.WEB_AGENT_PROFILE_DIR || resolve(process.cwd(),'.private-profile'), binary=process.env.WEB_AGENT_CHROME_BINARY || '/opt/shopvivaliz-browser/chrome-linux/chrome'}={}) {
    this.profileDir=resolve(profileDir);
    if (/shopvivaliz-(atendimento|dev)-chromium|fredconsole/i.test(this.profileDir)) throw Error('protected_browser_profile');
    this.binary=binary;
    this.context=null;
    this.page=null;
  }
  async start() {
    if (this.context) return;
    mkdirSync(this.profileDir,{recursive:true,mode:0o700});
    const {chromium}=await import('playwright-core');
    this.context=await chromium.launchPersistentContext(this.profileDir, {
      executablePath:this.binary,headless:true,viewport:{width:1280,height:800},
      acceptDownloads:false,serviceWorkers:'block',
      args:['--disable-dev-shm-usage']
    });
    await this.context.routeWebSocket('**/*', guardWebSocketRoute);
    await this.context.route('**/*', async route=>{
      const raw=route.request().url();
      if (/^(data|blob):/i.test(raw))return route.continue();
      try {await safePublicUrl(raw);return route.continue();}
      catch {return route.abort('blockedbyclient');}
    });
    this.page=this.context.pages()[0]||await this.context.newPage();
    this.page.setDefaultTimeout(10000);
  }
  async open(raw) {
    const url=await safePublicUrl(raw);
    await this.start();
    await this.page.goto(url,{waitUntil:'domcontentloaded',timeout:25000});
    return this.snapshot();
  }
  async navigate(raw) {
    return this.open(raw);
  }
  async back() {
    if(!this.page)throw Error('browser_not_open');
    await this.page.goBack({waitUntil:'domcontentloaded',timeout:15000});
    return this.snapshot();
  }
  async forward() {
    if(!this.page)throw Error('browser_not_open');
    await this.page.goForward({waitUntil:'domcontentloaded',timeout:15000});
    return this.snapshot();
  }
  async scroll(deltaY) {
    if(!Number.isInteger(deltaY)||deltaY===0||Math.abs(deltaY)>2000)throw Error('invalid_scroll');
    if(!this.page)throw Error('browser_not_open');
    await this.page.mouse.wheel(0,deltaY);
    return this.snapshot();
  }
  async fill(selector,value) {
    if(typeof selector!=='string'||!selector.length||selector.length>500)throw Error('invalid_selector');
    if(typeof value!=='string'||value.length>2000)throw Error('invalid_input');
    if(!this.page)throw Error('browser_not_open');
    const control=this.page.locator(selector).first();
    const disallowed=await control.evaluate(node=>{
      const tag=node.tagName.toLowerCase();
      const type=(node.getAttribute('type')||'text').toLowerCase();
      const attributes=['name','id','autocomplete','aria-label','placeholder'].map(name=>node.getAttribute(name)||'').join(' ').toLowerCase();
      const sensitive=/password|passcode|passphrase|one.time.code|one.time.password|verification.code|auth.code|authenticator|two.factor|2fa|mfa|otp|pin.code|security.pin|api.key|secret|token|cvv|cvc|security.code|card.number|credit.card|private.key/;
      if(sensitive.test(attributes))return true;
      if(tag==='input' && !['text','search','email','url','tel','number'].includes(type))return true;
      return !(tag==='input'||tag==='textarea'||node.isContentEditable);
    });
    if(disallowed)throw Error('sensitive_field_requires_human_takeover');
    await control.fill(value,{timeout:10000});
    return this.snapshot();
  }
  async snapshot() {
    if (!this.page)return {url:'',title:'',ready:false};
    return {url:sanitizedLocation(this.page.url()),title:(await this.page.title().catch(()=>'' )).slice(0,120),ready:true};
  }
  async inspect() {
    const summary=await this.snapshot();
    if (!this.page) return {...summary,controls:[]};
    const controls=await this.page.locator('a,button,input,select,textarea,[role="button"]').evaluateAll(nodes=>nodes.slice(0,50).map((node,index)=>({index,tag:node.tagName.toLowerCase(),type:node.getAttribute('type')||'',label:(node.getAttribute('aria-label')||node.getAttribute('placeholder')||((node.tagName.toLowerCase()==='input')?'':node.innerText)||'').trim().slice(0,90)})));
    return {...summary,controls};
  }
  async click(selector) {
    if(typeof selector!=='string' || selector.length>500 || selector.length===0)throw Error('invalid_selector');
    await this.page.locator(selector).first().click({timeout:10000});
    return this.snapshot();
  }
  async screenshot() {
    if(!this.page)throw Error('browser_not_open');
    return this.page.screenshot({type:'jpeg',quality:58,animations:'disabled',timeout:10000});
  }
  async mouse(x,y) {
    if(!Number.isInteger(x)||!Number.isInteger(y)||x<0||x>=1280||y<0||y>=800)throw Error('invalid_coordinates');
    await this.page.mouse.click(x,y);
  }
  async type(text) {
    if(typeof text!=='string' || text.length<1||text.length>500)throw Error('invalid_input');
    await this.page.keyboard.insertText(text);
  }
  async key(name) {
    if(!['Enter','Tab','Escape','Backspace','ArrowDown','ArrowUp','ArrowLeft','ArrowRight'].includes(name))throw Error('invalid_key');
    await this.page.keyboard.press(name);
  }
  async close() {
    if (this.context) await this.context.close();
    this.context=null;this.page=null;
  }
}
