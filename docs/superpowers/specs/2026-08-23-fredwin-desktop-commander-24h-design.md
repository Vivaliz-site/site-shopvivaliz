# Fred-Win Desktop Commander 24h Design

## Goal
Keep the official Desktop Commander channel available on Fred-Win after logon, process crash, network interruption, and reconnect, without requiring repeated device-code approval when the provider still has a valid persistent session.

## Architecture
Fred-Win keeps two independent paths. The official Desktop Commander runs under the persistent FRED Windows profile and is supervised locally. The existing private relay remains the recovery/diagnostic fallback and never replaces provider authentication or exposes the local MCP publicly.

## Root-cause hypothesis to test
Repeated device authorization is most likely caused by launching Desktop Commander under a different or ephemeral Windows profile/context, using a command that does not reuse its persisted auth state, or losing the provider session/cache between restarts. Provider-enforced reauthorization must not be bypassed.

## Required behavior
- Start the official Desktop Commander automatically under the same persistent FRED profile that performed authorization.
- Reuse provider-supported persistent local authentication state; never copy device codes, tokens, cookies, or session dumps into Git.
- Restart the process automatically after failure and after Windows logon.
- Keep the existing private relay and reverse SSH as a separate fallback path.
- Add health/status diagnostics that distinguish official Desktop Commander availability from private-relay availability.
- Do not expose MCP services publicly or weaken provider authentication.

## Implementation direction
1. Inspect the current process tree, scheduled tasks, HOME/USERPROFILE/APPDATA/LOCALAPPDATA, Desktop Commander command line, package/cache paths, and recent logs through the private relay.
2. Identify where the official Desktop Commander stores its persistent auth/session state and whether the startup task uses the same profile.
3. Add an idempotent Windows supervisor/startup script that starts Desktop Commander in the correct profile context and restarts it when unhealthy.
4. Register/update a Scheduled Task at logon with restart-on-failure settings and a watchdog trigger, without embedding secrets.
5. Add allowlisted relay diagnostics for status/start/restart verification only.
6. Verify: normal start, forced process termination, supervisor recovery, and network/tunnel-independent provider reconnect. If the provider itself invalidates the session, record that as an external policy boundary rather than bypassing it.

## Security constraints
No auth token, device code, cookie, session blob, private key, or full device identifier may be logged or committed. The official Desktop Commander remains provider-authenticated; the private relay stays bound to loopback and reverse SSH only.

## Success criteria
- Fresh relay diagnostic proves the Fred-Win private path is healthy.
- Official Desktop Commander startup task is present and runs under the intended Windows user profile.
- Killing the official Desktop Commander process is followed by automatic restart without user interaction while the provider session remains valid.
- A fresh official-channel health/status check succeeds after restart without a new device code.
- If provider policy requires reauthorization, the system detects and reports that state cleanly while the private relay remains available for unattended maintenance.
