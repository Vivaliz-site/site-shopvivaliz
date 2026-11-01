#!/usr/bin/env python3
"""Bounded, nonprivileged diagnostics for Marina's independent MCP.

No shell/commands, caller paths, host/IP selection, environment variables,
secret files, process arguments or service mutations are accepted.
"""
from __future__ import annotations

import os
import re
import shutil
import stat
import subprocess

PUBLIC_KERNEL_FILES = {
    "uptime": "/proc/uptime",
    "loadavg": "/proc/loadavg",
    "meminfo": "/proc/meminfo",
}
MAX_TELEMETRY_BYTES = 4096
MAX_PROCESS_BYTES = 16384
MAX_PROCESSES = 48
_COMM = re.compile(r"^[A-Za-z0-9_.:+/@-]{1,55}$")


def public_telemetry_read(source: str) -> dict:
    """Read only fixed, public Linux metrics with O_NOFOLLOW and strict bounds."""
    if type(source) is not str or source not in PUBLIC_KERNEL_FILES:
        raise ValueError("telemetry_source_not_allowed")
    path = PUBLIC_KERNEL_FILES[source]
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
        with os.fdopen(fd, "rb") as handle:
            metadata = os.fstat(handle.fileno())
            if not stat.S_ISREG(metadata.st_mode) or metadata.st_size > MAX_TELEMETRY_BYTES:
                raise RuntimeError("telemetry_file_not_safe")
            raw = handle.read(MAX_TELEMETRY_BYTES + 1)
    except OSError:
        raise RuntimeError("telemetry_read_unavailable") from None
    if len(raw) > MAX_TELEMETRY_BYTES or not raw or b"\0" in raw:
        raise RuntimeError("telemetry_data_unsafe")
    try:
        value = raw.decode("ascii")
    except UnicodeDecodeError:
        raise RuntimeError("telemetry_encoding_invalid") from None
    # The user gets only the symbolic resource name; no arbitrary path
    # or filesystem implementation detail is included in the response.
    return {"source": source, "data": value}


def public_telemetry_batch(sources: object) -> dict:
    if (not isinstance(sources, list) or not 1 <= len(sources) <= 3
            or any(type(x) is not str or x not in PUBLIC_KERNEL_FILES for x in sources)
            or len(set(sources)) != len(sources)):
        raise ValueError("telemetry_batch_not_allowed")
    return {"sources": [public_telemetry_read(source) for source in sources]}


def public_processes_list() -> dict:
    """Only PID and short executable basename; no argv, UID or environments."""
    try:
        result = subprocess.run(
            ["/usr/bin/ps", "-eo", "pid=,comm=", "--sort=pid"],
            capture_output=True, text=True, timeout=3, check=False,
        )
    except (OSError, subprocess.TimeoutExpired):
        raise RuntimeError("process_metadata_unavailable") from None
    if result.returncode != 0:
        raise RuntimeError("process_metadata_unavailable")
    if len(result.stdout) > MAX_PROCESS_BYTES * 8:
        raise RuntimeError("process_metadata_oversize")
    entries: list[dict] = []
    for raw in result.stdout.splitlines():
        fields = raw.strip().split(None, 1)
        if len(fields) != 2 or not fields[0].isdigit() or not _COMM.fullmatch(fields[1]):
            continue
        pid = int(fields[0])
        if pid <= 0:
            continue
        entries.append({"pid": pid, "process": fields[1]})
        if len(entries) >= MAX_PROCESSES:
            break
    return {"host": "marina-a1-4c24g", "processes": entries, "limit": MAX_PROCESSES}


def public_system_usage() -> dict:
    """CPU, aggregate load, public RAM counters and root filesystem totals."""
    try:
        load = os.getloadavg()
        capacity = shutil.disk_usage("/")
        source = public_telemetry_read("meminfo")["data"]
        memory = {}
        for line in source.splitlines():
            if line.startswith(("MemTotal:", "MemAvailable:")):
                name, rest = line.split(":", 1)
                match = re.fullmatch(r"\s*([0-9]+)\s+kB\s*", rest)
                if match:
                    memory[name] = int(match.group(1)) * 1024
        if len(memory) != 2:
            raise RuntimeError("memory_metadata_unavailable")
    except (OSError, OverflowError, ValueError):
        raise RuntimeError("system_usage_unavailable") from None
    return {
        "host": "marina-a1-4c24g",
        "cpu_count": max(1, int(os.cpu_count() or 1)),
        "load_1m": round(float(load[0]), 2),
        "load_5m": round(float(load[1]), 2),
        "load_15m": round(float(load[2]), 2),
        "memory_total_bytes": memory["MemTotal"],
        "memory_available_bytes": memory["MemAvailable"],
        "root_total_bytes": capacity.total,
        "root_free_bytes": capacity.free,
    }
