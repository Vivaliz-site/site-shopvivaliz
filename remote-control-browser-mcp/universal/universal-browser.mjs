import { chromium } from 'playwright-core';
import { readFileSync, mkdirSync, statSync, lstatSync, realpathSync, unlinkSync, writeFileSync, renameSync, existsSync } from 'node:fs';
import { resolve, basename, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { lookup } from 'node:dns/promises';
import { isIP } from 'node:net';

const root = dirname(fileURLToPath(import.meta.url));
const profile = resolve(root, 'isolated-profile');
const binary = process.env.SHOPVIVALIZ_BROWSER_UNIVERSAL_BINARY || '/opt/shopvivaliz-browser/chrome-linux/chrome';
const tabsPath=resolve(root,'tabs-state.json');
const readTabs=()=>existsSync(tabsPath)?JSON.parse(readFileSync(tabsPath,'utf8')):{active:null,tabs:[]};
const writeTabs=t=>{const tmp=tabsPath+'.tmp';writeFileSync(tmp,JSON.stringify(t),{mode:0o600});renameSync(tmp,tabsPath)};
const action = process.argv[2] || '';
const input = JSON.parse(readFileSync(0, 'utf8') || '{}');
const isForbiddenIp = ip => {
  if (ip.includes(':')) {
    const addr = ip.toLowerCase();
    if (addr.startsWith('::ffff:')) return isForbiddenIp(addr.slice(7));
    return addr === '::' || addr === '::1' || addr.startsWith('fc') || addr.startsWith('fd') || addr.startsWith('fe8') || addr.startsWith('fe9') || addr.startsWith('fea') || addr.startsWith('feb') || addr.startsWith('2001:db8:');
  }
  const x = ip.split('.').map(Number);
  if (x.length !== 4 || x.some(n => !Number.isInteger(n) || n < 0 || n > 255)) return true;
  return x[0] === 0 || x[0] === 10 || x[0] === 127 || x[0] >= 224 || (x[0] === 100 && x[1] >= 64 && x[1] <= 127) || (x[0] === 169 && x[1] === 254) || (x[0] === 172 && x[1] >= 16 && x[1] <= 31) || (x[0] === 192 && (x[1] === 168 || (x[1] === 0 && x[2] === 0))) || (x[0] === 198 && (x[1] === 18 || x[1] === 19));
};
const validateUrl = async value => {
  const u = new URL(value);
  if (!['https:', 'http:'].includes(u.protocol) || u.username || u.password) throw new Error('unsupported_url');
  const hostname = u.hostname.replace(/^\[|\]$/g,'').toLowerCase();
  if (hostname === 'localhost' || hostname.endsWith('.localhost') || hostname.endsWith('.local') || hostname.endsWith('.internal')) throw new Error('local_address_blocked');
  const answers = isIP(hostname) ? [{address:hostname}] : await lookup(hostname,{all:true,verbatim:true});
  if (!answers.length || answers.some(a => isForbiddenIp(a.address))) throw new Error('private_address_blocked');
  return u.toString();
};
const selector = value => {
  if (typeof value !== 'string' || !value || value.length > 500) throw new Error('invalid_selector');
  return value;
};
const result = (o) => console.log(JSON.stringify(o));
let context;
try {
  mkdirSync(profile, { recursive: true, mode: 0o700 });
  context = await chromium.launchPersistentContext(profile, {
    executablePath: binary,
    headless: true,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
    timeout: 25000,
    viewport: { width: 1280, height: 850 },
    acceptDownloads: true,
  });
  const page = context.pages()[0] || await context.newPage();
  page.setDefaultTimeout(10000);
  await context.route('**/*', async route => {
    const req=route.request();
    if (!/^https?:/i.test(req.url())) return route.continue();
    try { await validateUrl(req.url()); return route.continue(); }
    catch { return route.abort('blockedbyclient'); }
  });
  if (action === 'tabs_list') {
    const tabs=readTabs();
    result({ok:true,active:tabs.active,tabs:tabs.tabs.map(t=>({id:t.id,url:t.url,title:t.title}))});
  } else if (action === 'tabs_open') {
    const url=await validateUrl(input.url);
    const tabs=readTabs();
    if(tabs.tabs.length>=16) throw new Error('tabs_limit_16');
    await page.goto(url,{waitUntil:'domcontentloaded',timeout:20000});
    const id='tab-'+Date.now().toString(36);
    const item={id,url:page.url(),title:(await page.title()).slice(0,200)};
    tabs.tabs.push(item); tabs.active=id;writeTabs(tabs);
    result({ok:true,active:id,tab:item,tabsCount:tabs.tabs.length});
  } else if (action === 'tabs_switch') {
    if(typeof input.tab_id!=='string')throw new Error('invalid_tab_id');
    const tabs=readTabs(); const item=tabs.tabs.find(t=>t.id===input.tab_id);
    if(!item)throw new Error('tab_not_found');
    await page.goto(await validateUrl(item.url),{waitUntil:'domcontentloaded',timeout:20000});
    item.url=page.url();item.title=(await page.title()).slice(0,200);
    tabs.active=item.id;writeTabs(tabs);
    result({ok:true,active:item.id,tab:item,note:'logical_tab_restored_by_navigation'});
  } else if (action === 'tabs_close') {
    if(typeof input.tab_id!=='string')throw new Error('invalid_tab_id');
    const tabs=readTabs();const before=tabs.tabs.length;
    tabs.tabs=tabs.tabs.filter(t=>t.id!==input.tab_id);
    if(tabs.tabs.length===before)throw new Error('tab_not_found');
    if(tabs.active===input.tab_id)tabs.active=tabs.tabs.at(-1)?.id||null;
    writeTabs(tabs);result({ok:true,active:tabs.active,tabsCount:tabs.tabs.length});
  } else if (action === 'probe') {
    await page.goto('data:text/html,<title>Probe</title><h1>OK</h1><input id=q><select id=s><option value=a>A</option><option value=b>B</option></select><input id=c type=checkbox><input id=file type=file><a id=dl download=test.txt href=data:text/plain,hello>Download</a><button id=go onclick="document.title=\'Clicked\'">Go</button>');
    await page.locator('#q').fill('hello');
    await page.locator('#s').selectOption('b');
    await page.locator('#c').check();
    await page.locator('#go').click();
    const uploadFile=resolve(root,'upload-staging','smoke-test.txt');
    mkdirSync(resolve(root,'upload-staging'),{recursive:true,mode:0o700});
    writeFileSync(uploadFile,'test', {mode:0o600});
    await page.locator('#file').setInputFiles(uploadFile);
    const [testDownload]=await Promise.all([page.waitForEvent('download'),page.locator('#dl').click()]);
    await testDownload.saveAs(resolve(root,'probe-download.txt'));
    result({ok:true, title:await page.title(), heading:await page.locator('h1').innerText(), value:await page.locator('#q').inputValue(), selected:await page.locator('#s').inputValue(), checked:await page.locator('#c').isChecked(),uploadFile:(await page.locator('#file').inputValue()).endsWith('smoke-test.txt'),downloadBytes:statSync(resolve(root,'probe-download.txt')).size});
  } else if (action === 'open') {
    await page.goto(await validateUrl(input.url), {waitUntil:'domcontentloaded',timeout:20000});
    result({ok:true,url:page.url().split('?')[0],title:(await page.title()).slice(0,200)});
  } else if (action === 'inspect') {
    await page.goto(await validateUrl(input.url), {waitUntil:'domcontentloaded',timeout:20000});
    const controls = await page.locator('button,a,input,select,textarea,[role="button"]').evaluateAll(els => els.slice(0,80).map((e,i)=>({index:i,tag:e.tagName.toLowerCase(),role:e.getAttribute('role'),label:(e.getAttribute('aria-label')||e.innerText||e.getAttribute('placeholder')||'').trim().slice(0,100),type:e.getAttribute('type')})));
    result({ok:true,url:page.url().split('?')[0],title:(await page.title()).slice(0,200),controls});
  } else if (['click','fill','select','check','press','upload','download'].includes(action)) {
    await page.goto(await validateUrl(input.url), {waitUntil:'domcontentloaded',timeout:20000});
    const el = page.locator(selector(input.selector)).first();
    if (action === 'click') await el.click();
    else if (action === 'fill') {
      if (typeof input.text !== 'string' || input.text.length > 20000) throw new Error('invalid_text');
      await el.fill(input.text);
    } else if (action === 'select') {
      if (typeof input.value !== 'string' || input.value.length > 500) throw new Error('invalid_value');
      await el.selectOption(input.value);
    } else if (action === 'check') {
      await el.check();
    } else if (action === 'upload') {
      if (typeof input.filename !== 'string' || basename(input.filename) !== input.filename || !/^[a-zA-Z0-9_.-]{1,120}$/.test(input.filename)) throw new Error('invalid_filename');
      const source=resolve(root,'upload-staging',input.filename);
      const staging=resolve(root,'upload-staging');
      if(realpathSync(staging)!==staging)throw new Error('upload_staging_symlink_blocked');
      const st=lstatSync(source);
      if(st.isSymbolicLink() || !st.isFile() || st.size>10_000_000)throw new Error('upload_file_invalid');
      if(realpathSync(source)!==source)throw new Error('upload_file_symlink_blocked');
      await el.setInputFiles(source);
    } else if (action === 'download') {
      const [download]=await Promise.all([page.waitForEvent('download',{timeout:15000}),el.click()]);
      const name=basename(download.suggestedFilename()).replace(/[^a-zA-Z0-9_.-]/g,'_').slice(0,120);
      if(!name || name==='.' || name==='..')throw new Error('invalid_download_name');
      const folder=resolve(root,'downloads');
      mkdirSync(folder,{recursive:true,mode:0o700});
      const target=resolve(folder,Date.now()+'-'+name);
      await download.saveAs(target);
      const saved=statSync(target);
      if(saved.size>10_000_000){unlinkSync(target);throw new Error('download_too_large');}
      result({ok:true,action,filename:basename(target),bytes:saved.size});
    } else if (action === 'press') {
      if (!['Enter','Tab','Escape','ArrowUp','ArrowDown','Space'].includes(input.key)) throw new Error('invalid_key');
      await el.press(input.key);
    }
    if (action !== 'download') result({ok:true,action,url:page.url().split('?')[0],title:(await page.title()).slice(0,200)});
  } else throw new Error('unsupported_action');
} catch(e) {
  console.error(JSON.stringify({ok:false,error:String(e.message).slice(0,300)}));
  process.exitCode=1;
} finally {
  if (context) await context.close().catch(()=>{});
}
