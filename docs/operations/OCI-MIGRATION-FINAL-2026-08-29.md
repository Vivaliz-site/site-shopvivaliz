# OCI migration final state — 2026-08-29

## Control-plane verification

Fresh OCI control-plane inventory was executed from GitHub Actions using the repository's existing `OCI_CLI_*` secrets, without creating or modifying OCI resources.

Final instance state:

- `always-free-arm-1787907847-26` — `VM.Standard.A1.Flex` — `RUNNING`
- `shopvivaliz-free-a1` — `VM.Standard.A1.Flex` — `RUNNING`
- `shopvivaliz-ai` — `VM.Standard.E2.1.Micro` — `TERMINATED`
- `shopvivaliz-micro-2` — `VM.Standard.E2.1.Micro` — `TERMINATED`

Final boot-volume state:

- `shopvivaliz-ai (Boot Volume)` — 47 GB — `TERMINATED`
- `shopvivaliz-micro-2 (Boot Volume)` — 47 GB — `TERMINATED`
- `always-free-arm-1787907847-26 (Boot Volume)` — 100 GB — `AVAILABLE`
- `shopvivaliz-free-a1 (Boot Volume)` — 47 GB — `AVAILABLE`

Therefore the E2 decommission phase is already reflected as complete in the OCI control plane. No new paid resource was created by the verification.

## Backend A1 fresh verification

Host `always-free-arm-1787907847-26` was verified after the control-plane audit:

- sender processes: exactly `1`
- API health: HTTP `200`
- `mei-mg-email-api.service`: active
- `mei-mg-email-worker.service`: active
- `mei-mg-email-queue-replenisher.service`: active
- `mei-mg-email-monitor.service`: active
- Docker: active
- failed systemd units: `0`
- base-sync service: inactive after successful completion
- base-sync timer: enabled and active
- latest base-sync: run `25`, `success`, `rows_after=15987705`
- companies: `15987705`
- sends: `79225`
- Desktop Commander on this surviving A1: active

`mei-mg-email-ndr-guard.service` is inactive; Microsoft Graph `Mail.Read` remains the documented external gate because existing app-only authority returned HTTP 403 `Authorization_RequestDenied`. No retry or privilege escalation was attempted during final verification.

## Public site verification

The public apex was freshly reachable and returned the current Shop Vivaliz storefront after E2 termination. `www` redirects to the apex as expected.

## Safety / residual state

- Both A1 instances and their boot volumes remain intact.
- Both E2 instances and their boot volumes are terminated.
- No Cloudflare/dev mutation was performed during final control-plane verification.
- No OCI resource was created by the diagnostics.
- Existing migration backups were not touched.
- Microsoft Graph `Mail.Read` remains the only known external authorization gate from the migration thread.

## Evidence

- temporary OCI diagnostic workflow run `33269780416` / job `99146077792`: successful read-only inventory
- extended OCI diagnostic workflow run `33269848431` / job `99146258726`: successful all-instance and boot-volume inventory
- backend A1 fresh runtime verification performed immediately after the OCI inventory
