from __future__ import annotations

import argparse
import subprocess
import sys
import time
from pathlib import Path

from pywinauto import Application, Desktop, keyboard

APP_ID = "a5e400f0-969e-4fbe-be61-d390cb112517"
THUMBPRINT = "D2264CCD81743AACC7514D9E5290E8C942443D9B"
CERT_PATH = r"C:\Certs\ShopVivalizExchangeAuth.cer"
CHROME = r"C:\Program Files\Google\Chrome\Application\chrome.exe"
TARGET = (
    "https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/"
    f"ApplicationMenuBlade/~/Credentials/appId/{APP_ID}"
)


def out(message: str) -> None:
    print(message.encode("cp1252", errors="replace").decode("cp1252"), flush=True)


def get_chrome_window(timeout: int = 40):
    deadline = time.time() + timeout
    while time.time() < deadline:
        windows = Desktop(backend="uia").windows()
        for win in windows:
            try:
                title = win.window_text()
                if title and ("Chrome" in title or "Microsoft Entra" in title or "Microsoft Azure" in title):
                    return win
            except Exception:
                pass
        time.sleep(1)
    raise RuntimeError("Chrome window not found on interactive desktop")


def control_snapshot(win) -> list[tuple[str, str]]:
    items: list[tuple[str, str]] = []
    for ctrl in win.descendants():
        try:
            name = (ctrl.window_text() or "").strip()
            ctype = ctrl.element_info.control_type or ""
            if name:
                items.append((ctype, name))
        except Exception:
            pass
    return items


def find_control(win, names: list[str], control_types: tuple[str, ...] = ("Button", "Hyperlink", "TabItem", "Text")):
    lowered = [n.casefold() for n in names]
    for ctrl in win.descendants():
        try:
            name = (ctrl.window_text() or "").strip()
            ctype = ctrl.element_info.control_type or ""
            if ctype in control_types and name and any(n in name.casefold() for n in lowered):
                return ctrl
        except Exception:
            pass
    return None


def click_named(win, names: list[str]) -> bool:
    ctrl = find_control(win, names)
    if ctrl is None:
        return False
    try:
        ctrl.click_input()
    except Exception:
        try:
            ctrl.invoke()
        except Exception:
            return False
    return True


def probe(win) -> int:
    items = control_snapshot(win)
    joined = "\n".join(name for _, name in items).casefold()
    out("ENTRA_UIA_WINDOW=" + win.window_text())
    if any(x in joined for x in ("entre em microsoft azure", "sign in", "entrar em", "escolha uma conta")):
        out("ENTRA_UIA_AUTH_REQUIRED")
        return 10
    markers = []
    for ctype, name in items:
        low = name.casefold()
        if any(k in low for k in ("certif", "upload", "carregar", "app registration", "registro de aplicativo", "shopvivaliz")):
            markers.append(f"{ctype}:{name}")
    out("ENTRA_UIA_MARKERS=" + " | ".join(markers[:80]))
    if THUMBPRINT.casefold() in joined:
        out("ENTRA_UIA_CERT_ALREADY_REGISTERED")
        return 0
    out("ENTRA_UIA_PORTAL_READY")
    return 0


def upload(win) -> int:
    items = control_snapshot(win)
    joined = "\n".join(name for _, name in items).casefold()
    if THUMBPRINT.casefold() in joined:
        out("ENTRA_UIA_CERT_ALREADY_REGISTERED")
        return 0
    if any(x in joined for x in ("entre em microsoft azure", "sign in", "entrar em", "escolha uma conta")):
        out("ENTRA_UIA_AUTH_REQUIRED")
        return 10

    click_named(win, ["Certificates & secrets", "Certificados e segredos"])
    time.sleep(3)
    win = get_chrome_window(10)
    click_named(win, ["Certificates", "Certificados"])
    time.sleep(3)
    win = get_chrome_window(10)

    if not click_named(win, ["Upload certificate", "Carregar certificado"]):
        out("ENTRA_UIA_UPLOAD_BUTTON_NOT_FOUND")
        probe(win)
        return 11
    time.sleep(2)

    # Azure portal file picker may expose either an Edit control or a browser file-input button.
    dialogs = Desktop(backend="uia").windows()
    file_dialog = None
    for d in dialogs:
        try:
            title = d.window_text().casefold()
            if any(k in title for k in ("open", "abrir", "choose", "selecion")):
                file_dialog = d
                break
        except Exception:
            pass
    if file_dialog is not None:
        edits = file_dialog.descendants(control_type="Edit")
        if edits:
            edits[0].set_edit_text(CERT_PATH)
            keyboard.send_keys("{ENTER}")
    else:
        # If the portal did not create a native dialog yet, activate the first file/choose control.
        win = get_chrome_window(10)
        choose = find_control(win, ["Choose file", "Escolher arquivo", "Browse", "Procurar"], ("Button", "Text"))
        if choose is None:
            out("ENTRA_UIA_FILE_PICKER_NOT_FOUND")
            return 12
        choose.click_input()
        time.sleep(1)
        keyboard.send_keys(CERT_PATH, with_spaces=True)
        keyboard.send_keys("{ENTER}")

    time.sleep(2)
    win = get_chrome_window(10)
    if not click_named(win, ["Add", "Adicionar"]):
        out("ENTRA_UIA_ADD_BUTTON_NOT_FOUND")
        return 13
    time.sleep(8)
    win = get_chrome_window(10)
    joined = "\n".join(name for _, name in control_snapshot(win)).casefold()
    if THUMBPRINT.casefold() in joined:
        out("ENTRA_UIA_CERT_UPLOAD_READY")
        out("registered_thumbprint=" + THUMBPRINT)
        return 0
    out("ENTRA_UIA_CERT_UPLOAD_UNCONFIRMED")
    return 14


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--mode", choices=["probe", "upload"], default="probe")
    args = parser.parse_args()
    if not Path(CERT_PATH).is_file():
        out("ENTRA_UIA_CERT_FILE_MISSING")
        return 2

    subprocess.run(["taskkill", "/IM", "chrome.exe", "/F"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False)
    time.sleep(2)
    subprocess.Popen(
        [CHROME, "--profile-directory=Default", "--force-renderer-accessibility", "--new-window", TARGET],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    time.sleep(12)
    win = get_chrome_window()
    try:
        win.set_focus()
    except Exception:
        pass
    return probe(win) if args.mode == "probe" else upload(win)


if __name__ == "__main__":
    raise SystemExit(main())
