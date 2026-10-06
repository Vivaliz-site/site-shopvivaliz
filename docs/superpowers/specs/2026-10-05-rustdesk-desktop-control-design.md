# RustDesk Desktop Control Design

## Goal
Add a narrow, auditable Remote Control MCP capability for graphical maintenance of canonical Windows support hosts through the existing RustDesk client on the backend VM. The immediate use case is operating the KOCEPSV desktop to administer LAN devices whose web interfaces are only reachable from the physical LAN, while keeping the agent/browser execution environment on the backend VM.

## Constraints
- Remote Control MCP remains the primary control plane.
- Browser automation remains on `always-free-arm-1787907847-26`; this feature is desktop/RustDesk control, not Windows browser automation.
- Only canonical Windows support hosts are valid targets: `Fred-Win` and `KOCEPSV`.
- No secret, RustDesk password, router password, clipboard payload, cookie, token, or typed text may be returned or persisted in audit logs.
- Mutating GUI actions must be bounded and fail closed when the expected RustDesk window is absent or ambiguous.
- Production release immutability rules are unchanged.
- Existing browser tools, browser profiles, CDP bindings and continuity ownership rules are not repurposed.

## Approaches considered

### A. Windows-resident GUI daemon
Run a helper inside the interactive Windows session and bridge screenshot/click/type over the existing reverse SSH path.

Rejected for the first implementation because it introduces a new daemon, Windows session-token management, a new RPC boundary and additional secret-bearing state on both support hosts.

### B. Reuse the existing `browser_*` tools
Treat a RustDesk window as another browser target and relax current browser checks.

Rejected because current browser tools intentionally guarantee browser-window containment and authenticated browser-session semantics. Mixing desktop control into them would weaken those invariants and couple unrelated continuity locks to Windows maintenance.

### C. Backend RustDesk window control — selected
The backend already has RustDesk installed and configured for the private ShopVivaliz server. Add a separate `desktop_*` tool family that controls only RustDesk windows on the backend X11 session. RustDesk remains the transport of pixels/input to Windows; Remote Control supplies constrained screenshot/click/type primitives on the backend side.

This preserves the existing architectural boundary: agents operate from the backend VM, Windows remains a support endpoint, and RustDesk remains the GUI path.

## Architecture

The Remote Control MCP server gains four tools:

- `desktop_health(host)` — read-only. Validates that the target is an allowed Windows support host, the backend graphical session is reachable, RustDesk is installed, and at most one matching RustDesk remote-session window exists.
- `desktop_open(host)` — mutating. Starts or focuses a RustDesk connection for the selected canonical host using a host-to-RustDesk-ID mapping supplied by protected runtime configuration. It never accepts an arbitrary RustDesk ID from the caller.
- `desktop_screenshot(host)` — read-only. Captures only the active RustDesk remote-session window and returns PNG bytes/base64 metadata through the existing MCP result channel. It must not capture unrelated desktop windows.
- `desktop_click(host,x,y,button,clicks)` and `desktop_type(host,text,press_enter)` — mutating. Operate only when exactly one RustDesk session window for the requested host is active. Coordinates are validated against that window's client area. Typed text is passed via stdin and redacted from audit records.

The host-to-RustDesk-ID mapping is runtime-only configuration (`SHOPVIVALIZ_RUSTDESK_HOST_IDS` JSON or equivalent protected file) and is never printed by health/status tools. The initial deployment may populate only KOCEPSV; an absent mapping produces `rustdesk_host_id_unavailable`, not a fallback to arbitrary IDs.

## Window isolation

All GUI commands execute as the canonical graphical user on `DISPLAY=:0` (or a single configured display) and use `xdotool`/`scrot`/ImageMagick already available on the backend. A helper resolves RustDesk windows by process/class/title and requires exactly one remote-session window associated with the requested target. The helper rejects the tray window, connection manager and ambiguous windows.

`desktop_click` converts caller coordinates relative to the RustDesk client area into absolute screen coordinates and rejects values outside the current client rectangle. `desktop_type` requires the RustDesk window to be active and never includes text in argv, stdout, stderr or audit args.

## Security and audit

- Target host is selected from the existing named-host allowlist and additionally restricted to Windows support hosts.
- `desktop_open`, `desktop_click` and `desktop_type` are marked mutating/destructive in MCP annotations; `desktop_health` and `desktop_screenshot` are read-only.
- Audit records replace `text` with `[REDACTED]` and never serialize stdin.
- Tool schemas do not accept arbitrary executable paths, displays, window selectors, RustDesk IDs or shell fragments.
- No tool exposes the unattended RustDesk password. Connection authentication remains RustDesk's responsibility using already provisioned protected state.
- A failed/missing/ambiguous GUI state returns an error before any click/type.

## Testing

Unit/contract tests must prove:
- only `Fred-Win` and `KOCEPSV` are accepted;
- schemas and annotations are correct;
- arbitrary RustDesk IDs/window selectors are impossible to pass;
- screenshot command is constrained to a RustDesk window;
- click rejects negative/out-of-bounds coordinates;
- type uses stdin and audit redacts text;
- missing/ambiguous window fails closed;
- existing browser tool tests remain green.

Runtime E2E on the backend must prove:
1. `desktop_health(KOCEPSV)` sees the graphical environment and RustDesk client;
2. `desktop_open(KOCEPSV)` opens/focuses the remote session without exposing credentials;
3. `desktop_screenshot(KOCEPSV)` returns a frame containing only the RustDesk window;
4. a harmless click/focus round trip works;
5. browser tools and KOCEPSV reverse SSH remain healthy afterward.

## Rollback
The feature is additive. Rollback is the previous immutable Remote Control MCP release. No Windows service, router configuration or browser profile is modified by installing the MCP change.
