#!/usr/bin/env python3
"""Root-only, bounded stdio adapter for the loopback Remote Control MCP.

Administrative commands longer than the inline budget use the existing durable
queue. A lost response is indeterminate, not permission to replay a mutation.
"""
from __future__ import annotations

import errno
import json
import sys
import urllib.error
import urllib.request
import uuid
from pathlib import Path
from typing import Any

TOKEN_PATH = Path("/var/lib/shopvivaliz-remote-control/mcp-token")
SERVICE_ENV_PATH = Path("/var/lib/shopvivaliz-remote-control/service.env")
MCP_URLS = (
    "http://127.0.0.1:5581/mcp",
    "http://127.0.0.1:5580/mcp",
)
MAX_MESSAGE = 1_048_576
HTTP_TIMEOUT = 30
INLINE_BUDGET = 20
DEFAULT_COMMAND_TIMEOUT = 30
MAX_DURABLE_TIMEOUT_DEFAULT = 7200
MAX_DURABLE_TIMEOUT_HARD = 86400


def configured_max_durable_timeout() -> int:
    value = MAX_DURABLE_TIMEOUT_DEFAULT
    try:
        for raw_line in SERVICE_ENV_PATH.read_text(encoding="utf-8").splitlines():
            key, separator, raw_value = raw_line.partition("=")
            if separator and key == "SHOPVIVALIZ_REMOTE_MCP_MAX_DURABLE_TIMEOUT":
                value = int(raw_value.strip())
                break
    except (OSError, UnicodeError, ValueError):
        value = MAX_DURABLE_TIMEOUT_DEFAULT
    return max(900, min(value, MAX_DURABLE_TIMEOUT_HARD))


def error_response(request: Any, message: str, **data: Any) -> dict[str, Any]:
    rid = request.get("id") if isinstance(request, dict) else None
    error: dict[str, Any] = {"code": -32000, "message": message}
    if data:
        error["data"] = data
    return {"jsonrpc": "2.0", "id": rid, "error": error}


def prepare_request(request: dict[str, Any]) -> tuple[dict[str, Any], bool]:
    """Choose execution mode before sending anything; preserve command limits."""
    if request.get("method") != "tools/call":
        return request, False
    params = request.get("params")
    if not isinstance(params, dict) or not isinstance(params.get("arguments", {}), dict):
        raise ValueError("invalid_tool_arguments")
    name = params.get("name")
    if name not in {"admin_command_run", "task_submit"}:
        return request, False
    args = dict(params.get("arguments", {}))
    promoted = False
    if name == "admin_command_run":
        timeout = args.get("timeout", DEFAULT_COMMAND_TIMEOUT)
        if isinstance(timeout, bool) or not isinstance(timeout, int) or not 1 <= timeout <= configured_max_durable_timeout():
            raise ValueError("invalid_timeout")
        durable = args.get("durable")
        if durable is not None and not isinstance(durable, bool):
            raise ValueError("invalid_durable")
        if durable is False and timeout > INLINE_BUDGET:
            raise ValueError("inline_budget_exceeded_use_task_submit")
        if durable is not True and timeout <= INLINE_BUDGET:
            return request, False
        args["timeout"] = timeout
        args.pop("durable", None)
        name = "task_submit"
        promoted = True
    if "request_id" not in args:
        # One identity per submission, retained across a safe pre-connect fallback.
        # Never deduplicate intentional later runs by command text alone.
        args["request_id"] = "stdio-" + uuid.uuid4().hex
    return {**request, "params": {**params, "name": name, "arguments": args}}, promoted


def forward(raw: bytes) -> dict[str, Any] | None:
    if not raw or len(raw) > MAX_MESSAGE:
        return error_response(None, "invalid_message_size")
    try:
        request = json.loads(raw)
    except (json.JSONDecodeError, UnicodeDecodeError):
        return error_response(None, "invalid_json")
    if not isinstance(request, dict):
        return error_response(None, "invalid_request")
    try:
        prepared, promoted = prepare_request(request)
    except ValueError as exc:
        return error_response(request, str(exc))
    params = prepared.get("params")
    args = params.get("arguments", {}) if isinstance(params, dict) else {}
    request_id = args.get("request_id") if isinstance(args, dict) else None
    try:
        token = TOKEN_PATH.read_text(encoding="utf-8").strip()
    except (OSError, UnicodeError):
        return error_response(request, "controller_token_unavailable")
    if not token or any(ch.isspace() for ch in token):
        return error_response(request, "controller_token_unavailable")
    payload = json.dumps(prepared, ensure_ascii=False).encode("utf-8")
    if len(payload) > MAX_MESSAGE:
        return error_response(request, "invalid_message_size")
    for index, mcp_url in enumerate(MCP_URLS):
        req = urllib.request.Request(
            mcp_url,
            data=payload,
            headers={"Content-Type": "application/json", "Authorization": "Bearer " + token},
            method="POST",
        )
        try:
            with urllib.request.urlopen(req, timeout=HTTP_TIMEOUT) as response:
                body = response.read(MAX_MESSAGE + 1)
                status = response.status
        except urllib.error.HTTPError as exc:
            status = exc.code
            exc.close()
            return error_response(request, "controller_http_" + str(status))
        except (OSError, urllib.error.URLError) as exc:
            reason = exc.reason if isinstance(exc, urllib.error.URLError) else exc
            # Only ECONNREFUSED proves this endpoint never accepted the request.
            # A timeout/reset can occur AFTER a command or submission has run.
            refused = isinstance(reason, OSError) and reason.errno == errno.ECONNREFUSED
            if refused and index + 1 < len(MCP_URLS):
                continue
            if refused:
                return error_response(request, "controller_unavailable", retry_safe=True)
            data: dict[str, Any] = {"retry_safe": False}
            if request_id:
                data["request_id"] = request_id
            return error_response(request, "controller_response_indeterminate", **data)
        if status == 202:
            return None
        if status != 200:
            return error_response(request, "controller_http_" + str(status))
        if len(body) > MAX_MESSAGE:
            return error_response(request, "controller_response_too_large")
        try:
            result = json.loads(body)
        except (json.JSONDecodeError, UnicodeDecodeError):
            return error_response(request, "controller_invalid_json")
        if not isinstance(result, dict):
            return error_response(request, "controller_invalid_json")
        if promoted and isinstance(result.get("result"), dict) and not result["result"].get("isError"):
            output = result["result"].get("structuredContent")
            if not isinstance(output, dict) or not output.get("task_id"):
                return error_response(request, "controller_invalid_durable_response", retry_safe=False, request_id=request_id)
            output.update(execution_mode="durable", requested_tool="admin_command_run", request_id=request_id)
            result["result"]["content"] = [{"type": "text", "text": json.dumps(output, ensure_ascii=False)}]
        return result
    return error_response(request, "controller_unavailable", retry_safe=True)


def main() -> int:
    if len(sys.argv) != 1:
        raise SystemExit(64)
    for line in sys.stdin.buffer:
        raw = line.strip()
        if not raw:
            continue
        result = forward(raw)
        if result is not None:
            sys.stdout.write(json.dumps(result, ensure_ascii=False, separators=(",", ":")) + "\n")
            sys.stdout.flush()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
