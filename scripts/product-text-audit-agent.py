#!/usr/bin/env python3
from __future__ import annotations

import argparse
import csv
import json
import re
import sys
import urllib.error
import urllib.request
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

DEFAULT_CATALOG_URL = "https://www.shopvivaliz.com.br/api/catalog/products.php?limit=5000"
DEFAULT_OUT_DIR = Path("audit-output")
SPACE_RE = re.compile(r"\s+")
WORD_RE = re.compile(r"[A-Za-zÀ-ÖØ-öø-ÿ0-9]+", re.UNICODE)


def clean(value: Any) -> str:
    if value is None:
        return ""
    text = re.sub(r"<[^>]+>", " ", str(value))
    return SPACE_RE.sub(" ", text).strip()


def fetch_catalog(url: str)