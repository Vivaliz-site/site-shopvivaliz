#!/usr/bin/env python3
"""
Compatibilidade para importar o cliente MCP a partir de scripts/mcp-client.py.
"""

from __future__ import annotations

import importlib.util
from pathlib import Path


_MODULE_PATH = Path(__file__).with_name("mcp-client.py")
_SPEC = importlib.util.spec_from_file_location("scripts.mcp_client_legacy", _MODULE_PATH)
if _SPEC is None or _SPEC.loader is None:
    raise ImportError(f"Unable to load MCP client from {_MODULE_PATH}")

_MODULE = importlib.util.module_from_spec(_SPEC)
_SPEC.loader.exec_module(_MODULE)

MCPClient = _MODULE.MCPClient
MCPCloudManager = _MODULE.MCPCloudManager

__all__ = ["MCPClient", "MCPCloudManager"]
