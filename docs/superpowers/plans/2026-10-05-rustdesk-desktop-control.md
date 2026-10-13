# RustDesk Desktop Control Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add constrained RustDesk desktop control for canonical Windows support hosts through the backend Remote Control MCP.

**Architecture:** Keep all GUI actuation on the backend X11 session and use the existing RustDesk client as the remote-desktop transport. Add a distinct `desktop_*` tool family with strict host/window isolation, stdin-only typing, redacted audit records, and runtime-only RustDesk host IDs.

**Tech Stack:** Python 3 Remote Control MCP, unittest, X11/xdotool/scrot, RustDesk, systemd installer/runtime environment.

**Spec:** `docs/superpowers/specs/2026-10-05-rustdesk-desktop-control-design.md`

## Global Constraints

- Only `Fred-Win` and `KOCEPSV` are valid desktop targets.
- Never expose/persist typed text, RustDesk passwords, router passwords, cookies, tokens or arbitrary target IDs.
- Existing `browser_*` semantics and ChatGPT continuity ownership remain unchanged.
- GUI writes fail closed on missing or ambiguous RustDesk windows.
- Production releases remain immutable.

## Review Focus

- Missing RustDesk host ID must fail closed; test in Task 1.
- Multiple RustDesk windows must prevent click/type; test in Task 2.
- Negative or out-of-window coordinates must be rejected; test in Task 2.
- Typed text must never appear in argv/audit/stdout; test in Tasks 1 and 2.
- Existing browser MCP behavior must remain unchanged; regression suite in Task 4.

---

### Task 1: MCP tool contracts and audit redaction

**Files:**
- Modify: `tests/remote-control-mcp-test.py`
- Modify: `remote-control-mcp/server.py`

**Interfaces:**
- Produces: `desktop_health`, `desktop_open`, `desktop_screenshot`, `desktop_click`, `desktop_type` MCP tools.
- Produces: `validate_desktop_host(host: str) -> dict[str, Any]`.
- Produces: runtime host-ID resolver that accepts only canonical host names.

- [ ] **Step 1: Write failing tests** for tool names, schemas/annotations, host allowlist, missing ID failure and audit redaction of `desktop_type.text`.
- [ ] **Step 2: Run targeted unittest methods and confirm RED.**
- [ ] **Step 3: Implement the minimal schemas, host validation, runtime ID resolver and audit sanitization.**
- [ ] **Step 4: Run targeted tests and confirm GREEN.**
- [ ] **Step 5: Commit** `feat(remote-control): add desktop tool contracts`.

### Task 2: Constrained RustDesk window helpers

**Files:**
- Modify: `tests/remote-control-mcp-test.py`
- Modify: `remote-control-mcp/server.py`

**Interfaces:**
- Produces: `desktop_health_command(host_id: str) -> str`.
- Produces: `desktop_open_command(host_id: str) -> str`.
- Produces: `desktop_screenshot_command(host_id: str) -> str`.
- Produces: `desktop_click_command(host_id: str, x: int, y: int, button: str, clicks: int) -> str`.
- Produces: `desktop_type_invocation(host_id: str, press_enter: bool) -> list[str]` using stdin for text.

- [ ] **Step 1: Write failing tests** proving window resolver confinement, missing/ambiguous failure, bounds validation, screenshot window-only behavior and stdin-only typing.
- [ ] **Step 2: Run targeted tests and confirm RED.**
- [ ] **Step 3: Implement minimal X11/RustDesk helper commands with fixed display/user and no arbitrary selectors.**
- [ ] **Step 4: Wire `execute_tool` to helper commands and preserve bounded/redacted results.**
- [ ] **Step 5: Run targeted tests and confirm GREEN.**
- [ ] **Step 6: Commit** `feat(remote-control): control RustDesk desktop window`.

### Task 3: Runtime configuration and deployment contract

**Files:**
- Modify: `deploy/systemd/shopvivaliz-remote-control-mcp.service`
- Modify: `scripts/setup-remote-control-access.sh`
- Modify: `remote-control-mcp/SPEC.md`
- Modify: `docs/knowledge/host-access.md`
- Modify: `tests/remote-control-mcp-test.py`

**Interfaces:**
- Consumes: `SHOPVIVALIZ_RUSTDESK_HOST_IDS` runtime configuration.
- Produces: installed service with desktop prerequisites validated but no host IDs logged.

- [ ] **Step 1: Write failing deployment-contract tests** requiring fixed display/user configuration, protected runtime host-ID source and no secret/ID echo.
- [ ] **Step 2: Run tests and confirm RED.**
- [ ] **Step 3: Update installer/service/docs minimally; do not hardcode IDs in Git.**
- [ ] **Step 4: Run deployment-contract tests and confirm GREEN.**
- [ ] **Step 5: Commit** `docs(remote-control): document RustDesk desktop path`.

### Task 4: Full verification, PR, merge and immutable promotion

**Files:**
- No new source files unless verification finds a defect.

**Interfaces:**
- Consumes all previous tasks.
- Produces merged `main`, immutable Remote Control MCP promotion and E2E evidence.

- [ ] **Step 1: Run `python3 -m unittest tests.remote-control-mcp-test` and relevant RustDesk/setup contract tests.**
- [ ] **Step 2: Run `git diff --check` and confirm clean working tree except intended commits.**
- [ ] **Step 3: Push branch and open PR; wait for required checks/review.**
- [ ] **Step 4: Rebase/update from current `main` if it advanced; rerun tests.**
- [ ] **Step 5: Merge after gates pass.**
- [ ] **Step 6: Promote the merged SHA through the canonical immutable Remote Control deployment path.**
- [ ] **Step 7: Runtime E2E: `desktop_health(KOCEPSV)`, `desktop_open(KOCEPSV)`, window-only screenshot, harmless focus/click, then verify browser health and KOCEPSV host health.**
- [ ] **Step 8: Resume the Wi-Fi audit only if the desktop E2E is green; otherwise rollback the Remote Control release and preserve evidence.**
