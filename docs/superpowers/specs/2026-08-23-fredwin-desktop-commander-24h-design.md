# Fred-Win Desktop Commander 24h Design

## Goal
Keep the official Desktop Commander channel available on Fred-Win 24h after Windows boot, process crash, network interruption, and reconnect, without requiring user logon or repeated device-code approval while the provider still has a valid persistent session.

## Architecture
Fred-Win keeps two independent paths. The official Desktop Commander runs under the persistent FRED Windows profile and is supervised by a noninteractive Scheduled Task that can start at Windows boot using S4U. The existing private relay remains the recovery/diagnostic fallback and never replaces provider authentication or exposes the local MCP publicly.

## Root-cause hypothesis to test
Repeated device authorization is most likely caused by launching Desktop Commander under a different or ephemeral Windows profile/context, using a command that does not reuse its persisted auth state, or losing the provider session/cache between restarts. Provider-enforced reauthorization must not be bypassed.

## Required behavior
- Start the official Desktop Commander automatically at Windows boot under the same persistent FRED profile that performed authorization; no interactive logon may be required.
- Reuse provider-supported persistent local authentication state; never copy device codes, tokens, cookies, or session dumps into Git or persistent logs.
- Restart the process automatically after failure with a one-minute watchdog.
- Keep the existing private relay and reverse SSH as a separate fallback path.
- Add health/status diagnostics that distinguish official Desktop Commander availability from private-relay availability.
- Do not expose MCP services publicly or weaken provider authentication.
- If the provider requires reauthorization, stop repeated device-flow attempts and keep the private relay available for unattended maintenance.

## Implementation direction
1. Inspect process/task/profile/session-path state through the private relay without reading secret values.
2. Preserve `%USERPROFILE%\.desktop-commander-device\device.json` by always running under the persistent user profile.
3. Use a sanitized runner that discards raw provider output and records only lifecycle/auth-required markers.
4. Register/update `ShopVivaliz Desktop Commander 24h` with an `AtStartup` trigger, `LogonType S4U`, restart settings and a one-minute watchdog.
5. Have the existing Fred-Win bootstrap restore the official Desktop Commander task if it is missing, while keeping private MCP/tunnel recovery independent.
6. Verify normal start, forced process termination, automatic recovery and provider reconnect. If the provider itself invalidates the session, record that as an external policy boundary rather than bypassing it.

## Security constraints
No auth token, device code, cookie, session blob, private key, or full device identifier may be logged or committed. Raw provider output is not persisted. The official Desktop Commander remains provider-authenticated; the private relay stays bound to loopback and reverse SSH only.

## Success criteria
- Fresh relay diagnostic proves the Fred-Win private path is healthy.
- Official Desktop Commander startup task is present, starts at boot under the intended persistent Windows profile, and does not require an interactive user session.
- Killing the official Desktop Commander process is followed by automatic restart within the watchdog interval without user interaction while the provider session remains valid.
- A fresh official-channel status check succeeds after restart without a new device code.
- If provider policy requires reauthorization, the system detects it once, suppresses repeated device-flow loops, and keeps the private relay available for unattended maintenance.
