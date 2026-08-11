# Autonomous PR healing and completion

This repository enforces `REGRA-PR-FALHOU-CORRIGIR-NA-HORA.md` with three workflows:

- `PR Conflict Auto-Healer`: keeps same-repository PR branches current with `main` and uses a rotating Gemini credential pool to resolve textual Git conflicts.
- `PR Policy Enforcement`: tests the healer and verifies that the enforcement surfaces have not been removed or weakened.
- `PR Completion Enforcer`: merges only when the PR contains current `main` and every canonical gate is successful on the exact head SHA.

## Gemini credential rotation

The healer accepts singular aliases and multi-key bundles. Bundles may be comma-, semicolon-, or newline-separated. Values are deduplicated in memory and never printed.

Supported inputs include `GEMINI_API_KEY`, `GOOGLE_GEMINI_API_KEY`, `GOOGLE_IMAGEN_API_KEY`, `GOOGLE_API_KEY` and their plural `*_API_KEYS` forms.

A `401`, `403`, or `429` rotates immediately to the next credential. Model-level `400`/`404` errors try the configured fallback model before abandoning a credential. Transport and server errors also rotate instead of stalling the PR.

## Trust boundary

The healer never runs PR code while AI secrets are available. It operates only on same-repository branches, uses the trusted healer implementation from `main`, and sends only conflicted file versions to Gemini. Fork PRs are excluded from automated healing and merging.

Binary, symlink and oversized conflicts fail closed. A resolution is staged only after Git conflict markers are absent. The healed commit is pushed with the repository automation token so normal PR validation is triggered again.

## Merge freshness

Green checks are valid only for the exact SHA being merged. The completion enforcer also requires `main` to be an ancestor of the PR head. If another PR advances `main`, the remaining PRs must synchronize and revalidate before merge.
