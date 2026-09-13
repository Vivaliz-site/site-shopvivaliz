#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
script="$root/scripts/install-apache-hardening.sh"
conf="$root/deploy/apache/shopvivaliz-log-redaction.conf"

[[ -f "$conf" ]] || {
  echo "missing log-redaction config" >&2
  exit 1
}
grep -Fq 'shopvivaliz-log-redaction.conf' "$script" || {
  echo "installer does not install log redaction" >&2
  exit 1
}
grep -Fq 'a2enconf shopvivaliz-log-redaction' "$script" || {
  echo "installer does not enable log redaction" >&2
  exit 1
}
grep -Eq '^LogFormat .*%U .* combined$' "$conf" || {
  echo "combined LogFormat must log path with %U" >&2
  exit 1
}
if grep -Eq '%[rq]' "$conf"; then
  echo "log redaction config must not log request/query via %r or %q" >&2
  exit 1
fi

printf 'apache-access-log-redaction-contract: ok\n'
