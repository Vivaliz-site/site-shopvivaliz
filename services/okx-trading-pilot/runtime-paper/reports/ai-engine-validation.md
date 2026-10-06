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
- Invalid model output/authentication fails closed. No automatic fallback to API-key access or heuristic execution in this candidate.

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
