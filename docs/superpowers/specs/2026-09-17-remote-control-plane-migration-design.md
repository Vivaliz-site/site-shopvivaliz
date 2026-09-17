# Remote Control Plane Migration Design

Date: 2026-09-17
Status: Proposed and user-approved in chat
Scope: Replace Desktop Commander as the primary operational control path while preserving it as fallback.

## Context

Desktop Commander remote usage for the currently authenticated account is exhausted (`remote_calls_left_pct=0`). The ShopVivaliz environment already has a private, auditable control plane based on GitHub Actions, self-hosted runners, private SSH inside the OCI VCN, and reverse SSH relays for Windows hosts.

The migration must preserve existing production behavior, avoid public MCP exposure, avoid editing active releases directly, and keep current Windows relay tasks running.

## Goals

- Make GitHub Actions + existing private relays the primary remote-control path.
- Keep Desktop Commander installed only as a fallback, not a runtime dependency.
- Preserve the canonical production topology and host roles.
- Keep Fred-Win and DESKTOP-KOCEPSV reachable through private loopback relays.
- Preserve immutable deployment rules for `/home/ubuntu/shopvivaliz-deploy`.
- Keep secrets in protected runtime/secret stores and never expose their content.
- Provide verifiable health checks for each remote path.

## Non-goals

- Do not expose MCP or SSH directly to the public internet.
- Do not re-enable historical Cloudflare relay endpoints.
- Do not replace the existing OCI VCN architecture.
- Do not remove Desktop Commander immediately.
- Do not edit `current/` or an active release directory in production.

## Canonical Architecture

```text
ChatGPT / operator
  -> GitHub
  -> GitHub Actions / self-hosted runners
     -> shopvivaliz-free-a1 (site/deploy)
        -> local execution / private SSH where needed
     -> always-free-arm-1787907847-26 (backend/relay)
        -> private VCN execution
        -> 127.0.0.1:5557 -> reverse SSH -> LAPTOP-NIG4IFUU / Fred-Win MCP
        -> 127.0.0.1:5558 -> reverse SSH -> DESKTOP-KOCEPSV MCP
```

Desktop Commander remains installed but is outside the primary request path.

## Host Roles

### shopvivaliz-free-a1

- Production web/deploy host.
- Canonical deploy root: `/home/ubuntu/shopvivaliz-deploy`.
- Never edit `current/` or the active release directly.
- Administrative jobs should prefer the self-hosted deploy runner and local/private SSH paths.

### always-free-arm-1787907847-26

- Backend, MEI, M365, and Windows relay host.
- Private IP: `10.0.1.38`.
- No direct public SSH dependency.
- Hosts the private relay endpoints consumed by administrative workflows.

### LAPTOP-NIG4IFUU / Fred-Win

- Canonical relay path uses loopback port `5557` on the backend A1.
- Reverse SSH tunnel terminates on the Windows host MCP at `127.0.0.1:5557`.
- Existing scheduled relay task remains enabled.

### DESKTOP-KOCEPSV

- Canonical relay path uses loopback port `5558` on the backend A1.
- Reverse SSH tunnel terminates on the Windows host MCP.
- Existing `ShopVivaliz DESKTOP-KOCEPSV Relay 24h` task remains enabled.

## Primary Control Workflow

The primary operational sequence is:

1. Resolve the target host by role.
2. Run a health probe through the existing private path.
3. Execute only an allowlisted administrative action.
4. Record success/failure evidence from the workflow run.
5. Escalate to OCI Bastion or Desktop Commander only if the primary path is unavailable.

For Fred-Win, the existing `.github/workflows/fred-win-remote-action.yml` and `ops/fredwin-request.json` pattern is the reference implementation. KOCEPSV should follow the same contract style with a distinct relay port and target identity.

## Action Model

Remote Windows actions must remain allowlisted. Arbitrary shell payloads are not part of the design.

Each supported action should have:

