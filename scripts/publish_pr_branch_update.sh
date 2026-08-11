#!/usr/bin/env bash
set -Eeuo pipefail

# ALLOW_SCOPED_PUSH
# Reviewed publication boundary for PR Auto-Healer updates. This helper may
# publish only the already-checked-out same-repository PR branch. It refuses
# protected branch names, ref syntax outside our conservative allowlist and
# non-fast-forward updates. There is deliberately no force option.

head_ref="${1:-}"
expected_remote="${2:-origin}"

if [[ -z "$head_ref" ]]; then
  echo 'ERROR missing PR head ref' >&2
  exit 2
fi

if [[ "$expected_remote" != 'origin' ]]; then
  echo 'ERROR only origin remote is allowed' >&2
  exit 3
fi

if [[ "$head_ref" == 'main' || "$head_ref" == 'master' ]]; then
  echo 'ERROR protected branch publication is forbidden' >&2
  exit 4
fi

# Conservative Git ref subset used by agent/feature/fix/policy branches.
if [[ ! "$head_ref" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]{0,199}$ ]]; then
  echo 'ERROR unsafe PR head ref syntax' >&2
  exit 5
fi

if [[ "$head_ref" == *'..'* || "$head_ref" == *'@{'* || "$head_ref" == */. || "$head_ref" == ./* || "$head_ref" == */ ]]; then
  echo 'ERROR unsafe PR head ref structure' >&2
  exit 6
fi

current_branch="$(git branch --show-current)"
if [[ "$current_branch" != "$head_ref" ]]; then
  echo 'ERROR checked-out branch does not match requested PR head ref' >&2
  exit 7
fi

remote_url="$(git remote get-url "$expected_remote")"
case "$remote_url" in
  https://github.com/Vivaliz-site/site-shopvivaliz|https://github.com/Vivaliz-site/site-shopvivaliz.git|git@github.com:Vivaliz-site/site-shopvivaliz.git)
    ;;
  *)
    echo 'ERROR origin does not point to the authorized repository' >&2
    exit 8
    ;;
esac

# Refuse a non-fast-forward rewrite even if remote changed after checkout.
git fetch --no-tags "$expected_remote" "+refs/heads/${head_ref}:refs/remotes/${expected_remote}/${head_ref}"
remote_sha="$(git rev-parse "refs/remotes/${expected_remote}/${head_ref}")"
local_sha="$(git rev-parse HEAD)"
if ! git merge-base --is-ancestor "$remote_sha" "$local_sha"; then
  echo 'ERROR remote PR branch moved or update is not fast-forward' >&2
  exit 9
fi

# ALLOW_SCOPED_PUSH: same-repository PR branch only, no force and never main/master.
git push "$expected_remote" "HEAD:refs/heads/${head_ref}"
echo "published_pr_branch=${head_ref}"
echo "published_sha=${local_sha}"
