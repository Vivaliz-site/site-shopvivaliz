#!/usr/bin/env bash
set -Eeuo pipefail

# Trusted Oracle-side finalizer. Receives only public PR metadata from Actions;
# repository write authentication remains on the VM.

repo='Vivaliz-site/site-shopvivaliz'
pr_number="${1:-}"
expected_head_sha="${2:-}"

fail() {
  printf 'ERROR %s\n' "$1" >&2
  exit "${2:-2}"
}

[[ "$pr_number" =~ ^[0-9]+$ ]] || fail 'invalid PR number'
[[ "$expected_head_sha" =~ ^[0-9a-f]{40}$ ]] || fail 'invalid expected head SHA'
gh auth status --hostname github.com >/dev/null 2>&1 || fail 'Oracle GitHub authentication unavailable'

meta="$(gh api "repos/${repo}/pulls/${pr_number}")"
state="$(jq -r '.state' <<<"$meta")"
draft="$(jq -r '.draft' <<<"$meta")"
base_ref="$(jq -r '.base.ref' <<<"$meta")"
head_repo="$(jq -r '.head.repo.full_name // ""' <<<"$meta")"
head_sha="$(jq -r '.head.sha' <<<"$meta")"

[[ "$state" == 'open' ]] || fail 'PR is no longer open'
[[ "$draft" == 'false' ]] || fail 'draft PR is not eligible'
[[ "$base_ref" == 'main' ]] || fail 'PR base is not main'
[[ "$head_repo" == "$repo" ]] || fail 'fork PR is not eligible'
[[ "$head_sha" == "$expected_head_sha" ]] || fail 'PR head SHA changed before merge'

relation="$(gh api "repos/${repo}/compare/main...${head_sha}" --jq '.status')"
[[ "$relation" == 'ahead' || "$relation" == 'identical' ]] || fail 'PR head does not contain current main'

result="$(gh api --method PUT "repos/${repo}/pulls/${pr_number}/merge" \
  -f sha="$head_sha" \
  -f merge_method='squash')"
merged="$(jq -r '.merged // false' <<<"$result")"
[[ "$merged" == 'true' ]] || fail "GitHub rejected merge: $(jq -r '.message // "unknown"' <<<"$result")"
merge_sha="$(jq -r '.sha' <<<"$result")"
[[ "$merge_sha" =~ ^[0-9a-f]{40}$ ]] || fail 'merge returned invalid SHA'

echo 'pr_merged=true'
echo "validated_head_sha=${head_sha}"
echo "merge_sha=${merge_sha}"
echo 'external_github_auth_used=true'
echo 'secret_values_printed=false'
