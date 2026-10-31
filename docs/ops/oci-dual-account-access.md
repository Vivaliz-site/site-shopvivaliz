## Arquitetura verificada em hosts — 2026-10-29

O destino de producao web/deploy e `marina-a1-4c24g` na tenancy `ESPOSA`; o backend/controller e `always-free-arm-1787907847-26` na tenancy `PRINCIPAL`. O proprietario informou exclusao de `shopvivaliz-free-a1`; a rota SSH da origem falhou, mas o lifecycle OCI `TERMINATED` nao foi consultado nessa rodada. A prova de login nas duas tenancies em Actions em 2026-10-16 abaixo nao prova inventario atual, permissao sudo/MCP, custo nem estado de instancias. Rotas e MCPs atualizados: `docs/knowledge/current-topology-and-access-2026-10-29.md`.

## Verified independent access (2026-10-16, 13:35 UTC)

GitHub Actions workflow `.github/workflows/oci-dual-api-validation.yml` executed successfully without requiring the laptop after PRs #2979, #2980 and #2981. Verified run: https://github.com/Vivaliz-site/site-shopvivaliz/actions/runs/38908995411

Sanitized actual job output:

```text
OCI_PRINCIPAL_AUTH=PASS
OCI_ESPOSA_AUTH=PASS
OCI_BOTH_ACCOUNTS_APTO=PASS
```

Status: **APTO for independent, read-only OCI API authentication through GitHub Actions**. Do not conflate this proof with on-VM MCP native OCI administration or VM provisioning: on-VM secrets were not deployed and provisioning actions have not been tested. The GitHub Actions runner uses protected GitHub secrets, temporary private-key files with restricted mode, and the OCI Python SDK. Run manually via `gh workflow run oci-dual-api-validation.yml -R Vivaliz-site/site-shopvivaliz --ref main`; inspect run checks and logs. Future privileged operations require a separately reviewed least-privilege workflow with appropriate approvals.

# OCI Dual Account Access — ShopVivaliz

Updated: 2026-10-16. Operational owner: ShopVivaliz infrastructure.

## Goal

Administer the existing PRIMARY Oracle Cloud tenancy and the independently owned ESPOSA tenancy without relying on the Windows laptop. Never assume that an API token is a signing key. Do not log, commit, or print private keys.

## Verified facts

- Windows laptop `LAPTOP-NIG4IFUU`: OCI API authentication succeeded separately for both accounts, home region `sa-saopaulo-1`.
- GitHub Actions repository: `Vivaliz-site/site-shopvivaliz`.
- Ten Actions secrets confirmed: `OCI_PRINCIPAL_USER`, `OCI_PRINCIPAL_TENANCY`, `OCI_PRINCIPAL_FINGERPRINT`, `OCI_PRINCIPAL_REGION`, `OCI_PRINCIPAL_PRIVATE_KEY`; and their five `OCI_ESPOSA_*` counterparts. Secrets are opaque: listing confirms existence, not correctness of values.
- Principal identity authenticated successfully inside GitHub Actions workflow run 38900218329; the backend was uniquely identified through the OCI Resource Search API.
- GitHub-hosted runner could not SSH to the backend's public port 22 (network timeout). This was a transport issue, not proof of an OCI credential failure.
- Canonical backend `always-free-arm-1787907847-26`: Remote Control MCP reachable; OCI CLI `/opt/shopvivaliz-oci-cli/bin/oci` version 3.95.0 installed. Account directories `/home/ubuntu/.oci/principal` and `/home/ubuntu/.oci/esposa` exist with mode 0700.
- Backend `/home/ubuntu/.oci/config` was empty. Neither account's backend authentication has been proved.
- Oracle Cloud Agent Snap service and updater were reported active on the backend. This does not establish that the Run Command plugin is enabled or functional.
- Backend has Tailscale, but laptop-to-backend Tailscale SSH was not authenticated in the tested session.
- An RSA key pair for a prospective encrypted bootstrap was generated under `/etc/shopvivaliz/oci-deploy/` on the backend, with its private key kept there. It has not been used to deploy secrets.
- Disk occupancy on backend reached about 90% (9.7 GB free) when last checked. Recheck before new installations.

## Existing implementation and references

- PR #2977 merged workflow `.github/workflows/oci-dual-backend-access.yml`.
- Workflow run [38900218329](https://github.com/Vivaliz-site/site-shopvivaliz/actions/runs/38900218329) **failed** at SSH transport after passing principal authentication and backend identification.
- Existing diagnostic: `.github/workflows/backend-oci-agent-readonly-diagnostic.yml` demonstrates an OCI Instance Agent command path for read-only tasks.
- A new workflow for transmitting OCI private keys was proposed but blocked by platform safety controls and **was not deployed**. Do not imply that encrypted delivery is installed.

## Required next actions

1. Confirm Oracle Run Command plugin via OCI instance-agent plugin API and ensure tenancy IAM authorization and explicit approved deployment channel.
2. Deploy both identities using a reviewed secret-delivery mechanism (for example, approved secret manager integration or an authorized machine identity), not plaintext shell arguments, logs, repository files, or command outputs.
3. Keep principal and spouse identities fully isolated. Preserve all existing `OCI_CLI_*` secrets; never overwrite them with second-account values.
4. Require backend directories mode 0700 and private keys/config mode 0600, with least privilege. Avoid storing secrets in agent instructions.
5. From the backend, individually run authenticated OCI region-subscription read-only checks using each separate profile.
6. Prove independent operation while the laptop is disconnected; verify no fallback to laptop or desktop.
7. Record sanitized proof with timestamp and per-account PASS/FAIL, then declare APTO only if both independent checks pass.
8. Do not provision paid resources or create instances without explicitly verifying account identity, compartment, quotas, and Always Free billing conditions.

## Safe checks

```bash
/opt/shopvivaliz-oci-cli/bin/oci --version
ls -ld /home/ubuntu/.oci/principal /home/ubuntu/.oci/esposa
sudo snap services oracle-cloud-agent
```

Once securely provisioned and approved, run independently:

```bash
sudo -u ubuntu /opt/shopvivaliz-oci-cli/bin/oci iam region-subscription list --config-file /home/ubuntu/.oci/principal/config --profile PRINCIPAL --output table
sudo -u ubuntu /opt/shopvivaliz-oci-cli/bin/oci iam region-subscription list --config-file /home/ubuntu/.oci/esposa/config --profile ESPOSA --output table
```

## Agent operational contract

Any ShopVivaliz agent resuming this task should first read this file, validate current state, avoid claims of background work, honor GitHub branch protections and approvals, and report BLOCKED with concrete technical evidence rather than pretending the two accounts are usable. No secrets should appear in chat, audit output, CI logs, or PR diffs.
