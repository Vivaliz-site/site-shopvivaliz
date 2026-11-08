#!/usr/bin/env python3
"""Safe Cloudflare credential ROUTE discovery before asking an operator for secrets.

Never reads/displays secret values. A GitHub Actions secret exists only in a
workflow job that explicitly injects it; presence is NOT an API grant.
"""
import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess

SECRET_NAME = "CLOUDFLARE_API_TOKEN"
SECRET_CHOICES = ("CLOUDFLARE_API_TOKEN", "CLOUDFLARE_DNS_EDIT_TOKEN", "CLOUDFLARE_MARINA_TUNNEL_TOKEN")
REPO = "Vivaliz-site/site-shopvivaliz"


def has_cloudflare_secret(names, secret_name=SECRET_NAME):
    """Require exact Actions secret name, not a similarly named token."""
    return secret_name in names


def decide_route(*, github, process, local_file, browser):
    """Prefer an already usable route; unknown sources never prove absence."""
    valid = {"present", "absent", "unknown"}
    if github not in valid or process not in valid or local_file not in valid:
        raise ValueError("invalid_secret_source_state")
    if browser not in {"authenticated", "absent", "unknown"}:
        raise ValueError("invalid_browser_state")
    sources = (github, process, local_file)
    if process == "present":
        route, availability = "current_process_existing_secret", "provisioned_current_process"
    elif github == "present":
        route, availability = "github_actions_existing_secret", "provisioned_other_context"
    elif local_file == "present":
        route, availability = "approved_local_secret_injection", "provisioned_other_context"
    elif browser == "authenticated":
        route, availability = "existing_authenticated_browser", "provisioned_browser_session"
    elif "unknown" in sources or browser == "unknown":
        route, availability = "verify_existing_sources", "not_verified"
    else:
        route, availability = "seek_authorized_provisioning", "verified_absent"
    return {
        "credential_availability": availability,
        "next_route": route,
        "request_new_credential": availability == "verified_absent",
        "usable_in_current_process": process == "present",
    }


def github_secret_state(repo=REPO, secret_name=SECRET_NAME):
    """Only list secret names, authenticated as the authorized gh OS user."""
    if not shutil.which("gh"):
        return "unknown"
    try:
        run = subprocess.run(
            ["gh", "api", "--paginate", f"repos/{repo}/actions/secrets?per_page=100",
             "--jq", ".secrets[].name"],
            capture_output=True, text=True, timeout=15, check=False,
        )
    except (OSError, subprocess.TimeoutExpired):
        return "unknown"
    if run.returncode != 0:
        return "unknown"  # API 403, expired gh session != secret absent.
    return "present" if has_cloudflare_secret(set(run.stdout.splitlines()), secret_name) else "absent"


def local_file_state(paths):
    """Only inspect caller-supplied file metadata, not contents or private paths."""
    if not paths:
        return "unknown"
    for raw in paths:
        try:
            p = Path(raw)
            if p.is_file():
                return "present"
        except OSError:
            return "unknown"
    return "absent"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--repo", default=REPO)
    parser.add_argument("--required-secret", choices=SECRET_CHOICES, default=SECRET_NAME)
    parser.add_argument("--local-file", action="append", default=[], help="Known protected path (presence check only)")
    parser.add_argument("--browser", choices=("authenticated", "absent", "unknown"), default="unknown",
                        help="Use authenticated only if a live UI identity check already passed")
    args = parser.parse_args()
    sources = {
        "github": github_secret_state(args.repo, args.required_secret),
        "process": "present" if os.environ.get(args.required_secret) else "absent",
        "local_file": local_file_state(args.local_file),
        "browser": args.browser,
    }
    result = decide_route(**sources)
    print(json.dumps({"provider": "cloudflare", "required_secret": args.required_secret, "source_status": sources, **result,
                      "provider_api_scope_verified": False}, sort_keys=True))
    # A route plan is a diagnostic, not a provider operation or a secret grant.
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
