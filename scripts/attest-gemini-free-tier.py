#!/usr/bin/env python3
"""Operator-only record for Free-tier Gemini API key/project verification.

This command never calls Gemini. Confirm project tier in the official Google
AI Studio before using --confirm-free-tier. No secret is printed or stored.
"""
from __future__ import annotations
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import tempfile
import run_background_gemini as guard

def main() -> int:
    parser=argparse.ArgumentParser(description=__doc__)
    group=parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--status",action="store_true")
    group.add_argument("--confirm-free-tier",action="store_true")
    parser.add_argument("--project-id")
    parser.add_argument("--key-source",choices=guard.SUPPORTED_KEYS)
    parser.add_argument("--env-file",type=Path,default=Path(os.getenv("GEMINI_ENV_FILE",str(guard.DEFAULT_ENV_FILE))))
    parser.add_argument("--proof-file",type=Path,default=guard.DEFAULT_TIER_ATTESTATION)
    args=parser.parse_args()
    if not args.env_file.is_file():
        print("GEMINI_TIER_STATUS=KEY_SOURCE_NOT_AVAILABLE")
        return 2
    try:
        creds=guard.select_gemini_credentials(args.env_file.read_text(encoding="utf-8"))
    except OSError:
        print("GEMINI_TIER_STATUS=KEY_SOURCE_UNREADABLE")
        return 2
    if args.status:
        approved=guard.free_tier_approved_credentials(creds,args.proof_file)
        print("GEMINI_TIER_STATUS="+("FREE_ATTESTED" if approved else "UNVERIFIED"))
        print("GEMINI_APPROVED_KEY_COUNT="+str(len(approved)))
        return 0
    if not args.project_id or not args.key_source:
        parser.error("--confirm-free-tier requires --project-id and --key-source")
    if not re.fullmatch(r"[a-z][a-z0-9-]{2,62}",args.project_id):
        parser.error("invalid Google project identifier")
    matching=[(source,value) for source,value in creds if source==args.key_source]
    if not matching:
        print("GEMINI_TIER_STATUS=KEY_SOURCE_NOT_AVAILABLE")
        return 3
    # The operator must have viewed the matching key/project in Google
    # AI Studio and confirmed Billing tier = Free. Do not infer from model.
    payload={
        "schema_version":1,
        "billing_tier":"free",
        "verified_source":"google_ai_studio",
        "verified_at":datetime.datetime.now(datetime.timezone.utc).isoformat(),
        "project_id":args.project_id,
        "key_sha256":[hashlib.sha256(value.encode()).hexdigest() for _,value in matching],
    }
    parent=args.proof_file.parent
    parent.mkdir(mode=0o700,parents=True,exist_ok=True)
    os.chmod(parent,0o700)
    fd,temp=tempfile.mkstemp(prefix=".tier-proof-",dir=parent)
    try:
        with os.fdopen(fd,"w",encoding="utf-8") as f:
            json.dump(payload,f,sort_keys=True)
            f.write("\n")
            f.flush()
            os.fsync(f.fileno())
        os.chmod(temp,0o600)
        os.replace(temp,args.proof_file)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)
    print("GEMINI_TIER_STATUS=FREE_ATTESTED_BY_OPERATOR")
    print("VERIFICATION_RENEWAL_DAYS="+str(guard.MAX_FREE_TIER_ATTESTATION_AGE_DAYS))
    return 0

if __name__=="__main__":
    raise SystemExit(main())
