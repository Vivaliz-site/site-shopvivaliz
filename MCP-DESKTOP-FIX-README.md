# Fred-Win MCP Desktop Automation - Fixes & Validation

## Overview

This document describes the fixes applied to the Fred-Win MCP desktop automation system and provides step-by-step E2E validation instructions.

## Changes Made

### 1. **Desktop Bridge PowerShell** (`scripts/shopvivaliz-native-desktop-bridge.ps1`)

#### Problem
- Screenshot timeout too short (25s) for network/rendering delays
- Large screenshots truncated to Base64, causing response failures
- No validation of session/user context in Worker mode

#### Solutions
- ✅ Increased timeout: 25s → 60s
- ✅ Conditional Base64 encoding: only if screenshot < 5MB
- ✅ Added file path in response for large screenshots
- ✅ Enhanced session/user validation
- ✅ Added multi-monitor and DPI awareness

### 2. **MCP Integration Layer** (`mcp-desktop-tools.py`) - NEW

Unified Python API for desktop automation:
- `screenshot()` - Capture full desktop with fallback handling
- `click(x, y, button="left", clicks=1)` - Mouse automation
- `type_text(text, press_enter=False)` - Keyboard input via clipboard
- `health_check()` - Connection validation
- `validate_bridge_available()` - Prerequisites check

**Features:**
- Validates input parameters
- Handles large screenshots gracefully
- Returns structured JSON responses
- Proper error messages

### 3. **E2E Test Suite** (`test-mcp-desktop-e2e.py`) - NEW

Automated validation of desktop automation:
1. Health check - Connection and session validation
2. Screenshot - Full desktop capture and encoding
3. Click - Mouse automation at coordinates
4. Type - Keyboard input (non-destructive test)

## E2E Validation Steps

### Prerequisites

**On Fred-Win (Windows machine):**
- PowerShell 5.1+ (default on Windows 10/11)
- Administrative privileges for scheduled task creation
- Desktop Commander service running (optional, for background tasks)

**On Backend (Linux machine running MCP):**
- Python 3.8+
- Windows SSH access configured (reverse SSH on port 2222)
- Desktop Bridge script accessible from `scripts/` directory

### Step 1: Verify Prerequisites

```bash
# Check PowerShell availability
powershell -NoProfile -Command "Write-Host 'PowerShell OK'; exit 0"

# Check desktop bridge script
ls -la scripts/shopvivaliz-native-desktop-bridge.ps1

# Verify Python environment
python3 --version
python3 -c "import json; print('JSON OK')"
```

### Step 2: Run Health Check

```bash
cd /home/ubuntu/shopvivaliz-deploy/worktrees/browser-universal-mcp-20261008

python3 -c "
from mcp_desktop_tools import health_check
import json
result = health_check()
print(json.dumps(result, indent=2))
"
```

**Expected Response:**
```json
{
  "ok": true,
  "action": "health",
  "session_id": 1,
  "user": "LAPTOP-NIG4IFUU\\FRED",
  "width": 3840,
  "height": 1080,
  "surface": "windows_interactive"
}
```

### Step 3: Run Full Test Suite

```bash
python3 test-mcp-desktop-e2e.py
```

**Expected Output:**
```
==================================================
MCP Desktop Automation - E2E Test Suite
==================================================

[TEST 1] Health Check
--------------------------------------------------
✅ PowerShell and bridge available
Response: {...}
✅ PASSED: Health check successful

[TEST 2] Screenshot
--------------------------------------------------
✅ Screenshot captured: 1024.5 KB
✅ Base64 encoded (< 5MB)
✅ PASSED: Screenshot capture successful

[TEST 3] Click
--------------------------------------------------
✅ PASSED: Click at (960, 540) successful

[TEST 4] Type
--------------------------------------------------
✅ PASSED: Typed 14 characters

==================================================
Test Summary
==================================================
✅ Health Check
✅ Screenshot
✅ Click
✅ Type

Total: 4/4 passed
```

### Step 4: Validate Large Screenshot Handling

```python
python3 -c "
from mcp_desktop_tools import screenshot
import json
result = screenshot()
print(f'Screenshot size: {result.get(\"file_size_bytes\", 0) / 1024 / 1024:.1f} MB')
print(f'Has Base64: {\"image_b64\" in result}')
print(f'Has file URL: {\"image_b64_url\" in result}')
print(f'Full response: {json.dumps(result, indent=2)}')
"
```

### Step 5: Verify DOM Inspection (Browser MCP)

When browser is active, test DOM inspection:
```bash
# This requires additional browser MCP integration (documented separately)
# Current fix ensures desktop-level automation works; browser-level follows
```

### Step 6: Validate Fallback Behavior

