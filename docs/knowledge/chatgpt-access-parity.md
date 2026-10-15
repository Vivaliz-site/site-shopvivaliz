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

- Canonical Dev Chromium: `shopvivaliz-dev-chromium`, CDP `9559`,
  dedicated MCP `5583`; the **temporary explicitly approved MCP-only**
  adoption of already-logged-in same-account auxiliary CDP `9557` is
  documented below and is never a cookie/profile transfer.
- Atendimento Chromium: `shopvivaliz-atendimento-chromium`, CDP `9556`, dedicated MCP `5582`.
- The dedicated bridges on ports `5582`/`5583` must check their own
  canonical CDP `/json/version` with a bounded timeout for `/health`.
  Their health checks must not invoke generic X11 / `xdotool` actions on
  `fredconsole:0`: those checks belong only to the general graphical MCP.
  A healthy CDP connection is a transport signal, not an account login or
  exact identity proof; do not treat it as `AUTHENTICATED`.
- The dedicated browser MCP services must use the same server source, runtime
  options and authorization policy. Only the session name, CDP URL, TCP port
  and corresponding identity binding may differ.
- The Dev and Atendimento Chromium systemd services must maintain equal
  CPU, memory, process limits, security hardening, restart policy and browser
  flags, except their explicit session-specific profile, class and CDP port.
  `tests/test_chatgpt_access_parity.py` enforces this at the blocking CI gate.
- After ordinary browser MCP code upgrades, restart both dedicated
  systemd adapters so updated server.py is loaded and verify freshness.
  For the **explicit Dev-only auxiliary adoption operation** below, restart
  **only** the Dev MCP adapter; do not restart Atendimento's running MCP
  or any authenticated Chromium process. Never copy cookies or profiles.
  The Dev adapter must prove exact account identity on each operation.
- Automatic backend updates limited to the browser MCP use the browser-only
  deployment job; a support Windows machine going offline must not prevent
  refreshing the Dev/Atendimento bridges. Controller, SSH or Windows changes
  still use the fail-closed four-host bootstrap. Browser-only success is NOT
  evidence that every Windows host is reachable.
- Never share or copy cookies, browser storage, TOTP, passwords, provider
  sessions, OAuth grants or token files between the accounts.
- Each account must be authenticated and confirmed to match its expected
  email before a browser mutation, plugin setup or continuity action.
- Dev and Atendimento checkpoints bind to their actual conversation/profile;
  never route one through the other's authenticated session.


## Dev MCP-only adoption of verified auxiliary Chromium (2026-10-12)

The owner explicitly requested to **use the already authenticated Dev
browser** instead of repeatedly attempting OpenAI's email verification in
the canonical Dev profile. Live exact-account identity was independently
verified through `https://chatgpt.com/api/auth/session` on the auxiliary
Chromium CDP9557 (`dev@shopvivaliz.com.br`), audit
`f15a9d6e-e3f7-47ab-9fa0-e3eb4f65d845`.

A narrowly scoped, reversible **transport-only** route allows only the
dedicated Dev MCP adapter at TCP5583 to use that **unchanged, running**
Chromium. Explicit opt-in requires
`SHOPVIVALIZ_BROWSER_SESSION_NAME=dev`,
`SHOPVIVALIZ_BROWSER_CDP_URL=http://127.0.0.1:9557`,
`SHOPVIVALIZ_DEV_ADOPTED_CDP=9557`, and a live exact-email match on
every tool call. Identity mismatch, unauthenticated page, wrong service
port, missing opt-in or cross-account tool alias fails closed. Existing
conversation/runtime/fencing gates are unchanged. The versioned
`scripts/adopt-verified-dev-aux-browser.sh` requires owner authorization,
a maintenance lease, read-only identity checks on both accounts, and a
healthy protected loopback 5583 endpoint; it changes **only the Dev MCP
systemd drop-in** with automatic rollback. It must never copy/export
cookies, passwords, OAuth credentials, QR/TOTP or restart Chromium.

The **MCP Dev adoption alone** does **not** log the canonical Dev
CDP9559 in, nor modify the independent guardian/worker or identify
historical conversation bindings. Atendimento remains isolated on CDP9556.
A second, separately authorized **continuity-only** opt-in may now observe
and use the existing authenticated Dev auxiliary CDP9557 without cookies
or profile transfers. It is installed only by
`scripts/adopt-verified-dev-aux-continuity.sh` under a real maintenance
lease, SHA40 source attestation and live exact-email checks. The worker
reroutes **only explicitly bound Dev conversation checkpoints**; the
guardian is strictly read-only for the auxiliary Chromium and never
restarts it. There is no implicit fallback when the auxiliary logs out.
Without this second opt-in, the worker and guardian remain on CDP9559.

Regardless of the selected authenticated Dev transport, continuity
readiness/E2E stays `RUNNING` until the original
`task_id+conversation_id+browser_session+fingerprint` proofs and a real
`PROGRESS_CONFIRMED` receipt are present. See
`docs/knowledge/browser-sessions.md` for separate rollback procedures.

## Official sign-in before a conversation exists

A user-authorized corporate login **cannot** reuse the ordinary
`browser_type` / `browser_click` mutation tools while the relevant
ChatGPT conversation is unbound: those actions correctly require conversation
and runtime leases. Never disable their gate or forge a conversation.

