#!/usr/bin/env python3
from __future__ import annotations

import csv
import json
import re
import sys
import urllib.request
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

CATALOG_URL = "https://shopvivaliz.com.br/api/catalog/products.php?limit=200"
OUT_DIR = Path("audit-output")


def fetch_catalog() -> dict[str, Any]:
    request = urllib.request.Request(
        CATALOG_URL,
        headers={"User-Agent": "ShopVivalizProductTextAudit/1.0", "Accept": "application/json"},
    )
   