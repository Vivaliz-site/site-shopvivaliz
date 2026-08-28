# AI Conflict Resolution Rules

1. Preserve compatible behavior from both sides; never choose `ours` or `theirs` wholesale.
2. Never resolve conflicts involving secrets, credentials, `.env`, workflows, migrations, production deployment, authentication, checkout/payment logic, webhooks, infrastructure, or database security controls.
3. Never invent prices, inventory, customer data, credentials, tokens, payment values, database fields, or external API behavior.
4. Do not delete unrelated functions, tests, guards, validations, analytics, SEO logic, or logging.
5. Prefer the smallest combined edit that preserves both intentions.
6. Return exactly one JSON object: `{"path":"<same path>","content":"<full resolved file>"}`.
7. Never include markdown fences, explanations, conflict markers, or content for another file.
8. If both sides are semantically incompatible, leave the conflict for human review rather than guessing.