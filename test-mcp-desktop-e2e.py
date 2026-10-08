#!/usr/bin/env python3
"""
End-to-end test for MCP Desktop Automation
Tests screenshot, click, type, and health check
"""

import json
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))

from mcp_desktop_tools import (
    screenshot,
    click,
    type_text,
    health_check,
    validate_bridge_available
)


def test_health_check():
    """Test 1: Health check."""
    print("\n[TEST 1] Health Check")
    print("-" * 50)

    if not validate_bridge_available():
        print("❌ FAILED: PowerShell or desktop bridge not available")
        return False

    print("✅ PowerShell and bridge available")

    result = health_check()
    print(f"Response: {json.dumps(result, indent=2)}")

    if result.get("ok"):
        print("✅ PASSED: Health check successful")
        print(f"   - Surface: {result.get('surface')}")
        print(f"   - Session: {result.get('session_id')}")
        print(f"   - Screen: {result.get('width')}x{result.get('height')}")
        return True
    else:
        print(f"❌ FAILED: {result.get('error')}")
        return False


def test_screenshot():
    """Test 2: Screenshot capture."""
    print("\n[TEST 2] Screenshot")
    print("-" * 50)

    result = screenshot()
    print(f"Response keys: {list(result.keys())}")

    if not result.get("ok"):
        print(f"❌ FAILED: {result.get('error')}")
        return False

    file_size = result.get("file_size_bytes", 0)
    print(f"✅ Screenshot captured: {file_size/1024:.1f} KB")

    if result.get("image_b64"):
        print("✅ Base64 encoded (< 5MB)")
    elif result.get("image_b64_url"):
        print(f"✅ File path: {result.get('file_path')}")
    else:
        print("⚠️ Screenshot saved but not encoded")

    if file_size > 0:
        print("✅ PASSED: Screenshot capture successful")
        return True
    else:
        print("❌ FAILED: Screenshot size invalid")
        return False


def test_click():
    """Test 3: Click at coordinates."""
    print("\n[TEST 3] Click")
    print("-" * 50)

    # Click at middle of screen (safe, non-destructive)
    x, y = 960, 540
    result = click(x, y, button="left", clicks=1)
    print(f"Response: {json.dumps(result, indent=2)}")

    if result.get("ok"):
        print(f"✅ PASSED: Click at ({x}, {y}) successful")
        return True
    else:
        print(f"❌ FAILED: {result.get('error')}")
        return False


def test_type():
    """Test 4: Type text."""
    print("\n[TEST 4] Type")
    print("-" * 50)

    test_text = "test-input-123"
    result = type_text(test_text, press_enter=False)
    print(f"Response: {json.dumps(result, indent=2)}")

    if result.get("ok"):
        print(f"✅ PASSED: Typed {len(test_text)} characters")
        return True
    else:
        print(f"❌ FAILED: {result.get('error')}")
        return False


def main():
    """Run all tests."""
    print("\n" + "=" * 50)
    print("MCP Desktop Automation - E2E Test Suite")
    print("=" * 50)

    tests = [
        ("Health Check", test_health_check),
        ("Screenshot", test_screenshot),
        ("Click", test_click),
        ("Type", test_type),
    ]

    results = []
    for name, test_func in tests:
        try:
            passed = test_func()
            results.append((name, passed))
        except Exception as e:
            print(f"\n❌ {name} - Exception: {e}")
            results.append((name, False))

        time.sleep(1)

    print("\n" + "=" * 50)
    print("Test Summary")
    print("=" * 50)

    passed = sum(1 for _, p in results if p)
    total = len(results)

    for name, passed_test in results:
        status = "✅" if passed_test else "❌"
        print(f"{status} {name}")

    print(f"\nTotal: {passed}/{total} passed")

    return 0 if passed == total else 1


if __name__ == "__main__":
    sys.exit(main())
