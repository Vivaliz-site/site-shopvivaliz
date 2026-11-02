#!/usr/bin/env bash
set -Eeuo pipefail

# ALLOW_SCOPED_PUSH
# Trusted Oracle-side PR repair boundary. The GitHub Actions runner only sends
# non-secret PR metadata. GitHub write authentication and Gemini credentials
# remain on the VM and are never copied back to Actions.

repo='Vivaliz-site/site-shopvivaliz'
shared_env='/home/ubuntu/shopvivaliz-deploy/shared/.env'
pr_number="${1:-}"
head_ref="${2:-}"
expected_head_sha="${3:-}"
trusted_healer="${4:-}"
external_github_auth_used=false

fail() {
  printf 'ERROR %s\n' "$1" >&2
  exit "${2:-2}"
}

[[ "$pr_number" =~ ^[0-9]+$ ]] || fail 'invalid PR number'
[[ -n "$expected_head_sha" && "$expected_head_sha" =~ ^[0-9a-f]{40}$ ]] || fail 'invalid expected head SHA'
[[ -n "$head_ref" ]] || fail 'missing PR head ref'
[[ "$head_ref" != 'main' && "$head_ref" != 'master' ]] || fail 'protected branch publication is forbidden'
[[ "$head_ref" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$ ]] || fail 'unsafe PR head ref syntax'
[[ "$head_ref" != *'..'* && "$head_ref" != *'@{'* && "$head_ref" != */. && "$head_ref" != ./* && "$head_ref" != */ ]] || fail 'unsafe PR head ref structure'
command -v git >/dev/null || fail 'git unavailable on Oracle'
command -v curl >/dev/null || fail 'curl unavailable on Oracle'
command -v jq >/dev/null || fail 'jq unavailable on Oracle'

fetch_pr_meta() {
  curl -fsSL --connect-timeout 10 --max-time 30 --retry 3 --retry-delay 1 \
    -H 'Accept: application/vnd.github+json' \
    -H 'User-Agent: shopvivaliz-pr-auto-healer' \
    "https://api.github.com/repos/${repo}/pulls/${pr_number}" \
    || fail 'GitHub PR metadata fetch failed'
}

meta="$(fetch_pr_meta)"
state="$(jq -r '.state' <<<"$meta")"
draft="$(jq -r '.draft' <<<"$meta")"
base_ref="$(jq -r '.base.ref' <<<"$meta")"
remote_head_ref="$(jq -r '.head.ref' <<<"$meta")"
remote_head_sha="$(jq -r '.head.sha' <<<"$meta")"
head_repo="$(jq -r '.head.repo.full_name // ""' <<<"$meta")"

[[ "$state" == 'open' ]] || fail 'PR is no longer open'
[[ "$draft" == 'false' ]] || fail 'draft PR is not eligible'
[[ "$base_ref" == 'main' ]] || fail 'PR base is not main'
[[ "$head_repo" == "$repo" ]] || fail 'fork PR is not eligible'
[[ "$remote_head_ref" == "$head_ref" ]] || fail 'PR head ref changed'
[[ "$remote_head_sha" == "$expected_head_sha" ]] || fail 'PR head SHA changed'

work_parent=/home/ubuntu/.cache/shopvivaliz-pr-healer-work
install -d -m 700 "$work_parent"
work="$(mktemp -d "${work_parent}/pr-${pr_number}-XXXXXX")"
cleanup() {
  rm -rf "$work"
}
trap cleanup EXIT

# Clone into an isolated temporary checkout. Never touch production/current,
# shared data, or the Agent Bridge working tree.
git clone -q --filter=blob:none --no-tags "https://github.com/${repo}.git" "$work/repo"
cd "$work/repo"
git config user.name 'shopvivaliz-pr-auto-healer[bot]'
git config user.email 'shopvivaliz-pr-auto-healer@users.noreply.github.com'
git fetch --no-tags origin \
  "+refs/heads/main:refs/remotes/origin/main" \
  "+refs/heads/${head_ref}:refs/remotes/origin/${head_ref}" >/dev/null
