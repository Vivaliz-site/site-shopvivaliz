#!/usr/bin/env bash
set -euo pipefail
F=tests/home-category-image-audit.mjs
grep -Fq "const loopbackBase =" "$F" || { echo "loopback detection missing" >&2; exit 1; }
grep -Fq "process.env.GITHUB_EVENT_NAME === 'pull_request' && !loopbackBase" "$F" || { echo "PR audit must not double-inject scripts into isolated localhost storefront" >&2; exit 1; }
grep -Fq "img.naturalWidth > 1" "$F" || { echo "audit must still require genuinely loaded images" >&2; exit 1; }
echo HOME_CATEGORY_IMAGE_AUDIT_LOOPBACK_REGRESSION_OK
