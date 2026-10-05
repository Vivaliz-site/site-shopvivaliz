# Functional audit privacy implementation plan

Goal: remove agent-key exposure through curl arguments, inherited child variables and tracing; stop printing raw HTTP error bodies.
Spec: docs/knowledge/agent-rules.md and the existing production-functional-audit contract. References: curl official --header @- and --disable; Bash export -n and shell options.
Architecture: keep the provided key shell-local, reject CR/LF, send only the authentication header through stdin, disable curlrc and tracing, retain every existing provider/HTTP fail-closed gate.
Files: scripts/production-functional-audit.sh; tests/test_production_functional_audit_security.py; scripts/repository-governance-validate.sh.

Constraints: isolated reserved worktree on known merge8f9d50f1; no real key, production probe, certifier change or retry of platform-blocked bootstrap/routing operations. No global certification. Full suite and protected integration still required. Use Superpowers TDD, execution, independent review and verification.

- [x] Baseline contracts and shell syntax passed on the known merged snapshot.
- [x] RED with real Bash and curl against disposable loopback HTTP fixtures.
- [x] Minimal fix and regression, including exported aliases, CR/LF, HTTP207, missing key, xtrace/allexport and curlrc verbose output.
- [ ] Full governance, independent review and correction of findings.
- [ ] PR checks, protected merge, versioned deployment and permitted post-merge regression.

Review focus: authentication delivered exactly once; no key in argv or child key variables; no diagnostics leak; success/failure/provider requirements unchanged; tests execute the real shell/curl not a mock response producer. External production E2E is not certified by local fixtures.

## Review rulings and proof
Independent static review d769ac98 completed; the original narrow patch had no declared blockers. Provider-controlled status/summary values and numeric tracebacks were regraded material and reproduced with6 failing cases, then removed from output. Missing-dependency skips were regraded material and reproduced with2 failures; the suite now fails closed rather than skipping. Exact failure-stage, no-forwarded-header-on-redirect and failed-provider-summary assertions were added. Final17 local HTTP tests pass, with the original contract and catalog checks unchanged.

Residual input contracts: credentials still arrive in the parent/audit shell initial environment; the patch prevents child propagation, not removal from the caller or initial process block. BASE_URL and caller startup environment remain trusted operator inputs. No claim of arbitrary hostile-shell protection or real-provider authentication. Production E2E remains separately blocked/unverified.
