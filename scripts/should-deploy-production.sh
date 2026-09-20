#!/usr/bin/env bash
set -euo pipefail

# Production parity is mandatory for every revision that reaches main.
#
# This repository's deployed release records an exact .release-sha. Treating
# documentation-, policy-, workflow-, or test-only commits as "no deploy"
# leaves production behind origin/main and lets agents stop in an intermediate
# state. The Master Production Pipeline may still exclude non-runtime paths
# from rsync, but it must create/activate a release for the exact main SHA.
#
# Empty/unknown change sets also deploy conservatively.
printf '%s\n' true
