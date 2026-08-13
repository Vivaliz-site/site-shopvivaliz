import base64,json,os
from datetime import datetime,timezone
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright,TimeoutError as T
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
 s=0;slow=False
 try:
  r=p.goto(B+u,wait_until='commit',timeout=20000);s=r.status if r else 0
 except T:slow=True
 return s,slow or settle(p,ms)
def again(p,ms):
 slow=False
 try:p.reload(wait_until='commit',timeout=20000)
 except T:slow=True
 return slow or settle(p,ms)
def auth(p):
 try:x=p.evaluate("""()=>{let t=(document.body?.innerText||'').toLowerCase(),g=[...document.querySelectorAll('a,button')].some(n=>/entrar com google/i.test(n.textContent||''));return{path:location.pathname,markers:document.querySelectorAll('body.admin-surface,.admin-overview,.ais-wrap,#iv-list,.sv-effective-toolbar').length,login:!!document.querySelector('input[type=password]')||g||t.includes('acesse sua conta')}}""")
 except T:return {'path':path(p.url),'markers':0,'login':False,'authenticated':False,'timeout':True}
 x['authenticated']=x['path'].startswith('/admin') and x['markers']>0 and not x['login'];return x
def tap(q):
 if not q.count():return False
 try:q.first.click(timeout=4500);return True
 except T:
  try:q.first.evaluate('e=>e.click()');return True
  except Exception:return False
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
 if a.get('timeout'):
  slow=again(p,1800) or slow;a=auth(p)
 if not a['authenticated']:return {'authenticated':False,'path':a['path'],'status':s,'navigation_slow':slow}
 try:p.wait_for_selector('details.sv-admin-card-details',timeout=9000)
 except T:pass
 x=p.evaluate("""()=>{let d=[...document.querySelectorAll('details.sv-admin-card-details')],k=document.querySelector('#sv-admin-mobile-dock');return{details:d.length,dock:!!(k&&getComputedStyle(k).display!='none'&&k.getBoundingClientRect().height>1),paths:k?[...k.querySelectorAll('a')].map(a=>new URL(a.href,location.href).pathname):[],actions:[...document.querySelectorAll('#sv-admin-section-actions button')].map(b=>(b.textContent||'').trim()),padding:parseFloat(getComputedStyle(document.body).paddingBottom)||0,overflow:Math.max(0,document.documentElement.scrollWidth-innerWidth)}}""")
 op=cl=sv=False
 if x['details']:
  o=p.get_by_role('button',name='Abrir seções');c=p.get_by_role('button',name='Recolher seções')
  if tap(o):p.wait_for_timeout(180);op=p.evaluate("()=>[...document.querySelectorAll('details.sv-admin-card-details')].every(d=>d.open)")
  if tap(c):p.wait_for_timeout(180);cl=p.evaluate("()=>[...document.querySelectorAll('details.sv-admin-card-details')].every(d=>!d.open)")
  k=p.evaluate("""()=>{let d=document.querySelector('details.sv-admin-card-details');if(!d)return'';d.querySelector('summary')?.click();return d.dataset.sectionKey||''}""")
  if k:
   p.wait_for_timeout(250);slow=again(p,1800) or slow
   try:p.wait_for_function("k=>[...document.querySelectorAll('details.sv-admin-card-details')].some(d=>d.dataset.sectionKey===k&&d.open)",k,timeout=9000);sv=True
   except T:pass
 e={'/admin/ai-image-studio/admin_dashboard.php','/admin/catalog-optimization/admin_catalog.php','/admin/produtos.php','/admin/pedidos.php'}
 return {'authenticated':True,'status':s,'navigation_slow':slow,'details':x['details'],'opened':op,'closed':cl,'saved':sv,'actions':{'Abrir seções','Recolher seções'}<=set(x['actions']),'dock':x['dock'] and e<=set(x['paths']),'padding':x['padding'],'overflow':x['overflow']}
