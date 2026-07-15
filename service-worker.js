const CACHE='shopvivaliz-shell-v20';
const SHELL=['/','/catalogo','/css/shopvivaliz-visual-v3.css','/images/logo-vivaliz-square.png'];
self.addEventListener('install',event=>{event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(SHELL)).catch(()=>null));self.skipWaiting();});
self.addEventListener('activate',event=>{event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key!==CACHE).map(key=>caches.delete(key)))));self.clients.claim();});
self.addEventListener('fetch',event=>{
  const request=event.request;
  if(request.method!=='GET')return;
  const url=new URL(request.url);
  if(url.origin!==location.origin)return;
  if(url.pathname.startsWith('/api/')||url.pathname.startsWith('/admin/')||url.pathname.startsWith('/checkout'))return;
  event.respondWith(fetch(request).then(response=>{
    const copy=response.clone();
    if(response.ok&&['style','script','image','font'].includes(request.destination))caches.open(CACHE).then(cache=>cache.put(request,copy));
    return response;
  }).catch(()=>caches.match(request).then(hit=>hit||caches.match('/'))));
});
