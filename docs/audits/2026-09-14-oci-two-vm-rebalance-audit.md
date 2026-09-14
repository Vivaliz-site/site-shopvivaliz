# OCI Two-VM Rebalance — Final Audit

Date: 2026-09-14
Region: sa-saopaulo-1

## Capacity
- `always-free-arm-1787907847-26`: 2 OCPU / 12 GiB RAM.
- `shopvivaliz-free-a1`: 2 OCPU / 12 GiB RAM.
- Aggregate A1 allocation remains 4 OCPU / 24 GiB.
- Both hosts were rebooted serially and validated after resize.

## Workload placement
- Main host: Solange production, Solange staging, MEI backend/workers, primary runners and verified backups.
- Secondary host: ShopVivaLiz integrations, SAFE-T, ML/RR persistent database and secondary CI runners.
- Closed PR/homologation environments were removed only after verified backups.

## Network and security
- Backend VM has no public IP and uses OCI NAT Gateway for outbound traffic.
- Site origin remains public by design behind Cloudflare authenticated origin access.
- Direct origin SSH is restricted to OCI Bastion.
- Direct origin HTTPS is restricted to Cloudflare IPv4 ranges; public HTTP/80 was removed.
- VCN Flow Logs are enabled with 30-day retention.
- Cloud Guard is enabled with root target and managed Configuration, Activity, Threat and Instance Security recipes.
- OCI Vulnerability Scanning has one active daily recipe and one active compartment target.
- OCI Bastion is active.

## Monitoring and cost controls
- Six OCI Monitoring alarms cover CPU, memory and missing metrics across both VMs.
- ONS email subscription is ACTIVE.
- Monthly budget was reduced to BRL 10.
- Historical BRL 8.39 spend was traced to excess Block Volume usage that is no longer present.

## Backups
- Pre-resize physical snapshots for Solange production and staging were uploaded to Object Storage with SHA-256 manifests.
- Recurring logical backups use PostgreSQL custom format and validate with `pg_restore -l` before upload.
- Main profile covers Solange production, Solange staging and MEI email database.
- Secondary profile covers the persistent `mlrr_phase3` database and excludes ephemeral CI databases.
- Backup objects are host-scoped in Object Storage and remote size is verified after upload.
- Weekly orchestration is versioned in GitHub Actions and pinned to the `mei-backend` and `mei-ci` runner labels.

## Preview lifecycle
- Preview cleanup is allowlisted to PR-shaped Solange projects only.
- Production and `solange-client-demo` are explicitly protected.
- Cleanup requires the GitHub PR to be closed and the environment to be at least 48 hours old.
- Scheduler runs hourly in a visible Docker container with `restart=unless-stopped`, 0.1 CPU and 96 MiB memory limits.

## Verification expectations
- Public ShopVivaLiz domain must return HTTP 200 through Cloudflare.
- Direct origin 443 and 22 from non-approved sources must be blocked.
- Both runners, Docker and critical database containers must remain active after reboots.
- Backup tests and preview TTL tests must pass before integration.
