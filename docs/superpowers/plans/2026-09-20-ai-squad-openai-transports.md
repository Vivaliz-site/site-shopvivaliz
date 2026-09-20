# AI Squad OpenAI Transport Resilience Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the OpenAI leg of AI Squad resilient without scraping ChatGPT Web: ChatGPT-authenticated Codex first when quota exists, then direct OpenAI API, then same-model OpenRouter, and finally an explicit manual-intervention state using the already-authenticated ChatGPT session.

**Architecture:** A loopback-only Node bridge runs as the `ubuntu` user and talks to the real Codex App Server over stdio with the existing ChatGPT Business auth profiles. PHP never reads ChatGPT tokens; it calls the local bridge, then falls back to existing direct API/OpenRouter transports. If every automated transport fails, the API emits a manual-required event containing only the safe prompt and failure classifications; the admin UI makes that state explicit instead of pretending the provider answered.

**Tech Stack:** PHP 8, Node.js built-ins, Codex CLI/App Server JSONL protocol, systemd user service, existing ShopVivaliz admin UI.

**Spec:** `docs/knowledge/ai-squad.md`

## Global Constraints

- Preserve requested OpenAI model across every automated transport; never silently swap models.
- OpenAI deep/balanced/fast presets remain gpt-5.6-sol/xhigh, gpt-5.6-terra/high, and gpt-5.6-luna/medium.
- Fable remains forbidden from all AI Squad presets.
- Never expose or persist provider keys, ChatGPT OAuth tokens, Codex auth files, or full prompts/responses in cycle logs.
- ChatGPT Web remains manual/visual fallback only; do not scrape its response into AI Squad.
- Codex runs with ChatGPT-managed auth and a restricted local sandbox; hosted web search is allowed when the profile requests live research.
- Existing Anthropic/Gemini direct -> same-model OpenRouter behavior stays unchanged.
- Manual OpenAI fallback must be explicit in API/UI and must not count as a successful agent response or moderator.

## Review Focus

- Codex bridge unavailable or timed out: direct API begins promptly and the cycle does not hang for every later phase.
- Codex quota exhausted: another configured ChatGPT profile may be tried once; failure is classified without exposing auth details.
- All OpenAI automated transports unavailable: emit manual-required with a copyable prompt, no false successful response.
- Model mismatch returned by a transport: reject it rather than accepting a silent model substitution.
- Manual-required OpenAI entries are excluded from successful transcript/consensus while Claude/Gemini can continue.

---

### Task 1: Loopback Codex App Server bridge

**Files:**
- Create: `ops/ai-squad/codex-bridge.mjs`
- Create: `tests/ai-squad-codex-bridge-test.mjs`
- Create: `ops/ai-squad/install-codex-bridge-user-service.sh`

**Interfaces:**
- Consumes: existing Codex auth profiles discovered under `$HOME/.codex-business/*/auth.json`; real Codex path from `AI_SQUAD_CODEX_REAL`.
- Produces: `GET /health` and `POST /v1/respond` on loopback. POST accepts `{model, effort, prompt, web_search}` and returns `{ok,text,model,transport,sources,profile_state}`.

- [ ] **Step 1: Write the failing Node contract test**
Test these exported pure helpers before the server exists:

```js
assert.deepEqual(validateRequest({model:'gpt-5.6-sol',effort:'xhigh',prompt:'x',web_search:true}).model, 'gpt-5.6-sol');
assert.throws(() => validateRequest({model:'other',effort:'xhigh',prompt:'x',web_search:true}));
assert.equal(classifyRateLimit({rateLimitReachedType:'usage_limit'}), 'exhausted');
assert.equal(classifyRateLimit({primary:{usedPercent:1},rateLimitReachedType:null}), 'available');
```

Run: `node tests/ai-squad-codex-bridge-test.mjs`
Expected: FAIL because the bridge module/helpers do not exist.

- [ ] **Step 2: Implement the minimal bridge**

Requirements:
- bind only `127.0.0.1`;
- discover auth-profile homes without logging names/tokens;
- spawn the real Codex binary directly, never the current failover wrapper;
- initialize App Server, read account/rate limits, then create an ephemeral thread;
- use requested model and effort; start in a dedicated empty cwd;
- use a restricted read-only sandbox policy and `approvalPolicy=never`;
- start App Server with live web search only when `web_search=true`;
- collect only final agent message text and URL-like sources;
- enforce per-request deadline and terminate child on completion/error;
- return sanitized failure classes such as `quota`, `timeout`, `auth`, `transport`;
- never return auth/account identifiers or raw stderr.

- [ ] **Step 3: Run Node test and lint**
Run: `node tests/ai-squad-codex-bridge-test.mjs && node --check ops/ai-squad/codex-bridge.mjs`
Expected: PASS.

- [ ] **Step 4: Add idempotent user-service installer**

Installer must create/update `~/.config/systemd/user/shopvivaliz-ai-squad-codex-bridge.service`, point ExecStart at the current immutable release, bind loopback, rely on existing `Linger=yes`, daemon-reload/restart, and verify `/health`. It must not copy auth files or print credentials.

- [ ] **Step 5: Commit**
```bash
git add ops/ai-squad tests/ai-squad-codex-bridge-test.mjs
git commit -m "feat(ai-squad): add ChatGPT-auth Codex bridge"
```

### Task 2: OpenAI transport chain in PHP

**Files:**
- Modify: `includes/ai-squad-core.php`
- Modify: `tests/ai-squad-core-test.php`

