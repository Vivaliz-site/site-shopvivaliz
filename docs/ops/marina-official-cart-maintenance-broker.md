# Marina P0 — official signed maintenance gate (candidate; NOT DEPLOYED)

**STATUS: IMPLEMENTATION IN DRAFT / NOT APPROVED / NOT INSTALLED / NO PROD MUTATION.**
Linked: [P0 #3163](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3163),
[control-plane capability #3169](https://github.com/Vivaliz-site/site-shopvivaliz/issues/3169),
and original fenced sender branch PR #3164 (CLOSED/DRAFT/UNMERGED).

## Why this is a new capability, not a bypass

The old official \`service_action\` refused \`runtime_lock_required\` (audit
\`9197c5d2-9546-4900-ba74-56e24ac7a584\`). The MCP does not issue
operator-authorized maintenance tickets; a generic root shell, Github admin
credential, ChatGPT confirmation or \`foreground_handoff\` is not sufficient.
**Do not use another transport or manually run \`runtime_lock.acquire_runtime_lock\`
to stop/disable the old source timer or remove the cron.** Existing refusal
remains in force until the authorized owner approves and deploys the new
control-plane capability in its official channel.

## Prepared code — still disabled

- \`remote-control-mcp/maintenance_cart.py\`: Ed25519 signature verification
  against an independently provisioned, root-owned trusted-operator registry;
  exact fixed action/source/destination/timer scope; source crontab hash CAS;
  ticket valid for at most 180 seconds, no reuse, no lease emission via public
  tool. The server obtains an internal exclusive runtime lock only after
  verifying the operator signature and read-only status on both hosts.
- \`remote-control-mcp/retire-origin-abandoned-cart.py\`: fixed source-only
  helper, which again validates the SAME signed ticket through protected
  stdin; correct host/root/fence; holds BOTH independent \`flock\` files so no
  new sender can start during the transaction; rejects an active sender, unexpected
  cron, timer service lock drift and concurrent ownership; stores a protected
  original crontab backup; stops/disables only the exact source timer and
  removes only the exact old cron line, with readback. Failed/unknown actions
  remain \`indeterminate\` and cannot be automatically replayed. No automatic
  rollback re-enables hazardous source writers.
- \`remote-control-mcp/server.py\` adds \`marina_cart_preview\` (read-only)
  and \`marina_cart_retire_source\` (mutating, signed ticket, operator-owned).
  Sensitive signed capability material is excluded from MCP audit payload.
  A separate flag \`SHOPVIVALIZ_CART_MAINTENANCE_ENABLED=approved-v1\` is
  required *in addition to* cryptographic approval, not as approval itself.
  \`marina_cart_retire_source\` will never accept arbitrary commands, host
  names, service names, supplied lease IDs or fencing tokens.
- The service connection to Marina must be a proven authenticated PRIVATE route
  in \`10.80.0.0/16\`; the existing public-IP Marina catalog entry deliberately
  fails closed until a legitimate private transport is provisioned.
- \`scripts/setup-remote-control-access.sh\` stages **verifier code only**.
  It does NOT install source helper, operator public keys, private keys or
  activate the feature. Existing code-only setup is not a maintenance grant.

## External operator-gated preparation

1. An accountable platform/ShopVivaliz runtime owner MUST authenticate as the
   intended authorized operator via the platform's own genuine identity flow,
   attest their signing-key ownership, and approve the narrowly defined P0
   operation. The GitHub identity \`fredmourao-ai\` has repository admin
   permission but that fact does not attest this privileged production role.
2. The approved administrator registers the operator's Ed25519 public key
   through a protected maintenance channel. On the backend AND source,
   the registry \`/etc/shopvivaliz/maintenance/abandoned-cart-operators.json\`
   must be root-owned mode 0600, not a symlink. Schema:
   \`{"schema_version":1,"operators":{"VERIFIED_OPERATOR":{"enabled":true,"ed25519_public_key_b64":"BASE64_OF_32_PUBLIC_KEY_BYTES"}}}\`.
   This is **template only**, not permission to generate an unauthorized
   signing key or register oneself. Private signing keys stay exclusively
   on the operator's authorized signing device; never send via chat, Git,
   shell argv, CI secrets/logs or the MCP controller.
3. Official deployment provisions the fixed source helper and verifier together
   in \`/opt/shopvivaliz-remote-control/\`, root-owned immutable/protected,
   with dependency \`cryptography\` verified. It creates the root-owned
   \`/var/lib/shopvivaliz-remote-control\` 0700 parent on source, without
   altering any worker, timer or cron. Backend verifier is installed by the
   approved MCP release mechanism. Do not edit \`current\` or an active release.
4. The official MCP catalog MUST use pinned host keys and authorized private
   routing to both VMs (source \`10.0.1.112\`, Marina \`10.80.1.116\`) for
   this transaction. No public SSH fallback. Verify actual identity and
   privileges. The source helper must be callable only by its controlled
   authorized deployment path, never as an ad-hoc way to evade the old
   \`service_action\` refusal.
5. After independent review, runtime-owner approval and required CI, enable
   the new tool in the protected MCP service environment. Do not set the
   feature flag simply to manufacture an approval.

## Actual maintenance execution after all prerequisites

1. Call \`marina_cart_preview\`. It must return both verified hosts, source
   fence present, exactly one legacy source cron, source timer active/enabled,
   destination timer active/enabled and the full source crontab SHA-256.
2. Have the registered operator approve the exact request **out-of-band**
   using their independently protected private key. The ticket is canonical
   JSON (sorted keys, ASCII, compact separators) with exactly these fields:
   \`version=1\`, unique UUIDv4 \`approval_id\`, verified \`operator_id\`,
   \`action=retire_origin_abandoned_cart_v1\`,
   \`source_host=shopvivaliz-free-a1\`, \`destination_host=marina-a1-4c24g\`,
   \`timer=shopvivaliz-abandoned-cart-recovery.timer\`,
   \`source_cron_sha256\` from fresh preview, integer \`issued_at\` and
   \`expires_at\` epoch timestamps (at most 180 seconds apart). Approval must
   be current, never guessable or reused, with detached Ed25519 signature
   base64 over the canonical serialized ticket.
3. The authenticated MCP tool \`marina_cart_retire_source\` verifies the
   original operator's signature, resource scope, TTL, source cron state and
   Marina sole-writer preflight; it acquires one internal owner-exclusive
   runtime lock with fencing, then executes the fixed source helper via
   pinned SSH and **protected stdin**. The helper verifies the ticket
   again on source, holds the two exact sender locks, stops/disables the
   source timer, removes ONLY the one expected source cron, and checks
   readback. It stores a one-use journal and original cron backup on source.
   The MCP checks destination again and releases its own runtime lease.
4. Validate \`timer=inactive/disabled\` and \`cron_entries=0\` on source,
   \`timer=active/enabled\` on Marina, plus zero source sender outputs across
   TWO consecutive scheduling windows. Do not reactivate source cron on
   rollback without explicit new approved transfer-of-ownership procedure.
5. Apply the already-prepared source sender fence through the canonical
   *immutable release* review/deploy process after the operator approval.
   Repeat real E2E UI and durable-data reconciliation, one-writer after
   approved controlled reboot, and separate global migration acceptance.
   **Never declare global APTO or decommission the source from a P0-only PASS.**

## Fail-closed and recovery behavior

Missing operator registry, unauthenticated signer, invalid signature, missing
private Marina route, expired/replayed ticket, changed cron or active runtime
lease all deny BEFORE STOP/DISABLE. Post-dispatch timeouts and crashes are
\`INDETERMINATE\`: inspect source manually under the officially owned
maintenance channel before any new action; do not retry the same approval.
A signed approval is a short-lived sensitive capability even though it
contains no password: never log, paste or expose it. The source cron backup
and journals are root-owned; no original crontab content enters repo/chat.

**No user or agent may self-issue a maintenance capability by calling the
existing \`runtime_lock.py\` API directly.** Operator signing-key
registration and feature rollout are separate official security decisions,
not inferred from the user's permission to prepare this code.

## Test and release criteria

\`python3 -m unittest discover -s tests -p 'test_marina_cart_signed_maintenance.py' -v\`
and \`python3 -m py_compile\` cover negative/positive signature and replay
contracts. CI workflow \`marina-cart-maintenance-ci.yml\` has no production
tokens or deploy. This branch must remain a DRAFT PR with
\`blocked-by-runtime-lease\` / \`no-auto-merge\` until official actor
authorization, independent review, secured roll-out, runtime gate and
post-transaction verification. No global migration green while other
Pricing, Supabase, SAFE-T, E2E, MCP and rollback gates remain pending.

## 2026-10-23 fail-closed hardening and verified rollout blockers

- Gate now requires **destination timer active/enabled AND last sender execution successful (systemd Result=success, ExecMainStatus=0)** on the *same Marina host* within the past two hours, measured using Linux monotonic microseconds. A running timer alone no longer qualifies as a healthy writer. The real 2026-10-23 12:41 UTC Marina sender exited 0/result success and the timer remained active/enabled. Synthetic CI uses a boot-uptime-independent monotonic fixture so a fresh hosted runner cannot falsely fail.
- The source preflight was independently observed on 2026-10-23 12:47 UTC: migration fence present, old cart timer active/enabled, one ubuntu cron, old sender lacks fail-closed fence, and the legacy cron line **exactly matches** the source helper's one-line expected contract. The systemd sender ExecStart also uses the predicted second `flock` path. This is a zero-mutation inspection, not approval or a completed transfer.
- The backend private TCP/22 route to Marina `10.80.1.116` was probed via official MCP on 2026-10-23 12:50 UTC and **NOT REACHABLE**. Its host catalog still routes Marina to PUBLIC IP `137.131.173.240`. Do not substitute the public endpoint to make a preview pass: a genuine approved private route, SSH host-key verification and separately authorized operator-controlled connection are mandatory.
- The backend currently has NO `/etc/shopvivaliz/maintenance/abandoned-cart-operators.json` and NO staged `maintenance_cart.py` installed (read-only probes). This confirms **no operator signing authority / rollout**. Neither `GitHub admin`, chat permission nor manually calling `runtime_lock.acquire_runtime_lock` is authorization. The feature remains blocked even with green tests.
- During current evidence collection, the Returns public probe was logged by Marina's Apache vhost, whereas the Pricing public `/v1/health` probe was logged by the ORIGIN's Apache vhost. The wider migration still has a split routing decision. **No Pricing cutover, worker ownership change, DNS change or VM decommission until its own separately approved transition and rollback gate.**
- Oracle Marina NSG remains hardened: 2026-10-23 read-only OCI API shows 0 public-all IPv4/IPv6 ingress entries and 15 NSG rules, all associated with the Cloudflare IPv4 allowlist. Guest Pricing may still publish port 13312 on `0.0.0.0` and `[::]`; separate Docker/host firewall maintenance authorization is needed to bind to loopback and verify persistent volumes. No Docker container recreation from this draft broker work.
- The signed broker remains a **draft implementation**, not a trusted production security boundary until independent code review, approved operator enrollment, immutable install, authenticated private connectivity and live E2E/ownership certification are complete. Never merge this security-sensitive PR merely because its CI passes.