Test automatic fallback when Base64 is > 5MB:
- Take 4K screenshot on multi-monitor setup
- Verify file path is returned instead of Base64
- Confirm `image_b64_truncated` flag is set

## Validation Checklist

Before marking this as complete:

- [ ] Health check passes (session_id, user, dimensions reported)
- [ ] Screenshot works (PNG saved, < 5MB encoded as Base64)
- [ ] Large screenshots return file URL (not Base64 truncation)
- [ ] Click automation works at various coordinates
- [ ] Type automation works without errors
- [ ] E2E test suite passes 4/4 tests
- [ ] No timeout errors on slow connections
- [ ] Session validation works correctly
- [ ] Multi-monitor coordinates handled properly

## Deployment

### To Current Release:
```bash
cd /home/ubuntu/shopvivaliz-deploy/current
cp /path/to/worktree/scripts/shopvivaliz-native-desktop-bridge.ps1 scripts/
cp /path/to/worktree/mcp-desktop-tools.py .
systemctl restart shopvivaliz-remote-control-mcp  # if applicable
```

### To Fred-Win Service:
The Desktop Commander runner will automatically use the updated bridge script on next execution.

## Troubleshooting

### "desktop_worker_timeout"
- Network connectivity issue or slow desktop responsiveness
- Timeout was increased to 60s; if still failing, check Fred-Win performance
- Run `Get-Process` on Fred-Win to check CPU/memory

### "desktop_screenshot_size_invalid"
- Screenshot either too small (< 100 bytes) or too large (> 12MB)
- Check monitor resolution: 3840x1080 with high color depth may exceed limit
- Consider implementing screenshot compression in Worker mode

### "interactive_user_missing"
- No user logged in interactively on Fred-Win
- Desktop bridge requires interactive session (not RDP without user)
- Ensure Fred user is logged in on LAPTOP-NIG4IFUU

### Python ImportError: "No module named 'mcp_desktop_tools'"
- Ensure working directory is correct
- Check PYTHONPATH includes worktree directory
- Run tests from worktree root only

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│ MCP Client (Claude Code Remote Control)                    │
└──────────────────────┬──────────────────────────────────────┘
                       │ HTTP/JSON request
                       ▼
┌─────────────────────────────────────────────────────────────┐
│ mcp-desktop-tools.py (Python Integration Layer)             │
│ - Validates parameters                                       │
│ - Manages temporary files                                    │
│ - Handles Base64 encoding/decoding                           │
└──────────────────────┬──────────────────────────────────────┘
                       │ PowerShell subprocess call
                       ▼
┌─────────────────────────────────────────────────────────────┐
│ shopvivaliz-native-desktop-bridge.ps1 (Dispatch Mode)       │
│ - Creates temporary request/response files                   │
│ - Validates interactive user                                │
│ - Spawns Worker task                                        │
└──────────────────────┬──────────────────────────────────────┘
                       │ Scheduled Task
                       ▼
┌─────────────────────────────────────────────────────────────┐
│ shopvivaliz-native-desktop-bridge.ps1 (Worker Mode)         │
│ - Executes in FRED's interactive session (Session 1)        │
│ - Captures screenshots via System.Drawing                   │
│ - Simulates mouse/keyboard via user32.dll P/Invoke          │
│ - Writes response JSON                                      │
└──────────────────────┬──────────────────────────────────────┘
                       │ File I/O (response.json)
                       ▼
┌─────────────────────────────────────────────────────────────┐
│ mcp-desktop-tools.py (Response Handler)                     │
│ - Reads response JSON                                        │
│ - Reads screenshot PNG (if present)                         │
│ - Encodes/returns result                                    │
└──────────────────────┬──────────────────────────────────────┘
                       │ JSON response
                       ▼
┌─────────────────────────────────────────────────────────────┐
│ MCP Client (Claude Code Remote Control)                    │
└─────────────────────────────────────────────────────────────┘
```

## Next Steps

1. ✅ **DONE**: PowerShell improvements
2. ✅ **DONE**: Python integration layer
3. ✅ **DONE**: E2E test suite
4. 🔄 **NEXT**: Execute E2E tests on Fred-Win
5. ⏳ **TODO**: Implement browser DOM inspection (separate task)
6. ⏳ **TODO**: Add Chrome automation via CDP (separate task)
7. ⏳ **TODO**: Fallback to Playwright for complex interactions (separate task)

## References

- Desktop Bridge: `scripts/shopvivaliz-native-desktop-bridge.ps1`
- MCP Tools: `mcp-desktop-tools.py`
- E2E Tests: `test-mcp-desktop-e2e.py`
- Desktop Commander Runner: `scripts/fredwin-desktop-commander-runner.ps1`

---

**Last Updated**: 2026-10-08  
**Commit**: 7cfb57c2f  
**Status**: Ready for E2E Validation
