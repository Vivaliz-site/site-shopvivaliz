#!/usr/bin/env python3
"""
MCP Tools for Windows Desktop Automation via ShopVivaliz Native Desktop Bridge
Supports: screenshot, click, type, navigate, dom-inspect with fallback automation
"""

import json
import subprocess
import tempfile
import base64
import sys
from pathlib import Path
from typing import Any, Dict, Optional

BRIDGE_SCRIPT = Path(__file__).parent / "scripts" / "shopvivaliz-native-desktop-bridge.ps1"
MAX_RESPONSE_SIZE = 50 * 1024 * 1024  # 50MB


def validate_bridge_available() -> bool:
    """Check if PowerShell and desktop bridge script are available."""
    if not BRIDGE_SCRIPT.exists():
        return False
    result = subprocess.run(
        ["powershell", "-NoProfile", "-Command", "exit 0"],
        capture_output=True,
        timeout=5
    )
    return result.returncode == 0


def execute_desktop_action(action: str, **kwargs) -> Dict[str, Any]:
    """Execute desktop automation action via PowerShell bridge."""

    request = {"action": action}
    request.update(kwargs)

    with tempfile.TemporaryDirectory() as tmpdir:
        tmpdir_path = Path(tmpdir)
        request_file = tmpdir_path / "request.json"
        response_file = tmpdir_path / "response.json"
        screenshot_file = tmpdir_path / "screenshot.png"

        request_file.write_text(json.dumps(request), encoding="utf-8")

        cmd = [
            "powershell",
            "-NoProfile",
            "-ExecutionPolicy", "Bypass",
            "-File", str(BRIDGE_SCRIPT),
            "-Mode", "Dispatch",
            "-RequestPath", str(request_file),
            "-ResponsePath", str(response_file),
            "-ScreenshotPath", str(screenshot_file)
        ]

        try:
            result = subprocess.run(
                cmd,
                capture_output=True,
                text=True,
                timeout=90
            )

            if result.returncode != 0:
                return {
                    "ok": False,
                    "error": f"bridge_failed: {result.stderr or result.stdout}",
                    "action": action
                }

            response = json.loads(result.stdout)

            if action == "screenshot" and screenshot_file.exists():
                screenshot_data = screenshot_file.read_bytes()
                response["screenshot_saved"] = True
                response["file_size_bytes"] = len(screenshot_data)

                if len(screenshot_data) < 5242880:
                    response["image_b64"] = base64.b64encode(screenshot_data).decode("ascii")
                else:
                    response["image_b64_url"] = f"file://{screenshot_file}"
                    response["note"] = f"Screenshot saved ({len(screenshot_data)/1024/1024:.1f}MB). Access via file_path."

            return response

        except subprocess.TimeoutExpired:
            return {"ok": False, "error": "desktop_timeout", "action": action}
        except json.JSONDecodeError as e:
            return {"ok": False, "error": f"invalid_response: {e}", "action": action}
        except Exception as e:
            return {"ok": False, "error": f"desktop_error: {e}", "action": action}


def screenshot() -> Dict[str, Any]:
    """Capture full desktop screenshot."""
    return execute_desktop_action("screenshot")


def click(x: int, y: int, button: str = "left", clicks: int = 1) -> Dict[str, Any]:
    """Click at coordinates on desktop."""
    if not isinstance(x, int) or not isinstance(y, int):
        return {"ok": False, "error": "coordinates_must_be_integers"}

    if button not in ("left", "right", "middle"):
        return {"ok": False, "error": f"invalid_button: {button}"}

    if clicks < 1 or clicks > 3:
        return {"ok": False, "error": "clicks_must_be_1_to_3"}

    return execute_desktop_action("click", x=x, y=y, button=button, clicks=clicks)


def type_text(text: str, press_enter: bool = False) -> Dict[str, Any]:
    """Type text using clipboard and SendKeys."""
    if not isinstance(text, str) or not text:
        return {"ok": False, "error": "text_required"}

    if len(text) > 4096:
        return {"ok": False, "error": "text_too_long"}

    return execute_desktop_action("type", text=text, press_enter=press_enter)


def health_check() -> Dict[str, Any]:
    """Check desktop connection health."""
    return execute_desktop_action("health")


if __name__ == "__main__":
    if not validate_bridge_available():
        print("Error: PowerShell or desktop bridge not available", file=sys.stderr)
        sys.exit(1)

    health = health_check()
    print(json.dumps(health, indent=2))
