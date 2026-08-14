from __future__ import annotations

import json
import os
from pathlib import Path
from urllib.parse import urlparse

from playwright.sync_api import sync_playwright

BASE = "https://shopvivaliz.com.br/"
MUTATING = {"POST", "PUT", "PATCH", "DELETE"}
DANGEROUS_GET = ("/admin/sync-", "/admin/reparar-", "/admin/force-", "/admin/excluir", "/admin/publicar")


def chrome_path() -> str:
    candidates = [
        r"C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe",
        r"C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe",
        os.path.expandvars(r"%LOCALAPPDATA%\\Google\\Chrome\\Application\\chrome.exe"),
    ]
    for candidate in candidates:
        if Path(candidate).is_file():
            return candidate
    raise RuntimeError("chrome_missing")


def main() -> None:
    blocked: list[dict[str, str]] = []
    console_errors: list[str] = []
    page_errors: list[str] = []

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=chrome_path(), headless=True)
        context = browser.new_context(
            viewport={"width": 390, "height": 844},
            device_scale_factor=1,
            is_mobile=True,
            has_touch=True,
            locale="pt-BR",
            service_workers="block",
        )

        def route_handler(route, request):
            method = request.method.upper()
            path = urlparse(request.url).path.lower()
            if method in MUTATING or (method == "GET" and any(token in path for token in DANGEROUS_GET)):
                blocked.append({"method": method, "path": path[:160]})
                route.abort("blockedbyclient")
                return
            route.continue_()

        context.route("**/*", route_handler)
        page = context.new_page()
        page.on("console", lambda msg: console_errors.append(msg.text[:300]) if msg.type == "error" else None)
        page.on("pageerror", lambda error: page_errors.append(str(error)[:300]))
        response = page.goto(BASE, wait_until="domcontentloaded", timeout=45000)
        page.wait_for_timeout(4500)

        base = page.evaluate(
            """
            () => {
              const rect = el => {
                const r = el.getBoundingClientRect();
                return {x:Math.round(r.x),y:Math.round(r.y),w:Math.round(r.width),h:Math.round(r.height),bottom:Math.round(r.bottom),right:Math.round(r.right)};
              };
              const clean = value => String(value || '').replace(/\s+/g,' ').trim().slice(0,180);
              const fixed = [...document.querySelectorAll('body *')].filter(el => {
                const s=getComputedStyle(el); const r=el.getBoundingClientRect();
                return (s.position==='fixed'||s.position==='sticky') && r.width>10 && r.height>10;
              }).slice(0,80).map(el => ({
                tag:el.tagName.toLowerCase(), id:el.id||'', cls:clean(el.className), text:clean(el.innerText||el.textContent),
                position:getComputedStyle(el).position, z:getComputedStyle(el).zIndex, display:getComputedStyle(el).display,
                rect:rect(el), images:[...el.querySelectorAll('img')].map(img=>({src:img.currentSrc||img.src||'',alt:img.alt||'',cls:clean(img.className)})).slice(0,6)
              }));
              const support = [...document.querySelectorAll('button,a,[role=button],iframe,img,div')].filter(el => {
                const hay=clean([el.id,el.className,el.getAttribute?.('aria-label'),el.getAttribute?.('title'),el.getAttribute?.('alt'),el.textContent].join(' ')).toLowerCase();
                return /(chat|bot|assist|atend|suporte|whats|ajuda|help)/.test(hay);
              }).slice(0,100).map(el => ({
                tag:el.tagName.toLowerCase(),id:el.id||'',cls:clean(el.className),text:clean(el.textContent),aria:el.getAttribute?.('aria-label')||'',title:el.getAttribute?.('title')||'',
                src:el.getAttribute?.('src')||'', href:el.getAttribute?.('href')||'', rect:rect(el),
                image:el.tagName==='IMG'?(el.currentSrc||el.src||''):((el.querySelector?.('img')?.currentSrc)||el.querySelector?.('img')?.src||'')
              }));
              const iframes=[...document.querySelectorAll('iframe')].map(f=>({src:f.src||'',title:f.title||'',name:f.name||'',id:f.id||'',cls:clean(f.className),rect:rect(f)}));
              const cards=[...document.querySelectorAll('.category-slide-image-wrapper')].slice(0,8).map((w,i)=>({
                i, data:w.dataset.images||'', src:w.querySelector('img')?.currentSrc||w.querySelector('img')?.src||'', title:w.closest('.category-slide')?.querySelector('.category-slide-title')?.textContent?.trim()||'', rect:rect(w)
              }));
              const headers=[...document.querySelectorAll('.section-header-container,.section-title-wrapper,.home-section-header')].slice(0,12).map(el=>{
                const before=getComputedStyle(el,'::before'), after=getComputedStyle(el,'::after');
                return {tag:el.tagName.toLowerCase(),id:el.id||'',cls:clean(el.className),text:clean(el.textContent),rect:rect(el),before:{content:before.content,width:before.width,height:before.height,position:before.position,top:before.top,left:before.left,transform:before.transform},after:{content:after.content,width:after.width,height:after.height,position:after.position,top:after.top,left:after.left,transform:after.transform}};
              });
              return {
                path:location.pathname,
                title:document.title,
                viewport:{w:innerWidth,h:innerHeight,scrollWidth:document.documentElement.scrollWidth,scrollHeight:document.documentElement.scrollHeight,overflow:Math.max(0,document.documentElement.scrollWidth-innerWidth)},
                fixed,support,iframes,cards,headers,
                bodyClasses:document.body.className||'',
                scripts:[...document.scripts].map(s=>s.src||'[inline]').filter(Boolean)
              };
            }
            """
        )

        before = page.evaluate("() => [...document.querySelectorAll('.category-slide-image-wrapper')].slice(0,8).map(w=>w.querySelector('img')?.currentSrc||w.querySelector('img')?.src||'')")
        page.wait_for_timeout(3300)
        after = page.evaluate("() => [...document.querySelectorAll('.category-slide-image-wrapper')].slice(0,8).map(w=>w.querySelector('img')?.currentSrc||w.querySelector('img')?.src||'')")
        rotations = []
        for index, start in enumerate(before):
            end = after[index] if index < len(after) else ""
            rotations.append({"index": index, "before": start, "after": end, "changed": bool(start and end and start != end)})

        click_result = {"attempted": False}
        selectors = [
            '[aria-label*="chat" i]', '[title*="chat" i]', '[class*="chat" i]', '[id*="chat" i]',
            '[aria-label*="assist" i]', '[title*="assist" i]', '[class*="assist" i]',
            '[aria-label*="atend" i]', '[title*="atend" i]', '[class*="atend" i]'
        ]
        candidate = None
        for selector in selectors:
            loc = page.locator(selector)
            try:
                count = min(loc.count(), 8)
            except Exception:
                count = 0
            for i in range(count):
                item = loc.nth(i)
                try:
                    if item.is_visible() and item.evaluate("el => ['BUTTON','A'].includes(el.tagName) || el.getAttribute('role')==='button' || getComputedStyle(el).cursor==='pointer'"):
                        candidate = item
                        break
                except Exception:
                    pass
            if candidate is not None:
                break
        if candidate is not None:
            click_result["attempted"] = True
            try:
                click_result["before"] = candidate.evaluate("el=>({tag:el.tagName.toLowerCase(),id:el.id||'',cls:String(el.className||''),text:(el.textContent||'').trim().slice(0,120),aria:el.getAttribute('aria-label')||'',title:el.getAttribute('title')||''})")
                candidate.click(timeout=5000)
                page.wait_for_timeout(900)
                click_result["after"] = page.evaluate(
                    """
                    () => [...document.querySelectorAll('[role=dialog],dialog,[class*=chat],[id*=chat],[class*=assist],[id*=assist]')]
                      .filter(el=>{const r=el.getBoundingClientRect(),s=getComputedStyle(el);return r.width>20&&r.height>20&&s.display!=='none'&&s.visibility!=='hidden'})
                      .slice(0,30).map(el=>({tag:el.tagName.toLowerCase(),id:el.id||'',cls:String(el.className||''),text:(el.innerText||el.textContent||'').replace(/\s+/g,' ').trim().slice(0,220),images:[...el.querySelectorAll('img')].map(img=>({src:img.currentSrc||img.src||'',alt:img.alt||'',cls:String(img.className||'')})).slice(0,10)}))
                    """
                )
            except Exception as exc:
                click_result["error"] = str(exc)[:300]

        result = {
            "http_status": response.status if response else 0,
            "base": base,
            "category_rotations_after_3_3s": rotations,
            "chat_click": click_result,
            "blocked_requests": blocked[:40],
            "console_errors": console_errors[:40],
            "page_errors": page_errors[:40],
        }
        print(json.dumps(result, ensure_ascii=False))
        context.close()
        browser.close()


if __name__ == "__main__":
    main()