- a stable action name;
- explicit target host;
- command body stored in version-controlled workflow logic or a version-controlled script;
- bounded timeout;
- structured output markers where practical;
- no secret-value echoing.

## Health Contracts

### Fred-Win

A healthy path requires the canonical route to return HTTP 200 and a response compatible with:

```text
status=ok
environment=fred-win
mcp_version=<present>
```

### DESKTOP-KOCEPSV

A healthy path must similarly prove:

```text
status=ok
environment=desktop-kocepsv
mcp_version=<present>
```

If the route cannot be tested, the result is INCONCLUSIVE, not INACTIVE.

## Migration Steps

1. Inventory all current Desktop Commander operational dependencies in workflows, scripts, docs, and monitoring.
2. Classify each dependency as:
   - replace with GitHub Actions/private relay;
   - keep as fallback only;
   - remove as obsolete.
3. Validate Fred-Win private relay health through the canonical workflow.
4. Validate DESKTOP-KOCEPSV private relay health through its canonical path.
5. Add or normalize allowlisted workflow actions required for routine operations.
6. Update host-access and operational docs so primary access order becomes GitHub/private relay -> OCI Bastion -> Desktop Commander fallback.
7. Update monitors so lack of Desktop Commander quota does not mark hosts unhealthy.
8. Keep Desktop Commander services/tasks present but non-primary.
9. Run end-to-end validation with Desktop Commander quota still exhausted.

## Failure Handling

- If a GitHub workflow cannot reach the backend A1, classify the failure at the runner/SSH layer before touching Windows.
- If backend loopback health fails, inspect relay listener and tunnel state before restarting anything.
- If a Windows relay is down, repair only the relevant scheduled task/process/tunnel.
- Do not redesign the topology as a first response to a transient failure.
- Do not expose MCP publicly as a recovery shortcut.

## Security

- Secrets remain in GitHub Secrets, local protected files, or authorized runtime environment variables.
- Workflows may reference secret names but must not print values.
- Public direct SSH remains disabled.
- Reverse relays remain loopback-only on the OCI host.
- Administrative actions remain allowlisted.
- No credential migration is required for the control-plane change.

## Documentation Changes

At implementation time, update at minimum:

- `docs/knowledge/host-access.md`
- `docs/knowledge/README.md` if ordering language changes
- `docs/FRED-WIN-PRIVATE-RELAY.md` only if behavior actually changes
- KOCEPSV relay documentation if missing or inconsistent
- any monitoring/runbook that treats Desktop Commander presence/quota as a primary health requirement

## Test Strategy

### Structural

- Confirm expected workflow files, request files, relay scripts, and scheduled-task definitions exist.
- Confirm workflow actions remain allowlisted.

### Integration

- Run Fred-Win health via GitHub Actions -> backend A1 -> `127.0.0.1:5557` -> reverse tunnel.
- Run KOCEPSV health via GitHub Actions -> backend A1 -> `127.0.0.1:5558` -> reverse tunnel.

### Operational

- Execute at least one benign allowlisted action on each Windows host.
- Confirm OCI hosts remain administrable through their runner/private paths.
- Confirm no public MCP endpoint was introduced.

### Acceptance Criteria

Migration is complete only when all of the following are true:

- Desktop Commander account still has no remaining remote-call quota.
- `shopvivaliz-free-a1` can be administrated through the primary non-DC path.
- `always-free-arm-1787907847-26` can be administrated through the primary non-DC path.
- Fred-Win health and one benign allowlisted action succeed through the private relay.
- DESKTOP-KOCEPSV health and one benign allowlisted action succeed through the private relay.
- Existing Windows relay tasks remain enabled and stable.
- Desktop Commander is no longer required for routine operational control.
- Documentation reflects the new priority order.
- No active production release was edited in place.

## Rollback

Rollback is operational rather than destructive: keep existing Desktop Commander services/tasks untouched, and if the new primary path proves unreliable, operators can temporarily use Desktop Commander when quota is available or OCI Bastion for the OCI hosts while the private-relay path is repaired.
