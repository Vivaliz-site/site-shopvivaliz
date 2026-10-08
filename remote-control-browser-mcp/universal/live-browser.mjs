import { chromium } from 'playwright-core';
import { readFileSync, writeFileSync, renameSync, existsSync, mkdirSync, statSync, lstatSync, realpathSync, unlinkSync } from 'node:fs';
import { dirname, resolve, basename } from 'node:path';
import { fileURLToPath } from 'node:url';
import { lookup } from 'node:dns/promises';
import { isIP } from 'node:net';
import { randomUUID } from 'node:crypto';

const defaultRoot=dirname(fileURLToPath(import.meta.url));
const MAX_DOWNLOAD_BYTES=10_000_000;
const allowedKeys=new Set(['Enter','Tab','Escape','ArrowUp','ArrowDown','Space']);

function forbidIp(ip) {
  if (ip.includes(':')) {
    const v=ip.toLowerCase();
    if (v.startsWith('::ffff:')) {
      const tail=v.slice(7);
      if (isIP(tail)===4) return forbidIp(tail);
      const parts=tail.split(':');
      if (parts.length===2 && parts.every(p=>/^[0-9a-f]{1,4}$/.test(p))) {
        const n=(parseInt(parts[0],16)*65536+parseInt(parts[1],16))>>>0;
        return forbidIp([n>>>24,(n>>>16)&255,(n>>>8)&255,n&255].join('.'));
      }
      return true;
    }
    // 2000::/3 is the globally routed IPv6 address family.
    const first=parseInt(v.split(':')[0],16);
    return !(first>=0x2000 && first<0x4000) || v.startsWith('2001:db8:');
  }
  const p=ip.split('.').map(Number);
  if(p.length!==4 || p.some(x=>!Number.isInteger(x)||x<0||x>255))return true;
  return p[0]===0 || p[0]===10 || p[0]===127 || p[0]>=224 ||
    (p[0]===100 && p[1]>=64 && p[1]<=127) ||
    (p[0]===169 && p[1]===254) ||
    (p[0]===172 && p[1]>=16 && p[1]<=31) ||
    (p[0]===192 && (p[1]===168 || (p[1]===0 && p[2]===0))) ||
    (p[0]===198 && (p[1]===18 || p[1]===19));
}

async function validateUrl(value) {
  if(typeof value!=='string'||value.length>4096)throw Error('invalid_url');
  const u=new URL(value);
  if(!['https:','http:'].includes(u.protocol)||u.username||u.password)throw Error('unsupported_url');
  const host=u.hostname.replace(/^\[|\]$/g,'').toLowerCase();
  if(host==='localhost'||host.endsWith('.localhost')||host.endsWith('.local')||host.endsWith('.internal'))throw Error('local_address_blocked');
  const addresses=isIP(host)?[{address:host}]:await lookup(host,{all:true,verbatim:true});
  if(!addresses.length||addresses.some(a=>forbidIp(a.address)))throw Error('private_address_blocked');
  return u.toString();
}

function publicUrl(value) {
  try {
    const u=new URL(value);
    if(!['https:','http:'].includes(u.protocol))return '';
    u.username='';u.password='';u.search='';u.hash='';
    return u.toString();
  } catch { return ''; }
}

function safeSelector(input) {
  if(typeof input!=='string'||!input||input.length>500)throw Error('invalid_selector');
  return input;
}

async function saveDownload(download,target) {
  await download.saveAs(target);
  const st=statSync(target);
  if(st.size>MAX_DOWNLOAD_BYTES){unlinkSync(target);throw Error('download_too_large');}
  return st.size;
}

export class BrowserSession {
  constructor({root=defaultRoot,profileDir=resolve(root,'isolated-profile'),binary=process.env.SHOPVIVALIZ_BROWSER_UNIVERSAL_BINARY||'/opt/shopvivaliz-browser/chrome-linux/chrome'}={}) {
    this.root=root;this.profileDir=profileDir;this.binary=binary;
    this.tabsPath=resolve(root,'tabs-state.json');
    this.context=null;this.defaultPage=null;
    this.tabs=new Map();this.liveTabs=new Map();this.activeId=null;
  }

