#!/usr/bin/env python3
from __future__ import annotations

import argparse
import csv
import html
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
SPACE