def catalog(p):
 s,slow=nav(p,'/admin/catalog-optimization/admin_catalog.php',2400);a=auth(p)
 if a.get('timeout'):
  slow=again(p,1800) or slow;a=auth(p)
 if not a['authenticated']:return {'authenticated':False,'path':a['path'],'status':s,'navigation_slow':slow}
 p.evaluate("""()=>{let t=document.querySelector('#sv-effective-toolbar');if(!t){t=document.createElement('div');t.id='sv-effective-toolbar';t.innerHTML='<span id=sv-effective-visible-count></span>';document.body.prepend(t)}let h=document.querySelector('#sv-fredwin-sort-fixture');if(!h){h=document.createElement('section');h.id='sv-fredwin-sort-fixture';document.body.append(h)}h.innerHTML='<article class="sv-review-card is-ready" data-sv-state="ready" data-effective-loaded="1" data-effective-count="1" data-sv-search="site Alpha"><input type=hidden name=staging_id value=990001><strong>Alpha</strong></article><article class="sv-review-card" data-sv-search="mercado livre Beta"><input type=hidden name=staging_id value=990002><strong>Beta</strong></article><article class="sv-review-card has-failure" data-sv-state="fail" data-sv-search="shopee Zeta"><input type=hidden name=staging_id value=990003><strong>Zeta</strong></article>';let q=document.createElement('script');q.src='/admin/assets/admin-mobile-completion.js?smoke='+Date.now();document.head.append(q)}""")
 try:p.wait_for_selector('#sv-effective-sort',timeout=9000)
 except T:pass
 q=p.locator('#sv-effective-sort');v=q.count()>0 and q.first.is_visible();o=pr=ur=[]
 if v:
  o=q.first.locator('option').evaluate_all('e=>e.map(x=>x.value)');js="()=>[...document.querySelectorAll('#sv-fredwin-sort-fixture article')].map(a=>a.querySelector('strong')?.textContent.trim())"
  q.first.select_option('product');p.wait_for_timeout(220);pr=p.evaluate(js);q.first.select_option('urgent');p.wait_for_timeout(220);ur=p.evaluate(js)
 return {'authenticated':True,'status':s,'navigation_slow':slow,'sort':v,'options':{'recent','urgent','channel','status','product'}<=set(o),'product':pr==['Alpha','Beta','Zeta'],'urgent':ur==['Zeta','Beta','Alpha'],'overflow':p.evaluate('()=>Math.max(0,document.documentElement.scrollWidth-innerWidth)')}
def image(p):
 s,slow=nav(p,'/admin/ai-image-studio/admin_dashboard.php',3000);a=auth(p)
 if a.get('timeout'):
  slow=again(p,1800) or slow;a=auth(p)
 if not a['authenticated']:return {'authenticated':False,'path':a['path'],'status':s,'navigation_slow':slow}
 try:p.wait_for_selector('.iv-item[data-product-id]',timeout=12000)
 except T:pass
 x=p.evaluate("""()=>({items:document.querySelectorAll('.iv-item[data-product-id]').length,products:document.querySelectorAll('.iv-item>summary input.iv-check').length,variants:document.querySelectorAll('.iv-variant input.iv-check').length})""")
 z=p.evaluate("""()=>{for(let i of document.querySelectorAll('.iv-item[data-product-id]')){let p=i.querySelector(':scope>summary input.iv-check:not(:disabled)'),v=i.querySelector('.iv-variant input.iv-check:not(:disabled)');if(!p||!v)continue;p.checked=true;p.dispatchEvent(new Event('change',{bubbles:true}));v.checked=true;v.dispatchEvent(new Event('change',{bubbles:true}));let c=document.querySelector('#iv-channel')?.value||'site';return{id:String(i.dataset.productId||''),type:String(v.value||''),key:'svImageDraftV3:'+c}}return null}""")
 p.wait_for_timeout(350);sa=sh=re=False
 if z:
  sa=p.evaluate("x=>{let d=JSON.parse(localStorage.getItem(x.key)||'{}'),r=d?.products?.[x.id];return !!(r?.selected&&r?.types?.includes(x.type))}",z);r=p.locator('#iv-run')
  if r.count():
   try:r.first.click(timeout=4000)
   except:pass
   p.wait_for_timeout(350);sh=p.locator('#sv-img-preflight.show').count()>0;c=p.locator('#sv-img-preflight .sv-img-cancel')
   if c.count():c.first.click()
  slow=again(p,2700) or slow;re=p.evaluate("x=>{let i=document.querySelector('.iv-item[data-product-id=\"'+CSS.escape(x.id)+'\"]');if(!i)return false;let p=i.querySelector(':scope>summary input.iv-check'),v=[...i.querySelectorAll('.iv-variant input.iv-check')].find(n=>n.value===x.type);return !!(p?.checked&&v?.checked)}",z)
 return {'authenticated':True,'status':s,'navigation_slow':slow,**x,'eligible':bool(z),'preflight':p.locator('#sv-img-preflight').count()>0,'shown':sh,'saved':sa,'restored':re,'actionbar':p.locator('.iv-actionbar').count()>0,'overflow':p.evaluate('()=>Math.max(0,document.documentElement.scrollWidth-innerWidth)')}
