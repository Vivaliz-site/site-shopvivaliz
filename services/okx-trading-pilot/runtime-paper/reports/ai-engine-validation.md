# OKX Terra login-only engine validation

## Requested configuration
- Model: gpt-5.6-terra; reasoning effort: medium.
- Local Codex bridge with corporate ChatGPT login, not an OpenAI API key.
- Existing 20 ordered analytical layers and deterministic Risk Gateway retained.
- PAPER only. Existing operational ledger must not be reset or replaced by smoke results.

## Enforced boundaries
- Provider URL must be loopback HTTP /v1/respond; userinfo, query credentials and fragments rejected.
- Result requires matching model and codex_chatgpt transport.
- Child process forces ChatGPT login and native openai provider.
- API key, direct access-token and workload-identity environment overrides are removed from the child environment.
- Invalid model output/authentication fails closed. No automatic fallback to API-key access or heuristic execution is allowed. The only automatic provider fallback is the login-based normal ChatGPT Dev-browser path: GPT-5.6 Sol at Extra High through MCP 5583/CDP9559.

## Validation evidence
- Test-first: default/transport suite produced five expected failures before correction; endpoint/environment guards failed before implementation.
- Local validation: 57 Python tests PASS; existing Codex bridge suite PASS; new login-only environment suite PASS; syntax and git diff checks PASS. CI must also run on the new commit.
- Review in this session was local, not an independent model review. Superpowers workflow applied; GEPETO_UNAVAILABLE in exposed tools.
- Initial legacy profile probes showed revoked session credentials. This is historical evidence, not proof about corporate credentials today.
- Native login was attempted in the canonical corporate dev profile using the real Codex executable, bypassing the legacy profile-selection wrapper. The wrapper otherwise overrides CODEX_HOME and buffers interactive output.
- Native OAuth reached the corporate sign-in page. The dedicated authentication tab was subsequently navigated to example.com outside this task; the attempt was stopped instead of fighting a concurrent browser writer.
- Native login status after that attempt: Not logged in. No successful Terra inference has yet been observed.

## Deployment gate
Do not merge or deploy until a corporate login completes, a real Terra-medium turn succeeds, a real OKX response validates all 20 layers, and the operational ledger survives a controlled restart. The existing heuristic PAPER service remains on its previously validated immutable release meanwhile.

## Real ChatGPT/Codex E2E — dev account
- Corporate `dev` profile authenticated through native Codex device-code flow; no API key used.
- `account/read(refreshToken=true)`: ChatGPT Team session valid.
- Real `gpt-5.6-terra` + `medium` probe completed successfully.
- Real OKX 20-layer E2E on public SWAP market data completed successfully after a parser regression fix:
  - exactly 20 ordered layers, all names exact;
  - market snapshot timestamp matched;
  - no market context sections missing;
  - supporting and contrary evidence present;
  - model returned HOLD with confidence 62, zero risk/leverage, which Risk Gateway correctly denied as `not_trade`;
  - paper portfolio was read from a temporary copy only;
  - no broker submit and no real order were executed.
- Parser now allows leverage=0 for non-trade decisions only; TRADE still requires leverage > 0. Regression covered by tests.
- Full runtime suite after fix: 59 tests PASS.


## Normal ChatGPT fallback contract
- Primary remains Codex/ChatGPT login with gpt-5.6-terra + medium.
- Fallback is normal ChatGPT login with gpt-5.6-sol + xhigh (Extra High).
- The fallback transport is the canonical Dev browser MCP on loopback 5583, pinned to shopvivaliz-dev-chromium / CDP 9559 / dev@shopvivaliz.com.br.
- Atendimento (5582 / CDP 9556) is never used as a substitute, and no third OKX browser profile is created.
- Each fallback inference uses a fresh Temporary Chat under the global browser maintenance lock, rejects web-search/tool use, validates Sol model metadata, and closes its tab.
- Primary availability failures start a primary cooldown; browser-provider errors start the orchestrator cooldown. Integrity/model/schema failures do not silently switch providers.
- No OpenAI Platform API key, access-token environment fallback, heuristic decision engine, or real exchange order path is enabled.

## Fallback deployment gate
The fallback is operational only after a real Dev-session Sol/Extra-High turn returns a valid exact 20-layer response, the runtime reports decision_effective_provider=CHATGPT_BROWSER_20_LAYER, the existing PAPER ledger/run id survives restart, and real_orders_enabled=false remains true.