  async start() {
    if(this.context)return;
    mkdirSync(this.root,{recursive:true,mode:0o700});
    mkdirSync(this.profileDir,{recursive:true,mode:0o700});
    this.context=await chromium.launchPersistentContext(this.profileDir,{
      executablePath:this.binary,headless:true,
      args:['--no-sandbox','--disable-dev-shm-usage'],
      timeout:25000,viewport:{width:1280,height:850},acceptDownloads:true,
    });
    this.defaultPage=this.context.pages()[0]||await this.context.newPage();
    this.defaultPage.setDefaultTimeout(10000);
    await this.context.route('**/*',async route=>{
      const url=route.request().url();
      if(!/^https?:/i.test(url))return route.continue();
      try {await validateUrl(url);return route.continue();}
      catch {return route.abort('blockedbyclient');}
    });
    if(existsSync(this.tabsPath)){
      const saved=JSON.parse(readFileSync(this.tabsPath,'utf8'));
      if(saved && Array.isArray(saved.tabs))for(const t of saved.tabs.slice(0,16)){
        if(typeof t.id==='string'&&t.id.startsWith('tab-')&&typeof t.url==='string'&&/^https?:/.test(t.url)){
          this.tabs.set(t.id,{id:t.id,url:publicUrl(t.url),title:String(t.title||'').slice(0,200)});
        }
      }
      if(this.tabs.has(saved?.active))this.activeId=saved.active;
    }
  }

  activePage(){return this.liveTabs.get(this.activeId)||this.defaultPage;}

  _save(){
    const data={active:this.activeId,tabs:[...this.tabs.values()]};
    const tmp=this.tabsPath+'.tmp';
    writeFileSync(tmp,JSON.stringify(data),{mode:0o600});
    renameSync(tmp,this.tabsPath);
  }

  async _pageFor(input={}){
    const id=input.tab_id||this.activeId;
    if(!id)return {page:this.defaultPage,id:null,reloaded:false};
    if(typeof id!=='string'||!this.tabs.has(id))throw Error('tab_not_found');
    const result=await this._restore(id);
    this.activeId=id;this._save();
    return result;
  }

  async _restore(id) {
    if(this.liveTabs.has(id) && !this.liveTabs.get(id).isClosed())return {page:this.liveTabs.get(id),id,reloaded:false};
    const record=this.tabs.get(id);
    const page=await this.context.newPage();
    page.setDefaultTimeout(10000);
    try {await page.goto(await validateUrl(record.url),{waitUntil:'domcontentloaded',timeout:20000});}
    catch(err){await page.close().catch(()=>{});throw err;}
    this.liveTabs.set(id,page);
    await this._update(id,page);
    return {page,id,reloaded:true};
  }

  async _update(id,page){
    if(!id)return;
    const record=this.tabs.get(id);
    if(!record)return;
    const clean=publicUrl(page.url());
    if(clean)record.url=clean;
    record.title=(await page.title().catch(()=>record.title)).slice(0,200);
    this._save();
  }

