# Read-only Fred-Win Admin mobile smoke; verifies repaired Playwright arg handling and navbar metrics.
import base64,json,os
from datetime import datetime,timezone
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright,TimeoutError as T,Error as PError
B='https://shopvivaliz.com.br'
MUTATING_METHODS={'POST','PUT','PATCH','DELETE'}
G=('/admin/sync-','/admin/reparar-','/admin/force-','/admin/excluir','/admin/publicar')
def ts():return datetime.now(timezone.utc).isoformat()
def path(u):return urlparse(u).path or '/'
def emit(x,n):
 print('SV_ADMIN_SMOKE_B64='+base64.b64encode(json.dumps(x,ensure_ascii=False,separators=(',',':')).encode()).decode());raise SystemExit(n)
def chrome():
 for p in (r'C:\Program Files\Google\Chrome\Application\chrome.exe',r'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',os.path.expandvars(r'%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe')):
  if Path(p).is_file():return p
 raise FileNotFoundError('chrome_missing')
def guard(c,blocked):
 def h(r,q):
  m=q.method.upper();p=path(q.url)
  if m in MUTATING_METHODS or (m=='GET' and any(x in p.lower() for x in G)):
   blocked.append({'method':m,'path':p[:160]});r.abort('blockedbyclient')
  else:r.continue_()
 c.route('**/*',h)
def settle(p,ms):
 slow=False
 try:p.wait_for_load_state('domcontentloaded',timeout=12000)
 except T:slow=True
 try:p.wait_for_selector('body',state='attached',timeout=6000)
 except T:slow=True
 p.wait_for_timeout(ms);return slow
def nav(p,u,ms=2300):
 s=0;slow=False;target=B+u
 for attempt in range(2):
  try:
   r=p.goto(target,wait_until='commit',timeout=20000);s=r.status if r else 0;break
  except (T,PError):
   slow=True
   if attempt==0:p.wait_for_timeout(600)
 return s,slow or settle(p,ms)
def again(p,ms):
 slow=False
 try:p.reload(wait_until='commit',timeout=20000)
 except (T,PError):slow=True
 return slow or settle(p,ms)
def auth(p):
 x=p.evaluate("""()=>{let t=(document.body?.innerText||'').toLowerCase(),g=[...document.querySelectorAll('a,button')].some(n=>/entrar com google/i.test(n.textContent||''));return{path:location.pathname,markers:document.querySelectorAll('body.admin-surface,.admin-overview,.ais-wrap,#iv-list,.sv-effective-toolbar').length,login:!!document.querySelector('input[type=password]')||g||t.includes('acesse sua conta')}}""")
 x['authenticated']=x['path'].startswith('/admin') and x['markers']>0 and not x['login'];return x
def login(w,ch):
 b=w.chromium.launch(executable_path=ch,headless=True);c=b.new_context(viewport={'width':390,'height':844},service_workers="block");z=[];guard(c,z);p=c.new_page()
 try:
  s,slow=nav(p,'/auth/login.php',900);x=p.evaluate("""()=>{let n=[...document.querySelectorAll('a,button')].find(x=>/entrar com google/i.test(x.textContent||'')),r=n?.getBoundingClientRect();return{v:!!(n&&r&&r.width>1&&r.height>1),h:n?.getAttribute?.('href')||''}}""");return {'status':s,'navigation_slow':slow,'google_visible':x['v'],'google_canonical':path(x['h'])=='/auth/google-start.php'}
 finally:c.close();b.close()
