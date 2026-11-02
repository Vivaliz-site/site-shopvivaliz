# Marina protected broker client implementation plan

> Required workflow: superpowers:executing-plans, TDD and verification-before-completion.

**Goal:** connect the existing unprivileged MCP to the existing fixed-operation root broker, without activating privileges or external ingress.
**Architecture:** root-authenticated fixed Unix socket; host-issued short-lived fenced ticket; protected operator-installed grant metadata; default-off startup and external Access JWT remains read-only.
**Tech Stack:** Python standard library, unittest, existing immutable packaging and GitHub CI.
**Spec:** docs/superpowers/specs/2026-11-01-mcp-admin-cross-repository-parity.md and docs/ops/marina-mcp-existing-cloudflare-tunnel-2026-11-02.md.

## Global constraints
No shell, arbitrary service/path/host, credential reuse, root HTTP daemon or provider bypass. Do not install/enable broker, publish DNS, modify active release or infer root authorization from #3514/#3521. Preserve existing legacy injected operator tests. Runtime requires exact new human root grant and official host permissions.

## Review focus
Root peer identity before sending; strict grant/ticket binding and expiration; response loss never retries mutation; external JWT cannot discover or invoke admin; startup failures preserve reads while strict check fails.

## Tasks
- [x] Task 1: tests/test_marina_broker_client.py RED; implement marina-direct-mcp/broker_client.py; GREEN. Fixed AF_UNIX socket, SO_PEERCRED root, bounded framed JSON, immutable matching grant, no retries.
- [x] Task 2: add root broker side-effect-free preflight and default-off server client startup; prepare/execute tools only under local auth; update packaging and relevant CI. Add integration/startup/negative tests RED before production changes, then GREEN.
- [ ] Task 3: full affected suites and universal gates, separate review, commit, technical PR and merge after checks. Persist exact activation blockers to issue #3152; do not mark operational MCP complete.

## Rulings
Use existing architecture and grant semantics. This is a technical implementation, not a grant or runtime deployment. Current conversation ID is unavailable; local ledger is durable but not proof of controller recovery. No new automation or executor is created.

## Independent review follow-up
- Packaging regression reproduced missing broker unit/socket templates; include them only as inert immutable release files.
- Real transient systemd test reproduced errno 97 in the verifier child under AF_UNIX-only sandbox; permit outbound AF_INET/AF_INET6 while retaining inherited AF_UNIX listener validation.
- Add this runtime sandbox regression to dedicated CI. No host broker activation is included.
