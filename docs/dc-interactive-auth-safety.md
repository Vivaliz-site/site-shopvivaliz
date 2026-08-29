# Fred-Win Desktop Commander interactive provider authorization

This path exists only for the provider-required `Verify Device` step when the canonical S4U Desktop Commander task is fail-closed with `AUTH_REQUIRED=true` and the FRED user already has an interactive Windows session.

Safety boundaries:

- keep the existing `.desktop-commander-device/device.json` file in place and never read or copy its contents;
- never generate or replace a device UUID;
- never print provider URLs, device codes, cookies, access tokens, refresh tokens, or session blobs;
- stop the canonical S4U task before the interactive worker starts so there is only one logical remote launcher;
- invoke only the UI Automation button whose accessible name is exactly `Verify Device`;
- require a newer device-state mtime, the provider-connected marker, and removal of the auth cooldown before considering authorization complete;
- kill the temporary interactive launcher tree before restarting the canonical S4U task;
- leave the canonical task disabled on authorization failure so recovery remains fail-closed;
- hide the persistent Desktop Commander and Fred relay scheduled tasks after successful recovery.