def main():
 ch=chrome();sid=os.environ.get('SV_ADMIN_SESSION_ID','');sn=os.environ.get('SV_ADMIN_SESSION_NAME','PHPSESSID');r={'schema':3,'started_at':ts(),'login':{},'session_source':'ephemeral-audit-session','authenticated_profile':None,'home':{},'catalog':{},'image':{},'blocked_mutations':[],'errors':[]}
 if not sid or not sn:emit({**r,'overall':False,'failures':['ephemeral_session_missing'],'finished_at':ts()},8)
 with sync_playwright() as w:
  r['login']=login(w,ch);b=w.chromium.launch(executable_path=ch,headless=True);c=b.new_context(viewport={'width':390,'height':844},is_mobile=True,has_touch=True,locale='pt-BR',timezone_id='America/Sao_Paulo',service_workers="block");bl=[];guard(c,bl);c.add_cookies([{'name':sn,'value':sid,'url':B,'httpOnly':True,'secure':True,'sameSite':'Lax'}]);p=c.new_page();p.set_default_timeout(11000);stage='home'
  try:
   h=home(p);r['home']=h
   if h.get('authenticated'):
    r['authenticated_profile']='ephemeral-audit-session';stage='catalog';r['catalog']=catalog(p);stage='image';r['image']=image(p)
   r['blocked_mutations']=bl[:20]
  except Exception as e:r['errors'].append({'stage':stage,'type':type(e).__name__,'path':path(p.url)})
  finally:c.close();b.close()
 l=r['login'];h=r['home'];c=r['catalog'];m=r['image'];checks={'login_google':l.get('google_visible') and l.get('google_canonical'),'authenticated_profile':bool(r['authenticated_profile']),'home':all((h.get('authenticated'),h.get('details',0)>0,h.get('opened'),h.get('closed'),h.get('saved'),h.get('actions'),h.get('dock'),h.get('padding',0)>=70,h.get('overflow',999)<=4)),'catalog':all((c.get('authenticated'),c.get('sort'),c.get('options'),c.get('product'),c.get('urgent'),c.get('overflow',999)<=4)),'image':all((m.get('authenticated'),m.get('items',0)>0,m.get('products',0)>0,m.get('variants',0)>0,m.get('eligible'),m.get('preflight'),m.get('shown'),m.get('saved'),m.get('restored'),m.get('actionbar'),m.get('overflow',999)<=4))};r['failures']=[k for k,v in checks.items() if not v];r['overall']=not r['failures'];r['finished_at']=ts();emit(r,0 if r['overall'] else 7)
if __name__=='__main__':
 try:main()
 except SystemExit:raise
 except Exception as e:emit({'schema':3,'overall':False,'failures':['top_exception'],'errors':[type(e).__name__],'finished_at':ts()},9)
