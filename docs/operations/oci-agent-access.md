# OCI access for agents

This repository uses a dedicated Oracle Cloud Infrastructure API signing key for agent operations. The credential is separate from personal OCI credentials and must never be committed, printed, copied into logs, issues, PRs, prompts, or workflow output.

## Canonical identity

- Region: `sa-saopaulo-1`
- Agent API-key fingerprint: `81:3a:f0:0c:25:69:73:d1:61:f3:0c:8d:3b:cc:f2:ed`
- Profile name: `AGENTS`
- Private key: local protected storage only; never Git

Agents must use the `AGENTS` identity and must not fall back to a user's `DEFAULT`/personal OCI profile.

## Current VM targets

- `vm1`: `shopvivaliz-free-a1`
- `vm2`: `always-free-arm-1787907847-26`
- Canonical OCI controller: Fred-Win (`LAPTOP-NIG4IFUU`)

The old `shopvivaliz-ai` and `shopvivaliz-micro-2` names are historical and must not be used as current VM targets.

## Canonical direct shell via OCI Run Command

On Fred-Win the protected profile remains at:

```text
C:\Users\FRED\.oci\agents\config
```

Use the installed helper:

```powershell
sv-oci-vm-run vm1 "hostname; id; pwd"
sv-oci-vm-run vm2 "hostname; id; pwd"
```

Equivalent full names are accepted:

```powershell
sv-oci-vm-run shopvivaliz-free-a1 "systemctl --failed"
sv-oci-vm-run always-free-arm-1787907847-26 "docker ps"
```

The helper uses OCI Compute Instance Run Command. It creates the command with the `AGENTS` profile, polls execution to a terminal lifecycle state, prints the remote stdout, and exits with the remote exit code. It does not require SSH or an inbound port to be available.

## Access order for agents

1. Remote Desktop Commander directly to the current VM when its device is online.
2. SSH when host identity and key authentication have been verified. Do not use `StrictHostKeyChecking=no`.
3. OCI Compute Instance Run Command through Fred-Win and the `AGENTS` profile.
4. OCI serial console only as the last recovery channel.

Do not duplicate the OCI private key to either VM merely to make automation easier. The controller model keeps the signing key on the protected host and lets Oracle Cloud Agent execute the requested script inside the target VM.

## Verified state on 2026-09-06

Both ARM VMs have Oracle Cloud Agent snap enabled and active. The `Compute Instance Run Command` plugin reports `RUNNING` for the instances. Real commands created through the OCI API completed as `SUCCEEDED` with exit code `0` on both targets.

The plugin executes Linux scripts as user `ocarun` (UID 999) by default. Do not assume root. Administrative commands require an already-authorized sudo path or an explicit least-privilege sudoers policy for `ocarun`; do not bypass host controls.

## Validation

A file existing on disk is not proof of access. Validate the signed OCI identity and then validate a real command:

```powershell
oci iam region list --config-file C:\Users\FRED\.oci\agents\config --profile AGENTS
sv-oci-vm-run vm1 "echo OCI_RUN_COMMAND_OK; hostname; id -u"
sv-oci-vm-run vm2 "echo OCI_RUN_COMMAND_OK; hostname; id -u"
```

For Run Command, `ACCEPTED` only means the service accepted the request. Completion requires terminal state `SUCCEEDED` plus the expected stdout and exit code `0`.

If execution does not progress, confirm plugin status and inspect:

```text
/var/log/oracle-cloud-agent/plugins/runcommand/runcommand.log
```

Do not stop Oracle Cloud Agent or the Run Command plugin from a Run Command task.

## Security and rotation

- Never commit `*.pem`, OCI session tokens, browser tokens, or `security_token_file` contents.
- Never echo private-key material or authentication headers.
- Never send passwords, private keys, API tokens, or other confidential material as plain Run Command text.
- Keep key/config permissions restricted to the operating account.
- If compromise is suspected, revoke the API key by fingerprint in OCI, generate a replacement, update protected host storage, then revalidate signed OCI access and both VM Run Command paths.
- Prefer OCI Vault/Object Storage protected workflows when a command genuinely needs sensitive input or output.
