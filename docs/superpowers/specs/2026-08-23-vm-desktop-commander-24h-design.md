# VM Desktop Commander 24h Design

## Goal
Keep the official Desktop Commander channel available on the ShopVivaliz Ubuntu VM 24h, including after reboot, process crash, and network interruption, without depending on an interactive SSH session or repeated device-code approval while the provider session remains valid.

## Architecture
The VM uses a native `systemd` service for the official Desktop Commander process. The service runs under one persistent non-root user with a fixed `HOME`, persistent npm/cache/config directories, restart policy, startup on boot, and journald logs. SSH remains the recovery channel and must not be replaced by a public MCP endpoint.

## Required behavior
- Start Desktop Commander automatically at boot with `systemd`.
- Run under a persistent user/profile and preserve provider-supported local auth/session state.
- Set explicit `HOME`, `XDG_CONFIG_HOME`, `XDG_CACHE_HOME`, and npm cache locations when needed so restarts do not change the state directory.
- Use `Restart=always`, bounded restart delay, network-online ordering, and resource limits appropriate for the small VM.
- Expose no new public port and never weaken provider authentication.
- Distinguish process/service failure from provider reauthorization requirements.
- Keep SSH/systemd as the recovery path.

## Investigation before change
Inspect the current Desktop Commander process, user, command line, package location, npm cache, home/config paths, existing unit files, and recent logs. Record only paths, versions, booleans, PIDs, and service states; never print auth/session file contents.

## Implementation direction
1. Add sanitized VM diagnostic/status action using the existing GitHub Actions -> SSH route.
2. Identify the current official Desktop Commander command and the persistent Linux user that owns its provider state.
3. Add an idempotent installer that writes a `systemd` unit with fixed profile paths, startup/restart policy, and a noninteractive launch command.
4. Add sanitized status/restart/recovery actions.
5. Validate service start, forced process termination, automatic recovery, and boot-enabled state.
6. If provider policy invalidates the session, report the external authentication boundary instead of bypassing it.

## Security constraints
No provider token, device code, cookie, session blob, private key, or full device identifier may be logged or committed. Desktop Commander must remain provider-authenticated. No MCP service may be opened to the public internet for convenience.

## Success criteria
- `systemctl is-enabled` reports enabled for the Desktop Commander unit.
- `systemctl is-active` reports active after installation/start.
- The service runs under the intended persistent user with a fixed `HOME`.
- Killing the Desktop Commander child process is followed by automatic recovery without operator action while provider auth remains valid.
- A fresh status check after recovery shows the service/process healthy and no new device-code flow.
- SSH recovery remains available even if the provider requires reauthorization.
