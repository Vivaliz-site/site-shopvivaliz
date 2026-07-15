#!/usr/bin/env python3
from __future__ import annotations

import asyncio
import json
import os
import sys
from pathlib import Path
from typing import Any
from urllib.parse import urlparse

try:
    from playwright.async_api import async_playwright, Browser, BrowserContext, Page
except ImportError:
    print(json.dumps({"error": "playwright_missing", "install": "pip install playwright && playwright install chromium"}), file=sys.stderr)
    raise