  async execute(action,input={}){
    await this.start();
    if(action==='tabs_list') {
      for(const [id,page] of this.liveTabs) if(!page.isClosed())await this._update(id,page);
      return {ok:true,active:this.activeId,tabs:[...this.tabs.values()]};
    }
    if(action==='tabs_open') {
      if(this.tabs.size>=16)throw Error('tabs_limit_16');
      const url=await validateUrl(input.url);
      const page=await this.context.newPage();
      page.setDefaultTimeout(10000);
      try {await page.goto(url,{waitUntil:'domcontentloaded',timeout:20000});}
      catch(e){await page.close().catch(()=>{});throw e;}
      const id='tab-'+randomUUID();
      this.tabs.set(id,{id,url:publicUrl(page.url()),title:(await page.title()).slice(0,200)});
      this.liveTabs.set(id,page);
      this.activeId=id;this._save();
      return {ok:true,active:id,tab:this.tabs.get(id),tabsCount:this.tabs.size};
    }
    if(action==='tabs_switch') {
      if(typeof input.tab_id!=='string'||!this.tabs.has(input.tab_id))throw Error('tab_not_found');
      const {page,reloaded}=await this._restore(input.tab_id);
      this.activeId=input.tab_id;this._save();
      return {ok:true,active:input.tab_id,tab:this.tabs.get(input.tab_id),reloaded};
    }
    if(action==='tabs_close') {
      if(typeof input.tab_id!=='string'||!this.tabs.has(input.tab_id))throw Error('tab_not_found');
      const page=this.liveTabs.get(input.tab_id);
      if(page&&!page.isClosed())await page.close();
      this.liveTabs.delete(input.tab_id);this.tabs.delete(input.tab_id);
      if(this.activeId===input.tab_id)this.activeId=[...this.tabs.keys()].at(-1)||null;
      this._save();
      return {ok:true,active:this.activeId,tabsCount:this.tabs.size};
    }
    if(action==='probe')return this._probe();
    if(!['open','inspect','click','fill','select','check','press','upload','download'].includes(action))throw Error('unsupported_action');
    const {page,id}=await this._pageFor(input);
    if(input.url){
      const url=await validateUrl(input.url);
      if(page.url()!==url)await page.goto(url,{waitUntil:'domcontentloaded',timeout:20000});
    } else if(!id)throw Error('url_or_tab_id_required');
    if(action==='open'||action==='inspect'){
      if(action==='inspect'){
        const controls=await page.locator('button,a,input,select,textarea,[role="button"]').evaluateAll(elements=>elements.slice(0,80).map((el,index)=>({
          index,tag:el.tagName.toLowerCase(),role:el.getAttribute('role'),
          label:(el.getAttribute('aria-label')||el.innerText||el.getAttribute('placeholder')||'').trim().slice(0,100),
          type:el.getAttribute('type')
        })));
        await this._update(id,page);
        return {ok:true,url:publicUrl(page.url()),title:(await page.title()).slice(0,200),controls};
      }
      await this._update(id,page);
      return {ok:true,url:publicUrl(page.url()),title:(await page.title()).slice(0,200)};
    }
    const el=page.locator(safeSelector(input.selector)).first();
    if(action==='click')await el.click();
    else if(action==='fill'){
      if(typeof input.text!=='string'||input.text.length>20000)throw Error('invalid_text');
      await el.fill(input.text);
    } else if(action==='select'){
      if(typeof input.value!=='string'||input.value.length>500)throw Error('invalid_value');
      await el.selectOption(input.value);
    } else if(action==='check')await el.check();
    else if(action==='press'){
      if(!allowedKeys.has(input.key))throw Error('invalid_key');
      await el.press(input.key);
    } else if(action==='upload'){
      if(typeof input.filename!=='string'||basename(input.filename)!==input.filename||!/^[a-zA-Z0-9_.-]{1,120}$/.test(input.filename))throw Error('invalid_filename');
      const staging=resolve(this.root,'upload-staging');
      if(realpathSync(staging)!==staging)throw Error('upload_staging_symlink_blocked');
      const source=resolve(staging,input.filename);
      const st=lstatSync(source);
      if(st.isSymbolicLink()||!st.isFile()||st.size>10_000_000||realpathSync(source)!==source)throw Error('upload_file_invalid');
      await el.setInputFiles(source);
    } else if(action==='download'){
      const [download]=await Promise.all([page.waitForEvent('download',{timeout:15000}),el.click()]);
      const name=basename(download.suggestedFilename()).replace(/[^a-zA-Z0-9_.-]/g,'_').slice(0,120);
      if(!name||name==='.'||name==='..')throw Error('invalid_download_name');
      const folder=resolve(this.root,'downloads');
      mkdirSync(folder,{recursive:true,mode:0o700});
      const target=resolve(folder,Date.now()+'-'+name);
      const bytes=await saveDownload(download,target);
      await this._update(id,page);
      return {ok:true,action,filename:basename(target),bytes};
    }
    await this._update(id,page);
    return {ok:true,action,url:publicUrl(page.url()),title:(await page.title()).slice(0,200)};
  }

  async _probe() {
    const page=await this.context.newPage();
    try {
      page.setDefaultTimeout(10000);
      await page.goto('data:text/html,<title>Probe</title><h1>OK</h1><input id=q><select id=s><option value=a>A</option><option value=b>B</option></select><input id=c type=checkbox><input id=file type=file><a id=dl download=test.txt href=data:text/plain,hello>Download</a><button id=go onclick="document.title=\'Clicked\'">Go</button>');
      await page.locator('#q').fill('hello');
      await page.locator('#s').selectOption('b');
      await page.locator('#c').check();
      await page.locator('#go').click();
      const staging=resolve(this.root,'upload-staging');
      mkdirSync(staging,{recursive:true,mode:0o700});
      const fixture=resolve(staging,'smoke-test.txt');
      writeFileSync(fixture,'test',{mode:0o600});
      await page.locator('#file').setInputFiles(fixture);
      const [download]=await Promise.all([page.waitForEvent('download'),page.locator('#dl').click()]);
      const smallBytes=await saveDownload(download,resolve(this.root,'probe-download.txt'));
      const fake={saveAs:async target=>writeFileSync(target,Buffer.alloc(MAX_DOWNLOAD_BYTES+1))};
      const largeTarget=resolve(this.root,'probe-large.bin');
      let largeRejected=false;
      try {await saveDownload(fake,largeTarget);}
      catch(e){if(e.message!=='download_too_large')throw e;largeRejected=true;}
      if(!largeRejected||existsSync(largeTarget))throw Error('large_download_not_blocked');
      return {ok:true,title:await page.title(),heading:await page.locator('h1').innerText(),
        value:await page.locator('#q').inputValue(),selected:await page.locator('#s').inputValue(),
        checked:await page.locator('#c').isChecked(),uploadFile:(await page.locator('#file').inputValue()).endsWith('smoke-test.txt'),
        downloadBytes:smallBytes,oversizedDownloadRejected:largeRejected};
    } finally {await page.close().catch(()=>{});}
  }

  async close() {
    if(this.context)await this.context.close();
    this.context=null;this.defaultPage=null;this.liveTabs.clear();
  }
}
