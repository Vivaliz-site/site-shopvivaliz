from __future__ import annotations

import sys
import time
from pathlib import Path

from pywinauto import Desktop, keyboard

TARGET = "https://security.microsoft.com/restrictedentities"
SENDER = "naoresponda@dev.shopvivaliz.com.br"
LOG = Path(r"C:\site-shopvivaliz\logs\restricted-entities-browser-inspect.log")


def emit(message: str) -> None:
    line = message.replace("\r", " ").replace("\n", " ")[:1000]
    print(line, flush=True)


def clipboard_text() -> str:
    try:
        import win32clipboard

        win32clipboard.OpenClipboard()
        try:
            value = win32clipboard.GetClipboardData()
        finally:
            win32clipboard.CloseClipboard()
        return str(value or "")
    except Exception:
        return ""


def safe_url(win) -> str:
    try:
        win.set_focus()
        keyboard.send_keys("^l")
        time.sleep(0.3)
        keyboard.send_keys("^c")
        time.sleep(0.3)
        value = clipboard_text().strip()
        keyboard.send_keys("{ESC}")
        if value.startswith(("https://", "http://")):
            return value[:500]
    except Exception:
        pass
    return ""


def snapshot(win) -> tuple[list[str], list[str]]:
    names: list[str] = []
    controls: list[str] = []
    try:
        descendants = win.descendants()
    except Exception:
        descendants = []
    for ctrl in descendants[:5000]:
        try:
            name = (ctrl.window_text() or "").strip()
            if not name:
                continue
            names.append(name)
            ctype = str(ctrl.element_info.control_type or "")
            low = name.casefold()
            if ctype in {"Button", "Hyperlink", "MenuItem", "TabItem"} and any(
                marker in low
                for marker in (
                    "unblock",
                    "desbloquear",
                    "restricted",
                    "restrita",
                    "review",
                    "revisão",
                    "revisao",
                )
            ):
                controls.append(f"{ctype}:{name[:180]}")
        except Exception:
            continue
    return names, controls


def main() -> int:
    LOG.parent.mkdir(parents=True, exist_ok=True)
    time.sleep(8)
    windows = []
    for win in Desktop(backend="uia").windows():
        try:
            title = (win.window_text() or "").strip()
            if title and any(marker in title.casefold() for marker in ("chrome", "edge", "microsoft")):
                windows.append(win)
        except Exception:
            continue

    emit(f"BROWSER_WINDOW_COUNT={len(windows)}")
    selected = None
    selected_url = ""
    selected_names: list[str] = []
    selected_controls: list[str] = []

    for index, win in enumerate(windows):
        try:
            title = (win.window_text() or "").strip()
        except Exception:
            title = ""
        url = safe_url(win)
        names, controls = snapshot(win)
        joined = "\n".join(names).casefold()
        portal = "unknown"
        if "security.microsoft.com" in url.casefold() or "microsoft defender" in joined:
            portal = "defender"
        elif "admin.exchange.microsoft.com" in url.casefold() or "exchange admin" in joined:
            portal = "exchange_admin"
        emit(f"WINDOW_{index}_TITLE={title[:240]}")
        emit(f"WINDOW_{index}_URL={url[:500]}")
        emit(f"WINDOW_{index}_PORTAL={portal}")
        if controls:
            emit(f"WINDOW_{index}_ACTION_CONTROLS={' | '.join(controls[:30])}")
        if selected is None and (
            "security.microsoft.com" in url.casefold()
            or "restricted entities" in joined
            or "entidades restritas" in joined
        ):
            selected = win
            selected_url = url
            selected_names = names
            selected_controls = controls

    if selected is None:
        emit("RESTRICTED_PAGE_FOUND=false")
        emit(f"TARGET_EXPECTED={TARGET}")
        return 2

    joined = "\n".join(selected_names).casefold()
    restricted = "restricted entities" in joined or "entidades restritas" in joined
    sender_visible = SENDER.casefold() in joined
    unblock_visible = any(marker in joined for marker in ("unblock", "desbloquear"))
    sign_in_visible = any(
        marker in joined
        for marker in (
            "sign in",
            "entrar",
            "pick an account",
            "escolha uma conta",
        )
    )
    emit("RESTRICTED_PAGE_FOUND=true")
    emit(f"RESTRICTED_PAGE_URL={selected_url[:500]}")
    emit(f"RESTRICTED_ENTITIES_MARKER={str(restricted).lower()}")
    emit(f"SENDER_VISIBLE={str(sender_visible).lower()}")
    emit(f"UNBLOCK_VISIBLE={str(unblock_visible).lower()}")
    emit(f"AUTH_REQUIRED_VISIBLE={str(sign_in_visible).lower()}")
    if selected_controls:
        emit(f"RESTRICTED_ACTION_CONTROLS={' | '.join(selected_controls[:50])}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
