from __future__ import annotations

import argparse
import os
import re
import subprocess
import sys
import time
from pathlib import Path

from playwright.sync_api import Frame, Locator, Page, TimeoutError as PlaywrightTimeoutError, sync_playwright

SENDER = "naoresponda@dev.shopvivaliz.com.br"
TARGET = "https://security.microsoft.com/restrictedentities"
CHROME = Path(r"C:\Program Files\Google\Chrome\Application\chrome.exe")
LOG_DIR = Path(r"C:\site-shopvivaliz\logs")
SCREENSHOT = LOG_DIR / "exchange-restricted-sender.png"


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
        count = min(locator.count(), 30)
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
        if any(marker in text for marker in ("restricted entities", "entidades restritas", "security.microsoft.com")):
            return
        if any(marker in text for marker in ("sign in", "entrar", "escolha uma conta", "pick an account")):
            raise RuntimeError("EXCHANGE_UI_AUTH_REQUIRED")
        time.sleep(3)


def run(mode: str) -> int:
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    if not CHROME.is_file():
        out("EXCHANGE_UI_CHROME_MISSING")
        return 2

    subprocess.run(["taskkill", "/IM", "chrome.exe", "/F"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)
    time.sleep(3)

    user_data = Path(os.environ["LOCALAPPDATA"]) / "Google" / "Chrome" / "User Data"
    with sync_playwright() as playwright:
        context = playwright.chromium.launch_persistent_context(
            str(user_data),
            executable_path=str(CHROME),
            headless=False,
            no_viewport=True,
            args=["--profile-directory=Default", "--force-renderer-accessibility", "--new-window"],
            timeout=120000,
        )
        page = context.pages[0] if context.pages else context.new_page()
        try:
            page.goto(TARGET, wait_until="domcontentloaded", timeout=120000)
            wait_for_portal(page)
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

            for attempt in range(1, 7):
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
        except PlaywrightTimeoutError as exc:
            out("EXCHANGE_UI_TIMEOUT=" + str(exc).splitlines()[0])
            try:
                page.screenshot(path=str(SCREENSHOT), full_page=False)
            except Exception:
                pass
            return 14
        except Exception as exc:
            out("EXCHANGE_UI_ERROR=" + str(exc).replace("\n", " ")[:500])
            try:
                page.screenshot(path=str(SCREENSHOT), full_page=False)
            except Exception:
                pass
            return 15
        finally:
            context.close()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--mode", choices=("probe", "unblock"), default="probe")
    args = parser.parse_args()
    return run(args.mode)


if __name__ == "__main__":
    raise SystemExit(main())