git checkout -B "$head_ref" "origin/$head_ref" >/dev/null
[[ "$(git rev-parse HEAD)" == "$expected_head_sha" ]] || fail 'checked-out SHA differs from expected'

before_sha="$(git rev-parse HEAD)"
merge_clean=0
if git merge --no-edit --no-ff origin/main; then
  merge_clean=1
fi

if [[ "$merge_clean" -eq 0 ]]; then
  mapfile -t unresolved < <(git diff --name-only --diff-filter=U)
  (( ${#unresolved[@]} > 0 )) || fail 'merge failed without conflict entries'
  printf 'pr_conflict_count=%s\n' "${#unresolved[@]}"
  [[ -r "$trusted_healer" ]] || fail 'trusted healer unavailable'
  [[ -r "$shared_env" ]] || fail 'private runtime env unavailable'
  command -v python3 >/dev/null || fail 'python3 unavailable on Oracle'
  # Only real conflicts consume the private Gemini pool.
  GEMINI_ENV_FILE="$shared_env" python3 "$trusted_healer" --credential-preflight
  GEMINI_ENV_FILE="$shared_env" python3 "$trusted_healer"
  [[ -z "$(git diff --name-only --diff-filter=U)" ]] || fail 'unmerged paths remain after Gemini'
  git diff --cached --check
  git commit -m "chore: auto-resolve PR #${pr_number} conflicts with main via Gemini" >/dev/null
fi

after_sha="$(git rev-parse HEAD)"
if [[ "$after_sha" == "$before_sha" ]]; then
  echo 'pr_branch_already_current=true'
  echo "pr_head_sha=${after_sha}"
else
  # Before publication, ensure nobody moved the PR branch. This makes the push
  # strictly fast-forward and scoped to the original PR head.
  latest="$(fetch_pr_meta | jq -r '.head.sha')"
  [[ "$latest" == "$expected_head_sha" ]] || fail 'remote PR branch moved before publication'
  remote_sha="$(git rev-parse "refs/remotes/origin/${head_ref}")"
  git merge-base --is-ancestor "$remote_sha" HEAD || fail 'publication would not be fast-forward'

  # A self-hosted GitHub Actions job provides GH_TOKEN=github.token for
  # read-only orchestration. That token is the Actions bot: a branch push
  # authenticated by it makes all PR checks action_required, with no jobs.
  # Select the independently provisioned operator CLI account explicitly.
  # This also refuses to publish when the VM's real technical credential is
  # absent; NEVER fall back to the bot, human owner, secrets copied from CI,
  # or a change to branch protections.
  command -v gh >/dev/null || fail 'gh unavailable on Oracle for branch publication'
  independent_actor="$(env -u GH_TOKEN -u GITHUB_TOKEN -u GIT_ASKPASS -u SSH_ASKPASS \
    gh api user --jq .login 2>/dev/null)" || fail 'independent_github_auth_unavailable'
  [[ "$independent_actor" == 'fredmourao-ai' ]] || fail 'independent_github_actor_not_approved'
  env -u GH_TOKEN -u GITHUB_TOKEN -u GIT_ASKPASS -u SSH_ASKPASS \
    gh auth setup-git >/dev/null || fail 'independent_git_credential_helper_unavailable'
  external_github_auth_used=true

  # ALLOW_SCOPED_PUSH: technical account only, same-repository branch, CAS,
  # fast-forward publication; never a protected ref or blanket GitHub token.
  env -u GH_TOKEN -u GITHUB_TOKEN -u GIT_ASKPASS -u SSH_ASKPASS \
    git push origin "HEAD:refs/heads/${head_ref}" >/dev/null
  echo 'pr_branch_update_pushed=true'
  echo "pr_head_sha=${after_sha}"
fi

echo "external_github_auth_used=${external_github_auth_used}"
echo 'secret_values_printed=false'
