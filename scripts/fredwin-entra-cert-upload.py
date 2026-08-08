from __future__ import annotations

import os
import shutil
import sys
import tempfile
import time
from pathlib import Path

from playwright.sync_api import TimeoutError as PlaywrightTimeoutError
from playwright.sync_api import sync_playwright

APP_ID = "a5e400f0-969e-4fbe-be61-d390cb112517"
THUMBPRINT = "D2264CCD81743AACC7514D9E5290E8C942443D9B"
CERT_PATH = Path(r"C:\Certs\ShopVivalizExchangeAuth.cer")
CHROME = Path(r"C:\Program Files\Google\Chrome\Application\chrome.exe")
USER_DATA = Path.home() / "AppData" / "Local" / "Google" / "Chrome" / "User Data"
TARGET = (
    "https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/"
    f"ApplicationMenuBlade/~/Credentials/appId/{APP_ID}"
)


def log(message: str) -> None:
    safe = message.encode("cp1252", errors="replace").decode("cp1252")
    print(safe, flush=True)


def visible_text(page) -> str:
    try:
        return page.locator("body").inner_text(timeout=5000)
    except Exception:
        return ""


def click_any(page, names: list[str], timeout: int = 5000) -> bool:
    for name in names:
        candidates = [
            page.get_by_role("button", name=name, exact=False),
            page.get_by_role("link", name=name, exact=False),
            page.get_by_text(name, exact=False),
        ]
        for candidate in candidates:
            try:
                if candidate.first.is_visible(timeout=700):
                    candidate.first.click(timeout=timeout)
                    return True
            except Exception:
                pass
    return False


def make_profile_copy() -> Path:
    """Copy only auth/profile state needed by Chrome; never read cookies ourselves."""
    target = Path(tempfile.gettempdir()) / "ShopVivaliz-Entra-Automation"
    if target.exists():
        shutil.rmtree(target, ignore_errors=True)
    target.mkdir(parents=True)
    local_state = USER_DATA / "Local State"
    if local_state.is_file():
        shutil.copy2(local_state, target / "Local State")

    src = USER_DATA / "Default"
    dst = target / "Default"
    excluded_dirs = {
        "Cache", "Code Cache", "GPUCache", "DawnCache", "GrShaderCache",
        "ShaderCache", "Service Worker", "blob_storage", "File System",
    }

    def ignore(directory: str, names: list[str]) -> set[str]:
        result: set[str] = set()
        for name in names:
            if name in excluded_dirs or name.endswith("-journal"):
                result.add(name)
        return result

    shutil.copytree(src, dst, ignore=ignore, dirs_exist_ok=True)
    return target


def main() -> int:
    if not CERT_PATH.is_file():
        log("ENTRA_CERT_UPLOAD_NOT_READY: public certificate missing")
        return 2
    if not CHROME.is_file() or not USER_DATA.is_dir():
        log("ENTRA_CERT_UPLOAD_NOT_READY: Chrome/profile missing")
        return 3

    try:
        automation_profile = make_profile_copy()
    except Exception as exc:
        log(f"ENTRA_CERT_UPLOAD_NOT_READY: profile copy failed: {type(exc).__name__}: {exc}")
        return 4

    with sync_playwright() as p:
        try:
            context = p.chromium.launch_persistent_context(
                user_data_dir=str(automation_profile),
                executable_path=str(CHROME),
                headless=True,
                args=["--profile-directory=Default", "--disable-gpu"],
                ignore_default_args=["--password-store=basic", "--use-mock-keychain"],
                timeout=30000,
            )
        except Exception as exc:
            log(f"ENTRA_CERT_UPLOAD_NOT_READY: browser launch failed: {type(exc).__name__}: {str(exc)[:800]}")
            return 5

        try:
            page = context.pages[0] if context.pages else context.new_page()
            page.goto(TARGET, wait_until="domcontentloaded", timeout=60000)
            page.wait_for_timeout(12000)
            text = visible_text(page)
            lower = text.casefold()
            url = page.url
            log(f"ENTRA_PAGE_URL={url.split('?')[0]}")
            log(f"ENTRA_PAGE_TITLE={page.title()}")

            login_markers = [
                "login.microsoftonline.com", "sign in to your account",
                "entrar em sua conta", "pick an account", "escolha uma conta",
            ]
            if any(marker in url.casefold() or marker in lower for marker in login_markers):
                log("ENTRA_CERT_UPLOAD_AUTH_REQUIRED")
                return 10

            if THUMBPRINT.casefold() in lower:
                log("ENTRA_CERT_ALREADY_REGISTERED")
                return 0

            click_any(page, ["Certificates & secrets", "Certificados e segredos"])
            page.wait_for_timeout(2500)
            click_any(page, ["Certificates", "Certificados"])
            page.wait_for_timeout(2500)

            if not click_any(page, ["Upload certificate", "Carregar certificado"]):
                text = visible_text(page)
                if THUMBPRINT.casefold() in text.casefold():
                    log("ENTRA_CERT_ALREADY_REGISTERED")
                    return 0
                log("ENTRA_CERT_UPLOAD_NOT_READY: upload button not found")
                log("ENTRA_VISIBLE_MARKERS=" + " | ".join(text.splitlines()[:35]))
                return 11

            page.wait_for_timeout(1200)
            file_input = page.locator('input[type="file"]')
            try:
                file_input.first.set_input_files(str(CERT_PATH), timeout=8000)
            except PlaywrightTimeoutError:
                log("ENTRA_CERT_UPLOAD_NOT_READY: file input not found after upload action")
                return 12

            page.wait_for_timeout(1200)
            if not click_any(page, ["Add", "Adicionar"]):
                log("ENTRA_CERT_UPLOAD_NOT_READY: final Add button not found")
                return 13

            deadline = time.time() + 35
            while time.time() < deadline:
                page.wait_for_timeout(2000)
                text = visible_text(page)
                if THUMBPRINT.casefold() in text.casefold():
                    log("ENTRA_CERT_UPLOAD_READY")
                    log(f"registered_thumbprint={THUMBPRINT}")
                    return 0
            log("ENTRA_CERT_UPLOAD_NOT_READY: certificate thumbprint not visible after upload")
            return 14
        finally:
            context.close()
            shutil.rmtree(automation_profile, ignore_errors=True)


if __name__ == "__main__":
    raise SystemExit(main())
