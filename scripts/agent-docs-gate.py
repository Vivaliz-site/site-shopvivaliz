#!/usr/bin/env python3
"""CLI compatibility wrapper for the mandatory agent docs preflight."""
from __future__ import annotations

from agent_docs_gate import main


if __name__ == "__main__":
    raise SystemExit(main())
