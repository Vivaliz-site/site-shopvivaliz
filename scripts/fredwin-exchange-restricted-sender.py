from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import subprocess
import time
import urllib.request
from pathlib import Path

from playwright.sync_api import Browser, Frame, Locator, Page, TimeoutError as PlaywrightTimeoutError, sync_playwright

SENDER = "naoresponda@dev.shopvivaliz.com.br"
TARGET = "https://security.microsoft.com/restrictedentities"
CHROME = Path(r"C:\Program Files\Google\Chrome\Application\chrome.exe")
LOG_DIR = Path(r"C:\site-shopvivaliz\logs")
SCREENSHOT = LOG_DIR / "exchange-restricted-sender.png"
PROFILE_COPY = Path(r"C:\Temp\shopvivaliz-exchange-profile")
DEBUG_PORT = 9223


def out(message: str) -> None:
    print(message.encode("cp1252", errors="replace").decode("cp1252"), flush=True)


def frames(page: Page) -> list[Frame]:
    return list(page.frames)


def body_text(page: Page) -> str:
    chunks: list[str] = []
    for frame in frames(page):
        try:
            chunks.append(frame.locator("body").inner_text(timeout=5000))
        except Exception:
            continue
    return "\n".join(chunks)


def visible_first(locator: Locator) -> Locator | None:
    try:
        count = min(locator.count(), 40)
    except Exception:
        return None
    for index in range(count):
        item = locator.nth(index)
        try:
            if item.is_visible(timeout=1000):
                return item
        except Exception:
            continue
    return None


def find_text(page: Page, text: str) -> tuple[Frame, Locator] | None:
    for frame in frames(page):
        item = visible_first(frame.get_by_text(text, exact=False))
        if item is not None:
            return frame, item
    return None


def click_named(page: Page, names: list[str]) -> bool:
    pattern = re.compile("|".join(re.escape(name) for name in names), re.I)
    for frame in frames(page):
        for factory in (
            lambda: frame.get_by_role("button", name=pattern),
            lambda: frame.get_by_role("link", name=pattern),
            lambda: frame.get_by_text(pattern, exact=False),
        ):
            try:
                item = visible_first(factory())
                if item is None:
                    continue
                item.click(timeout=10000)
                return True
            except Exception:
                continue
    return False


def search_sender(page: Page) -> None:
    selectors = [
        "input[type='search']",
        "input[placeholder*='Search' i]",
        "input[placeholder*='Pesquisar' i]",
        "input[aria-label*='Search' i]",
        "input[aria-label*='Pesquisar' i]",
    ]
    for frame in frames(page):
        for selector in selectors:
            try:
                item = visible_first(frame.locator(selector))
                if item is None:
                    continue
                item.fill(SENDER, timeout=10000)
                item.press("Enter")
                time.sleep(6)
                return
            except Exception:
                continue


def sender_is_listed(page: Page) -> bool:
    if SENDER.casefold() in body_text(page).casefold():
        return True
    search_sender(page)
    return SENDER.casefold() in body_text(page).casefold()


def select_sender(page: Page) -> bool:
    found = find_text(page, SENDER)
    if found is None:
        return False
    _, item = found
    try:
        row = item.locator("xpath=ancestor::*[@role='row' or self::tr][1]")
        if row.count() > 0:
            checkbox = visible_first(row.get_by_role("checkbox"))
            if checkbox is not None:
                checkbox.check(timeout=10000)
                return True
            row.click(timeout=10000)
            return True
    except Exception:
        pass
    try:
        item.click(timeout=10000)
        return True
    except Exception:
        return False


def confirm_dialog(page: Page) -> bool:
    for frame in frames(page):
        try:
            dialogs = frame.get_by_role("dialog")
            for index in range(min(dialogs.count(), 10)):
                dialog = dialogs.nth(index)
                if not dialog.is_visible(timeout=1000):
                    continue
                for checkbox_index in range(min(dialog.get_by_role("checkbox").count(), 10)):
                    checkbox = dialog.get_by_role("checkbox").nth(checkbox_index)
                    try:
                        if checkbox.is_visible(timeout=1000) and not checkbox.is_checked():
                            checkbox.check(timeout=5000)
                    except Exception:
                        continue
                pattern = re.compile(r"^(Unblock|Desbloquear|Submit|Enviar|Confirm|Confirmar|Save|Salvar)$", re.I)
                button = visible_first(dialog.get_by_role("button", name=pattern))
                if button is not None:
                    button.click(timeout=10000)
                    return True
        except Exception:
            continue
    return click_named(page, ["Unblock", "Desbloquear", "Submit", "Enviar", "Confirm", "Confirmar"])