The primary ShopVivaliz Remote Control MCP now also exposes these **same
restricted tool names** through a fixed loopback proxy to the installed browser
MCP on `127.0.0.1:5581/mcp`. It uses the existing peer's authorization and
re-checks the account-specific maintenance lease and fencing token before
forwarding. It does **not** expose general browser mutation, desktop input or
OAuth consent through these login tools. Their `value` arguments are removed
from both layers of audit (length only); only boolean input/click outcomes are
returned. If the browser MCP is unavailable, return an explicit failure rather
than retrying against the Atendimento profile or switching CDP globally.

The browser MCP instead provides two narrowly scoped operations:

- `browser_auth_tabs(session)`: read-only discovery of official login pages
  in the session-specific CDP 9559 (Dev) or 9556 (Atendimento); returns only
  tab ID, session and URL *path*, never query/fragment/OTP.
- `browser_auth_action(session, tab_id, action, ...)`: login-only
  `fill_email`, `fill_password`, `fill_code`, `continue`, `resend`,
  `back_to_methods`, `open_login`, `continue_google` and `continue_microsoft`.
  The provider actions click only exact official button labels on the observed
  `auth.openai.com/log-in` stage, without selecting an OAuth account or granting
  consent. The account email must still be verified after the provider flow.
  Requires a **live maintenance runtime lock** authorizing the action with
  exact owner `shopvivaliz-account-auth:<session>`, a matching fencing token,
  and `SHOPVIVALIZ_CONTINUITY_DURABLE_HANDOFF=1`. Normal browser mutation
  gates and in-progress conversations are unchanged.

As an idempotent preflight, the action **brings only the already verified
official login tab to the foreground** using CDP `Page.bringToFront` before
evaluating controls. This wakes background/frozen authentication pages without
reloading or navigating, and uses a bounded CDP timeout. A failed activation
returns `auth_tab_activate_failed` without submitting or logging credentials.
The page origin/path is checked again inside the browser before input or click.

The implementation enforces the official `chatgpt.com/auth/login` or
`auth.openai.com/log-in` / `email-verification` paths *again inside the
focused browser evaluation*, not solely in the tab list, and restricts the
editable field or selected button by login action. It does not operate on
consent/authorization pages, OAuth grants, CAPTCHA or recovery challenges.
The expected corporate email is checked before any email write. The existing
browser profile/cookies stay in place; do not transfer secrets between accounts.

When permitted, any password/OTP is delivered to the browser process over
protected stdin, not via command-line arguments. The MCP audit never stores
the field value; it may record the value length and action. Use only a
legitimate approved source for the credential, and never print it through
debugging, command output, chat or GitHub. Release the runtime lease after
the bounded login attempt.

**A successful input/click is not authentication.** Verify
`/api/auth/session` in the correct profile with the exact
`user.email` before allowing conversation recovery. Separately verify
ChatGPT app permissions and individual OAuth grants for RDC 2 and other
providers from EACH principal before checking account parity.

The pre-login Browser MCP additionally supports
`browser_auth_open(session, runtime_lease_id, runtime_fencing_token)`.
It opens **only a new tab** with the fixed URL
`https://chatgpt.com/auth/login` in the already-isolated
account CDP profile, with no caller URL, tab ID or input values. Both the
primary Remote Control proxy and the browser MCP independently verify a
live maintenance lease owned by `shopvivaliz-account-auth:<session>`.
The operation refuses to create duplicates if an official login-stage tab
already exists and never navigates an existing conversation. After opening,
use `browser_auth_tabs` and the existing `browser_auth_action` for login
and verify the exact email with `/api/auth/session`.

Both dedicated corporate Chrome services use the protected persistent
`fredrdp` user D-Bus (`/run/user/1002/bus`) rather than a private
`dbus-run-session`. This permits native password-manager access
to the user's GNOME Keyring but **does not unlock the collection**.
The profile, cookies, Chrome user-data-dir and CDP port remain isolated.

## Corporate identity and neutral home tab MCP tools

The base **ShopVivaliz Remote Control** and its browser peer now define two
additional operations. **Deployed server code is not an approved ChatGPT plugin
catalog**: check the installed app tool-name/inputSchema list separately from
the MCP server's `tools/list` before claiming the new actions are exposed.

- `browser_auth_status(session)`: read-only; queries
  `https://chatgpt.com/api/auth/session` inside the selected isolated browser,
  compares `user.email` to its dedicated corporate account, and returns only
  redacted booleans, state, HTTP status and bounded counts. Neither credentials
  nor email from the API response are returned. HTTP 200 alone proves nothing.
  `UNKNOWN`, `NOT_AUTHENTICATED` and `IDENTITY_MISMATCH` fail closed.
- `browser_profile_home_open(session, runtime_lease_id, runtime_fencing_token)`:
  reuses a neutral ChatGPT home tab or creates a new background tab only after
  an exact match, in the selected corporate CDP profile. Requires durable
  handoff and a valid maintenance runtime lease owned by
  `shopvivaliz-account-auth:<session>` with action
  `browser_profile_home_open`, checked at the proxy and browser peer.
  It cannot accept custom URLs, navigate an existing conversation, clone
  sessions, transfer cookies or authentication, or bypass MFA/consent.

The neutral-tab action does not copy a logged-in profile into an unauthenticated
one. Dev remains CDP9559, Atendimento CDP9556; XFCE `fredconsole/:0` and the
LightDM greeter are independent of corporate `fredrdp/:99` profiles.

Both corporate email addresses deliver to the Gmail `fredmourao` mailbox,
per the owner (2026-10-11). This is email routing only, not a shared OAuth
grant or ChatGPT authentication. Preferred login: existing password + GNOME
TOTP on the official MFA challenge, without automatically falling back to
email code, passkey or SSO.

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
