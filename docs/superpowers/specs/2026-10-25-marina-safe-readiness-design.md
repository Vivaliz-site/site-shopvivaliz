# Marina Safe Readiness Design

## Intent

Reduce two versioned migration risks without operating protected infrastructure:
the source editorial/email workflow must not run while the origin fence is
present, and the certificate-renewal repair workflow must target Marina rather
than the stopped origin.

## Goals

- Make scheduled blog and product-email workflows fail closed before they can
  load SSH material or reach DB/email commands on any non-Marina runner.
- Make the manual Pricing certificate credential-reference workflow identify
  Marina as its only valid runtime target.
- Add regression tests and update the migration evidence without claiming a
  lease, a certificate issuance, a backup transfer, or operational acceptance.

## Non-goals

- Do not issue a certificate, write DNS, change Cloudflare, start/enable a
  service, alter Docker/MySQL/logrotate, transfer Supabase data, or create a
  second blog/email runner.
- Do not alter source cron/timers or manufacture a maintenance lease.

## Approaches considered

1. Run the existing workflows against the stopped source. Rejected: it cannot
   prove Marina readiness and could reintroduce a writer.
2. Add a Marina runner or directly alter services. Rejected: requires approved
   operator ownership, secrets, and runtime maintenance.
3. Harden the versioned workflows and test their preconditions. Selected:
   deployment-independent, fail-closed, and reversible by normal PR flow.

## Design

The editorial and product-email workflows receive an explicit short-hostname
Marina runner gate before SSH material is loaded. They exit without publishing
or sending unless the runner is `marina-a1-4c24g`; this prevents a source
runner from becoming a writer even if it retains the legacy runner label.
The certificate-repair workflow is renamed/scoped to Marina and checks the
Marina hostname before touching its credential-reference helper. Static tests
prove both guards and prohibit DNS/certbot invocation from the repair workflow.
The runbook records that the runner is not provisioned/attested and the
remaining privileged operations and evidence they need.
