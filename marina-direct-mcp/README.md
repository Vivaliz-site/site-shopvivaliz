# Marina independent MCP (read-only staging only)

**Status: source/staging candidate only. NOT INSTALLED, NOT CONNECTED, NOT AUTHORIZED FOR PRODUCTION.**
Tracking: Vivaliz-site/site-shopvivaliz issues #3152 and #3156. The working direct
ChatGPT route remains the separately connected Remote Desktop Commander.

## Security contract

* Runs **only on marina-a1-4c24g**; refuses a different hostname.
* Binds **127.0.0.1:5580 only**. Neither IPv4 nor IPv6 wildcard bindings, no public
  NSG/security-list opening, and no reuse of the existing ShopVivaliz controller
  as a command relay.
* Bearer token MUST be a dedicated Marina secret file owned by the service UID,
  a regular file with mode **0600**; no copy/reuse of central MCP, OCI, RDC,
  Cloudflare or SSH credentials. No token in logs, process arguments, Git or chat.
* Supports only MCP initialize, ping, discovery and two strictly read-only
  tools: host identity and a fixed systemd service status allowlist. No shell,
  sudo, Docker access, SQL changes, writes, service actions or durable tasks.
* Rejects unauthenticated callers, client-supplied Origin, unknown tools,
  unexpected services, oversized requests and host identity mismatch.
* Security is intentionally fail-closed. This staging server is NOT a replacement
  for the full ShopVivaliz Remote Control MCP; it does not offer state mutation,
  ownership leases, CAS or fencing because it has no mutation surface.

## Validation without deployment

```sh
python3 -m py_compile marina-direct-mcp/server.py tests/test_marina_direct_mcp.py
python3 tests/test_marina_direct_mcp.py
```

There is **no install or deploy action in CI**. The dedicated
`marina-direct-mcp-ci.yml` workflow runs only static/unit validation, and
these paths are intentionally outside the existing
`remote-control-mcp-bootstrap.yml` push filters to avoid the legacy
four-host bootstrap.

## Official future provisioning steps (operator-gated)

1. Review source/CI and obtain explicit, **effective** permission from the
   platform and the Marina host administration channel for installation.
   The existing RDC `NoNewPrivileges=yes` refusal is binding: do not use
   GitHub Actions, OCI Run Command, another SSH account, tunnel or MCP to evade it.
2. In that officially authorized channel, provision a **new per-Marina token**
   into an isolated file with mode `0600`, owned by a dedicated unprivileged
   service identity. Install a vetted release under a separate immutable path
   and unit with `NoNewPrivileges=yes`, no ambient capabilities and
   `ExecStart=python3 .../server.py --token-file ...`. Audit unit sandboxing,
   filesystem permissions and restart persistence, with rollback.
3. Connect `127.0.0.1:5580` **only** through its own outgoing authenticated
   HTTPS tunnel (e.g., a separately provisioned Cloudflare Zero Trust identity
   and Access policy). Require independent edge authorization in addition to
   the server bearer token. Do not publish port 5580 or repurpose website 443.
4. Register the **separate** remote MCP endpoint in the authorized ChatGPT
   workspace using its official configuration UI. Validate the actual app auth
   contract, tools discovery, negative tests without tokens, live host identity,
   reconnection after controlled restart, and no controller dependency.
5. Keep mutation/privileged tools **disabled** until individually authorized,
   implemented with audited ownership/leases/fencing, and certified E2E.
   Validate the migration's independent UI, DB/worker, reboot and rollback
   gates before any DNS cutover or deletion of the source VM.

Do **not** claim successful installation or ChatGPT connectivity from passing
the unit tests or from the working RDC connection. Pricing/SAFE-T ownership,
database volumes, backups, security groups and certificates remain independent
migration gates.
