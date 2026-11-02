import {resolve} from 'node:path';
import {mkdirSync} from 'node:fs';
import {safePublicUrl, sanitizedLocation} from './url-safety.mjs';

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