def wait_for_portal(page: Page) -> None:
    deadline = time.time() + 90
    while time.time() < deadline:
        text = body_text(page).casefold()
        if any(marker in text for marker in ("restricted entities", "entidades restritas", "restricted users")):
            return
        if any(marker in text for marker in ("sign in", "entrar", "escolha uma conta", "pick an account")):
            raise RuntimeError("EXCHANGE_UI_AUTH_REQUIRED")
        time.sleep(3)
    raise RuntimeError("EXCHANGE_UI_PORTAL_NOT_READY")


def _original_user_data() -> Path:
    original = Path(os.environ["LOCALAPPDATA"]) / "Google" / "Chrome" / "User Data"
    if not original.is_dir():
        raise RuntimeError("CHROME_USER_DATA_MISSING")
    return original


def _profile_names(original: Path) -> list[str]:
    names: list[str] = []
    for child in original.iterdir():
        if not child.is_dir():
            continue
        if child.name != "Default" and not child.name.startswith("Profile "):
            continue
        if (child / "Preferences").is_file():
            names.append(child.name)
    names.sort(key=lambda value: (value != "Default", value))
    if not names:
        raise RuntimeError("CHROME_PROFILES_MISSING")
    out("EXCHANGE_UI_PROFILE_COUNT=" + str(len(names)))
    return names


def _copy_profile(original: Path, profile_name: str) -> Path:
    shutil.rmtree(PROFILE_COPY, ignore_errors=True)
    PROFILE_COPY.mkdir(parents=True, exist_ok=True)
    local_state = original / "Local State"
    if local_state.is_file():
        shutil.copy2(local_state, PROFILE_COPY / "Local State")

    source_profile = original / profile_name
    target_profile = PROFILE_COPY / profile_name
    ignored = {
        "Cache",
        "Code Cache",
        "GPUCache",
        "GrShaderCache",
        "ShaderCache",
        "DawnCache",
        "Crashpad",
        "BrowserMetrics",
        "OptimizationHints",
        "Safe Browsing Network",
    }

    def ignore_names(_directory: str, names: list[str]) -> set[str]:
        return {name for name in names if name in ignored or name.endswith(".tmp")}

    shutil.copytree(source_profile, target_profile, ignore=ignore_names, dirs_exist_ok=True)
    out("EXCHANGE_UI_PROFILE_COPY_OK=" + profile_name)
    return PROFILE_COPY


def _wait_debug_endpoint() -> str:
    endpoint = f"http://127.0.0.1:{DEBUG_PORT}/json/version"
    deadline = time.time() + 60
    last_error = ""
    while time.time() < deadline:
        try:
            with urllib.request.urlopen(endpoint, timeout=3) as response:
                payload = json.loads(response.read().decode("utf-8"))
            if payload.get("webSocketDebuggerUrl"):
                return f"http://127.0.0.1:{DEBUG_PORT}"
        except Exception as exc:
            last_error = f"{type(exc).__name__}:{exc}"
        time.sleep(2)
    raise RuntimeError("CHROME_DEBUG_ENDPOINT_TIMEOUT " + last_error[:200])


