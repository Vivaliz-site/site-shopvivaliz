# AI Stakeholder Handoff

## Current Autonomous State
- The canonical autonomous queue is `tasks-queue.json`, mirrored to `logs/tasks-queue.json` for legacy consumers.
- The executor can run SEO, catalog, product-page and CRO work autonomously without touching price, discount, shipping, commission or payment rules.
- Marketplace, DNS and paid media items are blocked automatically when they depend on credentials, manual access or human approval.

## Active Growth Missions
- `task-041`: activate autonomous growth task generation
- `task-042`: execute autonomous conversion optimizations
- `task-043`: connect catalog with Shopee without price impact
- `task-044`: connect catalog with Mercado Livre without price impact
- `task-045`: prepare Google Ads stack with human approval gate
- `task-046`: connect public domain to active web layer
- `task-047`: activate automatic SEO for catalog and marketplaces
- `task-048`: generate dynamic, indexable product pages

## Required Owners
- AI Engineering Platform
  - Own `task-041`, `task-042`, `task-047` and `task-048`.
  - Continue autonomous implementation and validation inside the protected branch workflow.
- Marketplace / OAuth
  - Provide `SHOPEE_PARTNER_ID`, `SHOPEE_PARTNER_KEY`, `SHOPEE_SHOP_ID`, `SHOPEE_REFRESH_TOKEN`.
  - Provide `ML_CLIENT_ID`, `ML_CLIENT_SECRET`, `ML_REDIRECT_URI`.
- Marketing / Growth
  - Approve Google Ads activation and daily budget policy before `task-045` can move out of blocked state.
- Infra / Domain
  - Provide DNS or registrar access to complete `task-046`.

## Governance
- Automatic price changes remain prohibited.
- Guardian of Price remains untouched.
- Paid media can be prepared technically, but campaign execution must stay approval-gated.
- All changes continue through branch, PR and audit logging.

## Recommended Next Actions
1. Validate or provision marketplace credentials in the project environment.
2. Confirm the human approver and budget source for Google Ads.
3. Confirm who owns DNS changes for the public domain.
4. Let the autonomous executor continue with the non-financial missions already queued.
