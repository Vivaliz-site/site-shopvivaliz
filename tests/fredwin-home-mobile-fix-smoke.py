from __future__ import annotations

import json
import os
import subprocess
from pathlib import Path
from urllib.parse import urlparse

from playwright.sync_api import sync_playwright

BASE = "https://shopvivaliz.com.br/"
MUTATING = {"POST", "PUT", "PATCH", "DELETE"}


def git_show(path: str) -> str:
    return subprocess.check_output(["git", "show", f"FETCH_HEAD:{path}"], text=True, encoding="utf-8")


def chrome_path() -> str:
    for value in (
        r"C:\Program Files\Google\Chrome\Application\chrome.exe",
        r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe",
        os.path.expandvars(r"%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe"),
    ):
        if Path(value).is_file():
            return value
    raise RuntimeError("chrome_missing")


def main() -> None:
    carousel_js = git_show("js/auto-image-carousel.js")
    home_css = git_show("css/home-mobile-corrections-v1.css")
    liz_js = git_show("public/assets/liz-assistant/liz-assistant-corrections-v1.js")
    liz_css = git_show("public/assets/liz-assistant/liz-assistant-corrections-v1.css")
    blocked: list[dict[str, str]] = []

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=chrome_path(), headless=True)
        context = browser.new_context(viewport={"width": 390, "height": 844}, is_mobile=True, has_touch=True, locale="pt-BR", service_workers="block")

        def route_handler(route, request):
            method = request.method.upper()
            if method in MUTATING:
                blocked.append({"method": method, "path": urlparse(request.url).path[:140]})
                route.abort("blockedbyclient")
            else:
                route.continue_()

        context.route("**/*", route_handler)
        page = context.new_page()
        response = page.goto(BASE, wait_until="domcontentloaded", timeout=45000)
        page.wait_for_timeout(3500)

        # Consentimento é apenas ocultado no DOM de teste; nenhuma preferência é gravada.
        page.evaluate("document.querySelector('#sv-privacy-consent')?.remove()")
        page.add_style_tag(content=home_css)
        page.add_style_tag(content=liz_css)
        page.add_script_tag(content=liz_js)

        # Desliga os timers legados ao substituir os wrappers, preservando o DOM e data-images.
        page.evaluate(
            """
            () => document.querySelectorAll('.category-slide-image-wrapper[data-images]').forEach(old => {
              const clone=old.cloneNode(true); old.replaceWith(clone);
            })
            """
        )
        page.add_script_tag(content=carousel_js)
        first = page.locator('.category-slide-image-wrapper[data-images]').first
        first.scroll_into_view_if_needed(timeout=10000)
        page.wait_for_timeout(500)

        before = page.evaluate("() => [...document.querySelectorAll('.category-slide-image-wrapper[data-images]')].slice(0,2).map(w=>w.querySelector('img')?.src||'')")
        page.wait_for_timeout(3400)
        after = page.evaluate("() => [...document.querySelectorAll('.category-slide-image-wrapper[data-images]')].slice(0,2).map(w=>w.querySelector('img')?.src||'')")

        liz = page.evaluate(
            """
            () => {
              const launcher=document.querySelector('#sv-liz-launcher');
              const img=launcher?.querySelector('img');
              const nav=document.querySelector('.sv-mobile-nav-bar');
              const wa=document.querySelector('.sv-support-dock');
              const rect=e=>{if(!e)return null;const r=e.getBoundingClientRect();return {x:r.x,y:r.y,w:r.width,h:r.height,bottom:r.bottom,right:r.right}};
              return {src:img?.getAttribute('src')||'',alt:img?.alt||'',launcher:rect(launcher),nav:rect(nav),whatsapp:rect(wa)};
            }
            """
        )

        page.locator('#sv-liz-launcher').click(force=True)
        page.wait_for_timeout(300)
        dialog = page.evaluate(
            """
            () => ({
              open:document.querySelector('#sv-liz-panel')?.classList.contains('open')||false,
              official:Boolean(document.querySelector('#sv-liz-panel .sv-liz-official-brand img[src*="logo-oficial.svg"]')),
              oldVideo:Boolean(document.querySelector('#sv-liz-panel .sv-hero video')),
              width:document.querySelector('#sv-liz-panel')?.getBoundingClientRect().width||0
            })
            """
        )

        result = {
            "http": response.status if response else 0,
            "category_before": before,
            "category_after": after,
            "category_changed": bool(before and after and before[0] != after[0]),
            "category_source_real": bool(after and "/public/assets/category-images/" not in after[0]),
            "liz": liz,
            "dialog": dialog,
            "no_horizontal_overflow": page.evaluate("document.documentElement.scrollWidth <= innerWidth"),
            "blocked_mutations": blocked,
        }
        print(json.dumps(result, ensure_ascii=True))

        if not result["category_changed"]:
            raise SystemExit("category_did_not_rotate")
        if not result["category_source_real"]:
            raise SystemExit("category_used_non_product_art")
        if "logo-oficial.svg" not in liz["src"]:
            raise SystemExit("liz_launcher_not_official")
        if not dialog["open"] or not dialog["official"] or dialog["oldVideo"]:
            raise SystemExit("liz_dialog_not_corrected")
        if not result["no_horizontal_overflow"]:
            raise SystemExit("horizontal_overflow")

        context.close()
        browser.close()


if __name__ == "__main__":
    main()
