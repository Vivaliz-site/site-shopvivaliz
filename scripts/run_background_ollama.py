#!/usr/bin/env python3
"""Bounded, local-only Ollama analysis for free background provider tier.

Ollama has no privileged tools in this adapter. It cannot claim task progress:
the caller independently verifies the durable checkpoint and code changes.
No raw task content or model response is printed to logs.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import sys
from urllib.error import HTTPError, URLError
from urllib.request import Request, ProxyHandler, build_opener

ALLOWED_ENDPOINTS = frozenset({
    "http://127.0.0.1:11434",
    "http://localhost:11434",
})
DEFAULT_MODEL = "qwen2.5-coder:1.5b"


def run(prompt_file: Path, model: str = DEFAULT_MODEL,
        endpoint: str = "http://127.0.0.1:11434", timeout: int = 70) -> int:
    if endpoint.rstrip("/") not in ALLOWED_ENDPOINTS:
        print("background_ollama_reason=endpoint_not_local")
        return 78
    if not model or len(model) > 100 or any(c not in
        "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._:-" for c in model):
        print("background_ollama_reason=invalid_model")
        return 64
    try:
        if not prompt_file.is_file() or prompt_file.stat().st_size > 65536:
            print("background_ollama_reason=prompt_unavailable")
            return 66
        prompt = prompt_file.read_text(encoding="utf-8", errors="strict")
        payload = json.dumps({
            "model": model,
            "prompt": (
                "Analyze the next step briefly. Do not claim to have executed tools, "
                "changed files, or completed tasks. Do not reproduce credentials. "
                "This analysis is advisory only.\n" + prompt
            ),
            "stream": False,
            "keep_alive": "15m",
            "options": {"temperature": 0.1, "num_predict": 128},
        }, ensure_ascii=False).encode("utf-8")
        request = Request(
            endpoint.rstrip("/") + "/api/generate",
            data=payload,
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        with build_opener(ProxyHandler({})).open(request, timeout=max(1, min(int(timeout), 90))) as response:
            if response.status != 200:
                print("background_ollama_reason=provider_unavailable")
                return 69
            body = response.read(131073)
        if len(body) > 131072:
            print("background_ollama_reason=response_too_large")
            return 70
        result = json.loads(body)
        if not isinstance(result, dict) or not result.get("done") or not result.get("response"):
            print("background_ollama_reason=empty_or_incomplete")
            return 75
        count = result.get("eval_count", 0)
        print("background_ollama_reason=advisory_complete")
        print("background_ollama_model=" + model)
        print("background_ollama_generated_tokens=" + str(max(0, int(count))))
        print("background_ollama_task_state_advanced=false")
        return 0
    except (OSError, ValueError, UnicodeError, URLError, HTTPError, TimeoutError, KeyError):
        print("background_ollama_reason=local_request_failed")
        return 69


def main() -> int:
    parser = argparse.ArgumentParser(description="Local Ollama only; no paid APIs.")
    parser.add_argument("--prompt-file", type=Path, required=True)
    parser.add_argument("--model", default=os.environ.get("SHOPVIVALIZ_OLLAMA_MODEL", DEFAULT_MODEL))
    parser.add_argument("--endpoint", default=os.environ.get("SHOPVIVALIZ_OLLAMA_URL", "http://127.0.0.1:11434"))
    parser.add_argument("--timeout", type=int, default=70)
    args = parser.parse_args()
    return run(args.prompt_file, args.model, args.endpoint, args.timeout)


if __name__ == "__main__":
    raise SystemExit(main())