**Interfaces:**
- Consumes: bridge POST contract from Task 1 and existing `svais_openai_call` / `svais_openrouter_call`.
- Produces: OpenAI dispatch order `codex_chatgpt -> direct -> openrouter -> manual`, with same requested model guaranteed.

- [ ] **Step 1: Write failing PHP tests**
Add deterministic fake-transport tests proving:

```php
// codex success stops chain
// codex failure -> direct success
// codex + direct failure -> openrouter success
// all automated failures -> SvaisManualInterventionRequired
// any successful response whose model does not match the requested model is rejected
```

Also assert provider health exposes the transport order and manual fallback capability without secrets.

Run: `php tests/ai-squad-core-test.php`
Expected: FAIL because the dispatcher/manual exception do not exist.

- [ ] **Step 2: Implement transport helpers**

Add:
- `SvaisManualInterventionRequired` carrying only safe prompt + sanitized attempt classifications;
- `svais_codex_bridge_call(...)`;
- `svais_openai_transport_order()`;
- an injectable `svais_openai_dispatch(..., ?callable $invoke = null)` for deterministic tests;
- exact-model validation before accepting any automated result;
- one-request cooldown after Codex failure so later phases in the same PHP cycle skip a known-bad bridge instead of repeating its timeout.

Keep Anthropic/Gemini dispatch unchanged.

- [ ] **Step 3: Verify PHP**
Run:
```bash
php -l includes/ai-squad-core.php
php tests/ai-squad-core-test.php
```
Expected: PASS.

- [ ] **Step 4: Commit**
```bash
git add includes/ai-squad-core.php tests/ai-squad-core-test.php
git commit -m "feat(ai-squad): add resilient OpenAI transport chain"
```

### Task 3: Manual fallback event and visible UI

**Files:**
- Modify: `api/agent/ai-squad.php`
- Modify: `admin/ai-squad.php`
- Create: `tests/ai-squad-manual-fallback-contract-test.php`

**Interfaces:**
- Consumes: `SvaisManualInterventionRequired` from Task 2.
- Produces: NDJSON `agent_manual_required` event with provider, phase, requested model, manual prompt, safe attempt classes and `transport=manual`.

- [ ] **Step 1: Write failing contract test**

Assert API contains a dedicated catch for `SvaisManualInterventionRequired`, emits `agent_manual_required`, stores provider status `manual_required`, and the UI handles/copies the prompt without classifying it as `agent_message`.

Run: `php tests/ai-squad-manual-fallback-contract-test.php`
Expected: FAIL.

- [ ] **Step 2: Implement API/UI behavior**
API behavior:
- append manual event to transcript for visibility but not to successful messages;
- continue Claude/Gemini phases;
- skip OpenAI as moderator unless a real automated OpenAI response succeeded;
- cycle remains successful if another provider succeeds.

UI behavior:
- label transports `codex_chatgpt`, `direct`, `openrouter`, and `manual` explicitly;
- show a distinct OpenAI manual card;
- render the manual prompt with DOM textContent and a "Copiar prompt" action;
- explain that the authenticated ChatGPT VM/RDP session is the manual path;
- never auto-read the ChatGPT Web response.

- [ ] **Step 3: Verify API/UI contracts**
Run:
```bash
php -l api/agent/ai-squad.php
php -l admin/ai-squad.php
php tests/ai-squad-manual-fallback-contract-test.php
php tests/ai-squad-core-test.php
```
Expected: PASS.

- [ ] **Step 4: Commit**
```bash
git add api/agent/ai-squad.php admin/ai-squad.php tests/ai-squad-manual-fallback-contract-test.php
git commit -m "feat(ai-squad): expose manual OpenAI fallback"
```

### Task 4: Documentation, full gate, deploy, runtime proof

**Files:**
- Modify: `docs/knowledge/ai-squad.md`

**Interfaces:**
- Consumes: Tasks 1-3 complete.
- Produces: documented runtime contract and verified production behavior.

- [ ] **Step 1: Update documentation**

Document transport order, loopback bridge, ChatGPT-auth requirement, manual fallback semantics, service commands, health fields, and the rule that ChatGPT Web is never scraped.

- [ ] **Step 2: Run full feature gate**
Run:
```bash
node tests/ai-squad-codex-bridge-test.mjs
node --check ops/ai-squad/codex-bridge.mjs
php -l includes/ai-squad-core.php
php -l api/agent/ai-squad.php
php -l admin/ai-squad.php
php tests/ai-squad-core-test.php
php tests/ai-squad-manual-fallback-contract-test.php
git diff --check
```
Expected: all PASS / no output from `git diff --check`.

- [ ] **Step 3: Commit documentation**
```bash
git add docs/knowledge/ai-squad.md
git commit -m "docs(ai-squad): document OpenAI transport resilience"
```

- [ ] **Step 4: Review, PR, CI and immutable deploy**

Run the Superpowers final branch review. Push the isolated branch, open a PR, wait for required repository gates, merge only when green, then let the canonical immutable deployment pipeline publish main.

- [ ] **Step 5: Install/restart runtime bridge and validate production**

Run the versioned installer on `shopvivaliz-free-a1` after the new release is current. Validate:
- bridge `/health` is loopback-only and reports ChatGPT auth without account identifiers;
- production AI Squad health shows the OpenAI transport order;
- a safe smoke proves Codex transport when quota is available;
- a simulated bridge-unavailable test proves direct/manual fallback without changing production secrets;
- UI still redirects unauthenticated users and the authenticated admin UI renders the new transport/manual states.
