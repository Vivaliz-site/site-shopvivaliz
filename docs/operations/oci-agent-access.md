# OCI access for agents

This repository uses a dedicated Oracle Cloud Infrastructure API signing key for agent operations. The credential is separate from personal OCI credentials and must never be committed, printed, copied into logs, issues, PRs, prompts, or workflow output.

## Canonical identity

- Region: `sa-saopaulo-1`
- Agent API-key fingerprint: `81:3a:f0:0c:25:69:73:d1:61:f3:0c:8d:3b:cc:f2:ed`
- Profile name: `AGENTS`
- Private key: local protected storage only; never Git

Agents must use the `AGENTS` identity and must not fall back to a user's `DEFAULT`/personal OCI profile.

## Host access

### Fred-Win (`LAPTOP-NIG4IFUU`)

```powershell
oci iam region list --config-file C:\Users\FRED\.oci\agents\config --profile AGENTS
```

### VM1 (`shopvivaliz-ai`)

```bash
/home/ubuntu/.local/oci-cli-venv/bin/oci --config-file /home/ubuntu/.oci/agents/config --profile AGENTS iam region list
```
### VM2 (`shopvivaliz-micro-2`)

VM2 has a local signed-request helper and the protected `AGENTS` profile:

```bash
/home/ubuntu/.local/bin/oci-agent-request GET https://identity.sa-saopaulo-1.oci.oraclecloud.com/20160918/regions
```

Use OCI's documented REST endpoints with this helper when the full CLI is unavailable. Production remains on VM2; OCI access does not change deployment targeting.

### DESKTOP-KOCEPSV

The OCI private key is intentionally **not duplicated** to this host. Agents use the verified SSH path to VM1, which holds the protected `AGENTS` profile:

```powershell
powershell -NoProfile -File C:\Users\user\bin\oci-agent.ps1 iam region list --output table
```

The wrapper uses the existing host key verification and SSH identity. Do not replace it with `StrictHostKeyChecking=no` or copy the OCI private key to this host.

## Security and rotation

- Never commit `*.pem`, OCI session tokens, browser tokens, or `security_token_file` contents.
- Never echo private-key material or authentication headers.
- Keep key/config permissions restricted to the operating account.
- If compromise is suspected, revoke the API key by fingerprint in OCI, generate a replacement, update protected host storage, then revalidate all four host paths.
- A successful validation is a real authenticated read such as region listing; file presence alone is not proof of access.
