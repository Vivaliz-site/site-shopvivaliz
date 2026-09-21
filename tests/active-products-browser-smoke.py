import base64
import json
import os
from datetime import datetime, timezone
from pathlib import Path

from playwright.sync_api import sync_playwright

BASE = 'https://shopvivaliz.com.br'


def now():
    return datetime.now(timezone.utc).isoformat()


def emit(result, code):
    raw = json.dumps(result, ensure_ascii=False, separators=(',', ':')).encode('utf-8')
    print('SV_ACTIVE_PRODUCTS_BROWSER_B64=' + base64.b64encode(raw).decode('ascii'))
    raise SystemExit(code)


def chrome_path():
    candidates = (
        r'C:\Program Files\Google\Chrome\Application\chrome.exe',
        r'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
        os.path.expandvars(r'%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe'),
    )
    for candidate in candidates:
        if Path(candidate).is_file():
            return candidate
    raise FileNotFoundError('chrome_missing')


def get_json(request, url):
    response = request.get(url, timeout=30000, headers={'Cache-Control': 'no-cache'})
    if not response.ok:
        raise RuntimeError(f'HTTP {response.status} {url}')
    return response.json()


def main():
    sid = os.environ.get('SV_ADMIN_SESSION_ID', '')
    sname = os.environ.get('SV_ADMIN_SESSION_NAME', 'PHPSESSID')
    result = {
        'schema': 1,
        'started_at': now(),
        'catalog_total': 0,
        'catalog_api_skus': 0,
        'catalog_ui_skus': [],
        'pending_count': 0,
        'pending_offenders': [],
        'ui_offenders': [],
        'authenticated_pending': False,
        'failures': [],
    }
    if not sid or not sname:
        result['failures'].append('ephemeral_session_missing')
        result['finished_at'] = now()
        emit(result, 8)

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=chrome_path(), headless=True)
        context = browser.new_context(
            viewport={'width': 390, 'height': 844},
            locale='pt-BR',
            timezone_id='America/Sao_Paulo',
            service_workers='block',
        )
        context.add_cookies([{
            'name': sname,
            'value': sid,
            'url': BASE,
            'httpOnly': True,
            'secure': True,
            'sameSite': 'Lax',
        }])
        page = context.new_page()
        page.set_default_timeout(15000)

        try:
            first = get_json(context.request, BASE + '/api/catalog/products.php?limit=200&page=1&no_cache=1')
            if first.get('ok') is not True or first.get('source') != 'catalog_runtime':
                result['failures'].append('catalog_api_not_canonical')
            total_pages = max(1, int(first.get('total_pages') or 1))
            result['catalog_total'] = int(first.get('total') or 0)
            products = list(first.get('products') or [])
            for page_no in range(2, total_pages + 1):
                payload = get_json(context.request, BASE + f'/api/catalog/products.php?limit=200&page={page_no}&no_cache=1')
                products.extend(payload.get('products') or [])

            active_skus = {
                str(product.get('sku') or '').strip()
                for product in products
                if str(product.get('sku') or '').strip()
            }
            result['catalog_api_skus'] = len(active_skus)
            if not active_skus:
                result['failures'].append('active_catalog_empty')

            response = page.goto(BASE + '/catalogo', wait_until='domcontentloaded', timeout=30000)
            if not response or response.status != 200:
                result['failures'].append('catalog_ui_http')
            page.wait_for_selector('#product-grid .product-card[data-sku]', timeout=20000)
            page.wait_for_timeout(1200)
            ui_skus = page.locator('#product-grid .product-card[data-sku]').evaluate_all(
                "els => els.map(el => (el.dataset.sku || '').trim()).filter(Boolean)"
            )
            result['catalog_ui_skus'] = ui_skus[:50]
            result['ui_offenders'] = sorted(set(ui_skus) - active_skus)[:50]
            if result['ui_offenders']:
                result['failures'].append('catalog_ui_contains_noncanonical_product')

            pending = get_json(
                context.request,
                BASE + '/admin/ai-image-studio/api/pending_candidates.php?target_channel=shopee&limit=5000',
            )
            result['authenticated_pending'] = pending.get('success') is True
            if not result['authenticated_pending']:
                result['failures'].append('pending_endpoint_not_authenticated')
            pending_items = list(pending.get('items') or [])
            result['pending_count'] = len(pending_items)
            offenders = []
            for item in pending_items:
                sku = str(item.get('sku') or '').strip()
                if not sku or sku not in active_skus:
                    offenders.append({
                        'id': int(item.get('id') or 0),
                        'sku': sku,
                        'name': str(item.get('name') or '')[:120],
                    })
            result['pending_offenders'] = offenders[:50]
            if offenders:
                result['failures'].append('image_candidates_contain_noncanonical_product')

        except Exception as exc:
            result['failures'].append('browser_exception')
            result['browser_exception'] = f'{type(exc).__name__}: {exc}'[:1200]
        finally:
            result['finished_at'] = now()
            context.close()
            browser.close()

    result['overall'] = not result['failures']
    emit(result, 0 if result['overall'] else 7)


if __name__ == '__main__':
    main()
