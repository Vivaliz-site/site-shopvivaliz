# ChatGPT Dev and Atendimento: access and tool parity

## Invariant

The two corporate ChatGPT identities, `dev@shopvivaliz.com.br` and
`atendimento@shopvivaliz.com.br`, must have the same **effective authorized**
capabilities, tool catalogs, approved hosts, workspace access and application
permission modes for ShopVivaliz operations. Both use the same security gates;
parity never means silently elevating privilege or bypassing approval.

Scope: ShopVivaliz Remote Control MCP, Remote Desktop Commander 2 (RDC 2),
GitHub, Superpowers, Data, Writing Style and corporate Google connectors where
each account has completed its own supported OAuth authorization. Workspace
membership/role, installed apps, provider connections, tool availability and
effective app permissions are separate checks. Installed != connected.

## Identity and isolation (not optional)

- Dev Chromium: `shopvivaliz-dev-chromium`, CDP `9559`, dedicated MCP `5583`.
- Atendimento Chromium: `shopvivaliz-atendimento-chromium`, CDP `9556`, dedicated MCP `5582`.
- The dedicated browser MCP services must use the same server source, runtime
  options and authorization policy. Only the session name, CDP URL, TCP port
  and corresponding identity binding may differ.
- The Dev and Atendimento Chromium systemd services must maintain equal
  CPU, memory, process limits, security hardening, restart policy and browser
  flags, except their explicit session-specific profile, class and CDP port.
  `tests/test_chatgpt_access_parity.py` enforces this at the blocking CI gate.
- Never share or copy cookies, browser storage, TOTP, passwords, provider
  sessions, OAuth grants or token files between the accounts.
- Each account must be authenticated and confirmed to match its expected
  email before a browser mutation, plugin setup or continuity action.
- Dev and Atendimento checkpoints bind to their actual conversation/profile;
  never route one through the other's authenticated session.

## Parity requirements

1. Both accounts have the same approved ShopVivaliz workspace membership,
   role, app availability and access to the same canonical hosts. Their
   individual authentication records remain separate.
2. Both ChatGPT accounts have the ShopVivaliz Remote Control MCP installed
   and connected, with identical tool names and input schemas for shared
   functions. Test `hosts_list` independently on each account.
3. Both ChatGPT accounts have RDC 2 installed and connected through an
   independent ChatGPT-to-provider authorization. Record `who_am_i.email`
   for each to establish the expected provider identity, which may be the
   SAME provider account in two separately authorized ChatGPT connections
   when the owner/provider permits it. A label such as `Primary`, `dev`
   or `Atendimento` is not identity evidence, and two link IDs in a single
   ChatGPT session are NOT proof that the other ChatGPT account is connected.
   Compare online device access and effective per-host file/command settings.
4. In ChatGPT plugin settings, compare the *effective* global/default and
   plugin-specific permission mode per account and app. Keep the same
   approval requirements for sensitive actions. Do not set `full_access`
   just to make two accounts match. An app setting in one account does not
   configure the other account.
5. GitHub and other corporate connectors must be installed and individually
   authorized for both accounts, with equivalent repository/workspace
   entitlements and action permissions. Never impersonate one account using
   the other's OAuth token.
6. A provider may expose fewer native tools than another provider (for
   example, RDC 2 versus ShopVivaliz MCP). Cross-account parity means each
   account gets the same tool catalog *within that provider*; extending
   provider tools requires a separate supported MCP integration.

## Read-only acceptance procedure

For EACH account, using its own authenticated ChatGPT session:

1. Confirm exact account email and workspace membership/role.
2. Enumerate required apps and compare installed and connected status.
3. For **every shared MCP provider** (including ShopVivaliz Remote Control
   and RDC 2), call its authorized `tools/list` endpoint independently from
   EACH ChatGPT account. Capture the COMPLETE tool-name catalog and each
   tool's `inputSchema`, normalize JSON key ordering, and compare both
   catalogs for equality. Compare the complete set, not only `hosts_list`,
   `who_am_i` or `list_devices`; differences or missing schemas are FAIL.
   If a provider does not expose a complete catalog through a supported
   interface, record NOT_VERIFIED and do not claim full parity.
4. Inspect global/default and individual app permission modes; compare the
   effective modes by app without weakening approvals.
5. Call ShopVivaliz `hosts_list` and compare the canonical host set.
6. Call RDC 2 `who_am_i` from each ChatGPT account, verify the expected
   authorized provider identity for that connection, then `list_devices`;
   compare device availability and scoped settings.
7. Check the dedicated browser MCP service health and identity binding;
   health HTTP 200 alone is not an authenticated-browser E2E proof.
8. Record a redacted evidence summary with account identifier, timestamp,
   check name, PASS/FAIL and audit ID; never record secrets.

Do not declare `CONCLUIDO` while either account is signed out, a
connection points to the wrong identity, a permission differs, or an app
is not independently connected. User-mediated OAuth/consent must follow
the provider's official flow; it cannot be manufactured by editing the
connector label, copying an existing grant or changing local MCP config.

## Remediation order

1. Keep production controllers and existing working accounts online.
2. Obtain or repair the missing account-specific workspace/plugin
   installation and OAuth authorization by the official flow.
3. Align supported roles and permission settings without broadening
   sensitive access beyond the user's authorized policy.
4. Re-run all read-only checks in BOTH accounts, then a harmless E2E tool
   invocation; record remaining provider/platform blocks exactly.

Canonical browser isolation: `docs/knowledge/browser-sessions.md`.
Canonical tool/platform route: `docs/knowledge/host-access.md`.
