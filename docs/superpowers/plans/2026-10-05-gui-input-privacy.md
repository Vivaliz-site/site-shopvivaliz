# Graphical input privacy correction

Goal: prevent sensitive text from entering process arguments, reflected GUI command diagnostics, or unsalted audit digests while preserving existing account/profile/display isolation.

Scope:
- remote-control-browser-mcp/server.py graphical typing and URL validation.
- remote-control-mcp/server.py sensitive audit metadata.
- regression tests and Browser MCP CI only.
- no account switching, locker bypass, production release edit, or storefront certification.

Validated design:
- transport xdotool text through stdin with type --file -;
- reject NUL, non-UTF-8 surrogate input, oversize text, and URL control characters before GUI side effects;
- emit fixed timeout/command failure labels;
- preserve only sensitive-field length, not unsalted digests;
- enforce the unit regressions and isolated X11 transport fixture in CI.

Evidence:
- RED reproduced on current main-derived worktree before implementation.
- focused tests: 9 PASS after correction.
- Browser MCP tests: 37 PASS.
- Base Remote Control MCP tests: 191 PASS.
- isolated X11 fixture: exact 11-character receipt, stdin match, no input in argv.
- git diff --check and Python compilation PASS.

This fixture validates only the isolated input transport. It is not storefront E2E, account recovery, or global Auditoria V5 certification.