def home(p):
 s,slow=nav(p,'/admin/',2500)
 try:p.wait_for_selector('body.admin-surface',timeout=12000)
 except T:pass
 a=auth(p)
 if not a['authenticated']:return {'authenticated':False,'path':a['path'],'status':s,'navigation_slow':slow}
 try:p.wait_for_selector('details.sv-admin-card-details',timeout=9000)
 except T:pass
 x=p.evaluate("""()=>{let d=[...document.querySelectorAll('details.sv-admin-card-details')],k=document.querySelector('#sv-admin-mobile-dock'),menu=document.querySelector('.navbar-menu'),nav=document.querySelector('.navbar'),main=document.querySelector('main.catalog-page'),overview=document.querySelector('.admin-overview'),mc=menu?getComputedStyle(menu):null,nc=nav?getComputedStyle(nav):null,mr=main?.getBoundingClientRect(),or=overview?.getBoundingClientRect(),rgb=(nc?.backgroundColor||'').match(/[\d.]+/g)?.map(Number)||[],css=document.querySelector('link[href*="/css/admin-zoom-responsive.css"]')?.getAttribute('href')||'',mw=menu?.getBoundingClientRect().height||0,borders=mc?[mc.borderTopWidth,mc.borderRightWidth,mc.borderBottomWidth,mc.borderLeftWidth].map(parseFloat):[99];return{details:d.length,dock:!!(k&&getComputedStyle(k).display!='none'&&k.getBoundingClientRect().height>1),paths:k?[...k.querySelectorAll('a')].map(a=>new URL(a.href,location.href).pathname):[],actions:[...document.querySelectorAll('#sv-admin-section-actions button')].map(b=>(b.textContent||'').trim()),padding:parseFloat(getComputedStyle(document.body).paddingBottom)||0,overflow:Math.max(0,document.documentElement.scrollWidth-innerWidth),mobile_nav:{flex_direction:mc?.flexDirection||'',menu_background:mc?.backgroundColor||'',menu_transparent:(mc?.backgroundColor==='rgba(0, 0, 0, 0)'||mc?.backgroundColor==='transparent'),nav_background:nc?.backgroundColor||'',nav_dark:rgb.length>=3&&Math.max(rgb[0],rgb[1],rgb[2])<100,borderless:borders.every(v=>Number.isFinite(v)&&v===0),shadowless:(mc?.boxShadow||'none')==='none',height:mw,height_reasonable:mw>=32&&mw<=64,viewport_width:innerWidth,main_width:mr?.width||0,overview_width:or?.width||0,main_not_compressed:!!(mr&&or&&mr.width>=innerWidth-4&&or.width>=innerWidth-24&&or.left<=12),css_href:css,css_cache_busted:/admin-zoom-responsive\.css\?v=[^&]+/.test(css)}}}""")
 n=p.evaluate("""()=>{let nav=document.querySelector('.navbar'),menu=document.querySelector('.navbar-menu'),overview=document.querySelector('.admin-overview'),ns=nav?getComputedStyle(nav):null,ms=menu?getComputedStyle(menu):null;return{menuFlex:ms?.flexDirection||'',menuBg:ms?.backgroundColor||'',menuShadow:ms?.boxShadow||'',navBg:ns?.backgroundColor||'',navShadow:ns?.boxShadow||'',height:nav?nav.getBoundingClientRect().height:0,contentWidth:overview?overview.getBoundingClientRect().width:0,viewportWidth:innerWidth}}""")
 x['navbar']=n
 op=cl=sv=False
 if x['details']:
  o=p.get_by_role('button',name='Abrir seções');c=p.get_by_role('button',name='Recolher seções')
  if o.count():o.first.click();p.wait_for_timeout(180);op=p.evaluate("()=>[...document.querySelectorAll('details.sv-admin-card-details')].every(d=>d.open)")
  if c.count():c.first.click();p.wait_for_timeout(180);cl=p.evaluate("()=>[...document.querySelectorAll('details.sv-admin-card-details')].every(d=>!d.open)")
  k=p.evaluate("""()=>{let d=document.querySelector('details.sv-admin-card-details');if(!d)return'';d.querySelector('summary')?.click();return d.dataset.sectionKey||''}""")
  if k:
   p.wait_for_timeout(250);slow=again(p,1800) or slow
   try:p.wait_for_function("k=>[...document.querySelectorAll('details.sv-admin-card-details')].some(d=>d.dataset.sectionKey===k&&d.open)",arg=k,timeout=9000);sv=True
   except T:pass
 e={'/admin/ai-image-studio/admin_dashboard.php','/admin/catalog-optimization/admin_catalog.php','/admin/produtos.php','/admin/pedidos.php'}
 return {'authenticated':True,'status':s,'navigation_slow':slow,'details':x['details'],'opened':op,'closed':cl,'saved':sv,'actions':{'Abrir seções','Recolher seções'}<=set(x['actions']),'dock':x['dock'] and e<=set(x['paths']),'padding':x['padding'],'overflow':x['overflow'],'mobile_nav':x['mobile_nav'],'navbar':x.get('navbar',{})}