def _start_chrome(profile_root: Path, profile_name: str) -> subprocess.Popen:
    command = [
        str(CHROME),
        f"--remote-debugging-port={DEBUG_PORT}",
        f"--user-data-dir={profile_root}",
        f"--profile-directory={profile_name}",
        "--force-renderer-accessibility",
        "--no-first-run",
        "--no-default-browser-check",
        "--disable-background-mode",
        "--new-window",
        TARGET,
    ]
    process = subprocess.Popen(command, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    out(f"EXCHANGE_UI_CHROME_STARTED profile={profile_name} pid={process.pid}")
    return process


def _pick_page(browser: Browser) -> Page:
    for context in browser.contexts:
        for page in context.pages:
            if "security.microsoft.com" in page.url:
                return page
    if browser.contexts:
        return browser.contexts[0].new_page()
    raise RuntimeError("CHROME_CONTEXT_MISSING")


def _perform(page: Page, mode: str, profile_name: str) -> int:
    page.goto(TARGET, wait_until="domcontentloaded", timeout=120000)
    wait_for_portal(page)
    out("EXCHANGE_UI_AUTHENTICATED_PROFILE=" + profile_name)
    out("EXCHANGE_UI_URL=" + page.url)
    out("EXCHANGE_UI_TITLE=" + page.title())
    listed = sender_is_listed(page)
    out("EXCHANGE_UI_SENDER_LISTED=" + str(listed).lower())
    if mode == "probe":
        page.screenshot(path=str(SCREENSHOT), full_page=False)
        out("EXCHANGE_UI_PROBE_OK")
        return 0
    if not listed:
        out("EXCHANGE_UI_SENDER_NOT_LISTED")
        return 0
    if not select_sender(page):
        out("EXCHANGE_UI_SELECT_FAILED")
        return 11
    time.sleep(2)
    if not click_named(page, ["Unblock", "Desbloquear"]):
        out("EXCHANGE_UI_UNBLOCK_BUTTON_NOT_FOUND")
        return 12
    time.sleep(3)
    confirm_dialog(page)
    time.sleep(15)

    for attempt in range(1, 10):
        page.goto(TARGET, wait_until="domcontentloaded", timeout=120000)
        wait_for_portal(page)
        if not sender_is_listed(page):
            out("EXCHANGE_UI_UNBLOCK_CONFIRMED")
            return 0
        out(f"EXCHANGE_UI_STILL_LISTED_ATTEMPT={attempt}")
        time.sleep(10)
    page.screenshot(path=str(SCREENSHOT), full_page=False)
    out("EXCHANGE_UI_UNBLOCK_UNCONFIRMED")
    return 13


def run(mode: str) -> int:
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    if not CHROME.is_file():
        out("EXCHANGE_UI_CHROME_MISSING")
        return 2

    try:
        original = _original_user_data()
        profile_names = _profile_names(original)
    except Exception as exc:
        out("EXCHANGE_UI_ERROR=" + type(exc).__name__ + ":" + str(exc)[:500])
        return 15

    auth_required = 0
    for profile_name in profile_names:
        browser: Browser | None = None
        chrome_process: subprocess.Popen | None = None
        try:
            subprocess.run(["taskkill", "/IM", "chrome.exe", "/F"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)
            time.sleep(4)
            profile_root = _copy_profile(original, profile_name)
            chrome_process = _start_chrome(profile_root, profile_name)
            endpoint = _wait_debug_endpoint()
            with sync_playwright() as playwright:
                browser = playwright.chromium.connect_over_cdp(endpoint, timeout=60000)
                page = _pick_page(browser)
                return _perform(page, mode, profile_name)
        except PlaywrightTimeoutError as exc:
            out("EXCHANGE_UI_PROFILE_TIMEOUT=" + profile_name + ":" + str(exc).replace("\n", " ")[:400])
        except RuntimeError as exc:
            if str(exc) == "EXCHANGE_UI_AUTH_REQUIRED":
                auth_required += 1
                out("EXCHANGE_UI_PROFILE_AUTH_REQUIRED=" + profile_name)
            else:
                out("EXCHANGE_UI_PROFILE_ERROR=" + profile_name + ":" + str(exc).replace("\n", " ")[:500])
        except Exception as exc:
            out("EXCHANGE_UI_PROFILE_ERROR=" + profile_name + ":" + type(exc).__name__ + ":" + str(exc).replace("\n", " ")[:500])
        finally:
            try:
                if browser is not None:
                    browser.close()
            except Exception:
                pass
            if chrome_process is not None:
                try:
                    chrome_process.terminate()
                except Exception:
                    pass

    if auth_required == len(profile_names):
        out("EXCHANGE_UI_AUTH_REQUIRED_ALL_PROFILES")
    else:
        out("EXCHANGE_UI_NO_USABLE_ADMIN_PROFILE")
    return 15


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--mode", choices=("probe", "unblock"), default="probe")
    args = parser.parse_args()
    return run(args.mode)


if __name__ == "__main__":
    raise SystemExit(main())
