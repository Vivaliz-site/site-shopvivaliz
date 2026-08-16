import base64
import json
import os
import time
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlparse

from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeout

BASE = 'https://shopvivaliz.com.br'
FAKE_JOB_ID = 99019101


def now():
    return datetime.now(timezone.utc).isoformat()


def emit(result, code):
    raw = json.dumps(result, ensure_ascii=False, separators=(',', ':')).encode('utf-8')
    print('SV_IMAGE_BROWSER_SMOKE_B64=' + base64.b64encode(raw).decode('ascii'))
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


def main():
    sid = os.environ.get('SV_ADMIN_SESSION_ID', '')
    sname = os.environ.get('SV_ADMIN_SESSION_NAME', 'PHPSESSID')
    result = {
        'schema': 1,
        'started_at': now(),
        'authenticated': False,
        'selected_product_id': 0,
        'preflight_visible': False,
        'preflight_confirmed': False,
        'enqueue_intercepted': False,
        'enqueue_payload': {},
        'progress_visible': False,
        'progress_text': '',
        'ops_banner': '',
        'console_errors': [],
        'page_errors': [],
        'blocked_mutations': [],
        'failures': [],
    }
    if not sid or not sname:
        result['failures'].append('ephemeral_session_missing')
        result['finished_at'] = now()
        emit(result, 8)

    with sync_playwright() as pw:
        browser = pw.chromium.launch(executable_path=chrome_path(), headless=True)
        context = browser.new_context(
            viewport={'width': 1440, 'height': 1000},
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
        page.set_default_timeout(12000)
        page.on('console', lambda msg: result['console_errors'].append(msg.text[:500]) if msg.type == 'error' else None)
        page.on('pageerror', lambda err: result['page_errors'].append(str(err)[:500]))

        def route_handler(route, request):
            parsed = urlparse(request.url)
            path = parsed.path
            method = request.method.upper()

            if path.endswith('/admin/ai-image-studio/api/provider_health_check.php'):
                payload = {
                    'success': True,
                    'has_visual_editor': True,
                    'providers': {
                        'openai': {'ok': True, 'working_key_count': 1, 'key_count': 1, 'detail': 'browser-smoke'},
                        'google': {'ok': True, 'working_key_count': 1, 'key_count': 1, 'detail': 'browser-smoke'},
                        'claude': {'ok': True, 'working_key_count': 1, 'key_count': 1, 'detail': 'browser-smoke'},
                        'openrouter': {'ok': True, 'working_key_count': 1, 'key_count': 1, 'detail': 'browser-smoke'},
                        'groq': {'ok': True, 'working_key_count': 1, 'key_count': 1, 'detail': 'browser-smoke'},
                    },
                }
                route.fulfill(status=200, content_type='application/json', body=json.dumps(payload))
                return

            if method == 'POST' and path.endswith('/admin/ai-image-studio/api/enqueue_generation.php'):
                try:
                    body = json.loads(request.post_data or '{}')
                except Exception:
                    body = {}
                result['enqueue_intercepted'] = True
                result['enqueue_payload'] = body
                product_id = int(body.get('product_id') or 0)
                payload = {
                    'success': True,
                    'job_id': FAKE_JOB_ID,
                    'product_id': product_id,
                    'job_status': 'queued',
                    'deduplicated': False,
                    'provider_requested': body.get('provider') or 'openai',
                    'target_channel': body.get('target_channel') or 'shopee',
                    'image_types': body.get('image_types') or ['cover'],
                    'message': 'browser smoke intercepted; no production mutation',
                }
                route.fulfill(status=200, content_type='application/json', body=json.dumps(payload))
                return

            if method == 'GET' and path.endswith('/admin/ai-image-studio/api/generation_status.php'):
                body = result.get('enqueue_payload') or {}
                payload = {
                    'success': True,
                    'jobs': [{
                        'job_id': FAKE_JOB_ID,
                        'product_id': int(body.get('product_id') or 0),
                        'provider': body.get('provider') or 'openai',
                        'target_channel': body.get('target_channel') or 'shopee',
                        'image_types': body.get('image_types') or ['cover'],
                        'status': 'done',
                        'result_state': 'ready_for_review',
                        'terminal': True,
                        'variants': {'missing': [], 'failed_types': [], 'ready_types': body.get('image_types') or ['cover']},
                        'staging': [],
                        'last_error': '',
                    }],
                }
                route.fulfill(status=200, content_type='application/json', body=json.dumps(payload))
                return

            if method in {'POST', 'PUT', 'PATCH', 'DELETE'}:
                result['blocked_mutations'].append({'method': method, 'path': path[:180]})
                route.abort('blockedbyclient')
                return

            route.continue_()

        context.route('**/*', route_handler)

        try:
            response = page.goto(BASE + '/admin/ai-image-studio/admin_dashboard.php', wait_until='domcontentloaded', timeout=25000)
            if not response or response.status != 200:
                result['failures'].append('dashboard_http')
            page.wait_for_selector('#iv-generation', timeout=15000)
            result['authenticated'] = page.url.startswith(BASE + '/admin/') and not page.locator('input[type=password]').count()
            if not result['authenticated']:
                result['failures'].append('not_authenticated')

            page.select_option('#iv-channel', 'shopee')
            page.select_option('#iv-provider', 'openai')
            page.wait_for_timeout(1800)
            page.wait_for_selector('.iv-item', timeout=15000)

            candidate = page.locator('.iv-item').filter(has=page.locator('summary input.iv-check:not([disabled])')).first
            if candidate.count() == 0:
                result['failures'].append('no_selectable_candidate')
            else:
                product_id = int(candidate.get_attribute('data-product-id') or 0)
                result['selected_product_id'] = product_id
                product_check = candidate.locator('summary input.iv-check').first
                product_check.check()
                page.wait_for_timeout(250)

                variants = candidate.locator('.iv-variant input.iv-check:not([disabled])')
                if variants.count() == 0:
                    result['failures'].append('no_selectable_variant')
                elif not any(variants.nth(i).is_checked() for i in range(variants.count())):
                    variants.first.check()

                page.wait_for_function("() => { const b=document.querySelector('#iv-run'); return b && !b.disabled; }", timeout=8000)
                page.locator('#iv-run').click()
                page.wait_for_selector('#sv-img-preflight.show', timeout=8000)
                result['preflight_visible'] = True
                page.locator('#sv-img-preflight .sv-img-confirm').click()
                result['preflight_confirmed'] = True

                deadline = time.time() + 12
                while time.time() < deadline and not result['enqueue_intercepted']:
                    page.wait_for_timeout(250)

                if not result['enqueue_intercepted']:
                    result['failures'].append('confirm_did_not_reach_enqueue')

                try:
                    page.wait_for_selector('#iv-progress.show', timeout=8000)
                    result['progress_visible'] = True
                    result['progress_text'] = page.locator('#iv-progress').inner_text()[:1200]
                except PlaywrightTimeout:
                    result['failures'].append('progress_not_visible')

                banner = page.locator('#iv-ops-banner:not([hidden])')
                if banner.count():
                    result['ops_banner'] = banner.first.inner_text()[:700]

                overlay_open = page.locator('#sv-img-preflight.show').count() > 0
                if overlay_open:
                    result['failures'].append('preflight_remained_open')

                if result['enqueue_intercepted']:
                    payload = result['enqueue_payload']
                    if int(payload.get('product_id') or 0) != product_id:
                        result['failures'].append('wrong_product_payload')
                    if payload.get('target_channel') != 'shopee':
                        result['failures'].append('wrong_channel_payload')
                    if payload.get('provider') != 'openai':
                        result['failures'].append('wrong_provider_payload')
                    if not payload.get('image_types'):
                        result['failures'].append('missing_image_types')

        except Exception as exc:
            result['failures'].append('browser_exception')
            result['browser_exception'] = f'{type(exc).__name__}: {exc}'[:1000]
        finally:
            result['finished_at'] = now()
            context.close()
            browser.close()

    result['overall'] = not result['failures']
    emit(result, 0 if result['overall'] else 7)


if __name__ == '__main__':
    try:
        main()
    except SystemExit:
        raise
    except Exception as exc:
        emit({'schema': 1, 'overall': False, 'failures': ['top_exception'], 'error': f'{type(exc).__name__}: {exc}', 'finished_at': now()}, 9)