def catalog(p):
 s,slow=nav(p,'/admin/catalog-optimization/admin_catalog.php',2400);a=auth(p)
 if not a['authenticated']:return {'authenticated':False,'path':a['path'],'status':s,'navigation_slow':slow}
 p.evaluate("""()=>{let t=document.querySelector('#sv-effective-toolbar');if(!t){t=document.createElement('div');t.id='sv-effective-toolbar';t.innerHTML='<span id=sv-effective-visible-count></span>';document.body.prepend(t)}let h=document.querySelector('#sv-fredwin-sort-fixture');if(!h){h=document.createElement('section');h.id='sv-fredwin-sort-fixture';document.body.append(h)}h.innerHTML='<article class="sv-review-card is-ready" data-sv-state="ready" data-effective-loaded="1" data-effective-count="1" data-sv-search="site Alpha"><input type=hidden name=staging_id value=990001><div class="source-title"><strong>Alpha</strong></div></article><article class="sv-review-card" data-sv-search="mercado livre Beta"><input type=hidden name=staging_id value=990002><div class="source-title"><strong>Beta</strong></div></article><article class="sv-review-card has-failure" data-sv-state="fail" data-sv-search="shopee Zeta"><input type=hidden name=staging_id value=990003><div class="source-title"><strong>Zeta</strong></div></article>';let q=document.createElement('script');q.src='/admin/assets/admin-mobile-completion.js?smoke='+Date.now();document.head.append(q)}""")
 try:p.wait_for_selector('#sv-effective-sort',timeout=9000)
 except T:pass
 q=p.locator('#sv-effective-sort');v=q.count()>0 and q.first.is_visible();o=pr=ur=[]
 if v:
  o=q.first.locator('option').evaluate_all('e=>e.map(x=>x.value)');js="()=>[...document.querySelectorAll('#sv-fredwin-sort-fixture article')].map(a=>a.querySelector('strong')?.textContent.trim())"
  q.first.select_option('product');p.wait_for_timeout(220);pr=p.evaluate(js);q.first.select_option('urgent');p.wait_for_timeout(220);ur=p.evaluate(js)
 return {'authenticated':True,'status':s,'navigation_slow':slow,'sort':v,'options':{'recent','urgent','channel','status','product'}<=set(o),'product':pr==['Alpha','Beta','Zeta'],'urgent':ur==['Zeta','Beta','Alpha'],'overflow':p.evaluate('()=>Math.max(0,document.documentElement.scrollWidth-innerWidth)')}
def image(p):
 s,slow=nav(p,'/admin/ai-image-studio/admin_dashboard.php',2200);a=auth(p)
 if not a['authenticated']:return {'authenticated':False,'path':a['path'],'status':s,'navigation_slow':slow}
 base=p.evaluate("""()=>({wrap:document.querySelectorAll('.ais-wrap').length>0,form:document.querySelectorAll('.ais-form form[method=get]').length>0,target:!!document.querySelector('#target_channel'),provider:!!document.querySelector('#provider'),model:!!document.querySelector('#model'),pageSize:!!document.querySelector('#page_size'),overflow:Math.max(0,document.documentElement.scrollWidth-innerWidth)})
""")
 ps,pslow=nav(p,'/admin/ai-image-studio/admin_dashboard.php?preview=1&target_channel=site&provider=openai&model=gpt-image-1&page_size=25',3200);slow=slow or pslow;pa=auth(p)
 try:p.wait_for_selector('.ais-preview-list,.ais-alert.note',timeout=12000)
 except T:pass
 preview=p.evaluate("""()=>({items:document.querySelectorAll('.ais-preview-item').length,checks:document.querySelectorAll('[data-product-check]').length,list:document.querySelectorAll('.ais-preview-list').length>0,emptyNote:[...document.querySelectorAll('.ais-alert.note')].some(n=>/nenhum produto pendente/i.test(n.textContent||'')),selectAll:!!document.querySelector('#ais-select-all'),clearAll:!!document.querySelector('#ais-clear-all'),submit:!!document.querySelector('#ais-submit'),overflow:Math.max(0,document.documentElement.scrollWidth-innerWidth)})
""")
 selection=True
 if preview['checks']>0:
  selection=False
  sa=p.locator('#ais-select-all');ca=p.locator('#ais-clear-all')
  if sa.count() and ca.count():
   sa.first.click();p.wait_for_timeout(160)
   picked=p.evaluate("()=>[...document.querySelectorAll('[data-product-check]')].every(x=>x.checked)&&!document.querySelector('#ais-submit')?.disabled")
   ca.first.click();p.wait_for_timeout(160)
   cleared=p.evaluate("()=>[...document.querySelectorAll('[data-product-check]')].every(x=>!x.checked)&&!!document.querySelector('#ais-submit')?.disabled")
   selection=bool(picked and cleared)
 previewReady=bool((preview['checks']>0 and preview['list'] and preview['selectAll'] and preview['clearAll'] and preview['submit'] and selection) or preview['emptyNote'])
 return {'authenticated':True,'status':s,'navigation_slow':slow,'wrap':base['wrap'],'form':base['form'],'fields':all((base['target'],base['provider'],base['model'],base['pageSize'])),'preview_authenticated':pa.get('authenticated',False),'preview_status':ps,'preview_items':preview['items'],'preview_checks':preview['checks'],'preview_ready':previewReady,'selection_local':selection,'overflow':max(base['overflow'],preview['overflow'])}
def main():
 ch=chrome();sid=os.environ.get('SV_ADMIN_SESSION_ID','');sn=os.environ.get('SV_ADMIN_SESSION_NAME','PHPSESSID');r={'schema':4,'started_at':ts(),'login':{},'session_source':'ephemeral-audit-session','authenticated_profile':None,'home':{},'catalog':{},'image':{},'blocked_mutations':[],'errors':[]}
 if not sid or not sn:emit({**r,'overall':False,'failures':['ephemeral_session_missing'],'finished_at':ts()},8)
 with sync_playwright() as w:
  r['login']=login(w,ch);b=w.chromium.launch(executable_path=ch,headless=True);c=b.new_context(viewport={'width':390,'height':844},is_mobile=True,has_touch=True,locale='pt-BR',timezone_id='America/Sao_Paulo',service_workers="block");bl=[];guard(c,bl);c.add_cookies([{'name':sn,'value':sid,'url':B,'httpOnly':True,'secure':True,'sameSite':'Lax'}]);p=c.new_page();p.set_default_timeout(11000);stage='home';active=p
  try:
   h=home(p);r['home']=h
   if h.get('authenticated'):
    r['authenticated_profile']='ephemeral-audit-session';stage='catalog';r['catalog']=catalog(p);stage='image';active=c.new_page();active.set_default_timeout(11000);r['image']=image(active)
   r['blocked_mutations']=bl[:20]
  except Exception as e:r['errors'].append({'stage':stage,'type':type(e).__name__,'path':path(active.url),'message':str(e)[:220]})
  finally:
   if active is not p:
    try:active.close()
    except:pass
   c.close();b.close()
 l=r['login'];h=r['home'];c=r['catalog'];m=r['image'];mn=h.get('mobile_nav',{});nb=h.get('navbar') or {};checks={'login_google':l.get('google_visible') and l.get('google_canonical'),'authenticated_profile':bool(r['authenticated_profile']),'admin_load':h.get('status')==200 and not h.get('navigation_slow',True),'home':all((h.get('authenticated'),h.get('details',0)>0,h.get('opened'),h.get('closed'),h.get('saved'),h.get('actions'),h.get('dock'),h.get('padding',0)>=70,h.get('overflow',999)<=4)),'admin_mobile_nav':all((mn.get('flex_direction')=='row',mn.get('menu_transparent'),mn.get('nav_dark'),mn.get('borderless'),mn.get('shadowless'),mn.get('height_reasonable'),mn.get('main_not_compressed'),mn.get('css_cache_busted'))),'navbar_mobile':all((nb.get('menuFlex')=='row',nb.get('menuBg') in ('rgba(0, 0, 0, 0)','transparent'),nb.get('menuShadow')=='none',nb.get('navBg')=='rgb(17, 24, 39)',nb.get('navShadow')=='none',0<nb.get('height',999)<=60,nb.get('viewportWidth')==390,nb.get('contentWidth',0)>=360)),'catalog':all((c.get('authenticated'),c.get('sort'),c.get('options'),c.get('product'),c.get('urgent'),c.get('overflow',999)<=4)),'image':all((m.get('authenticated'),m.get('status')==200,m.get('wrap'),m.get('form'),m.get('fields'),m.get('preview_authenticated'),m.get('preview_status')==200,m.get('preview_ready'),m.get('selection_local'),m.get('overflow',999)<=4)),'errors':not r['errors']};r['checks']=checks;r['failures']=[k for k,v in checks.items() if not v];r['overall']=not r['failures'];r['finished_at']=ts();emit(r,0 if r['overall'] else 7)
if __name__=='__main__':
 try:main()
 except SystemExit:raise
 except Exception as e:emit({'schema':4,'overall':False,'failures':['top_exception'],'errors':[type(e).__name__],'finished_at':ts()},9)
