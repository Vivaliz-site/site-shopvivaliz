#!/usr/bin/env python3
"""ShopVivaliz ecommerce excellence audit.

Static mode inventories every tracked file and validates common formats,
local references and production-risk markers. Live mode validates SEO,
sitemap, Merchant feed, security headers and representative public URLs.
No secret values are read or printed.
"""
from __future__ import annotations

import argparse
import collections
import hashlib
import html.parser
import json
import os
import pathlib
import re
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from dataclasses import dataclass, asdict
from typing import Any

ROOT = pathlib.Path(__file__).resolve().parents[1]
PRODUCTION_EXTENSIONS = {".php", ".js", ".css", ".html", ".htm", ".json", ".xml", ".yml", ".yaml", ".sh", ".py"}
REQUIRED_FILES = {
    "index.php", "catalogo.php", "produto.php", "carrinho.php", "checkout.php",
    "robots.txt", "sitemap.php", "google-merchant-feed.php",
    "api/orders/create.php", "api/webhook-mercadopago.php",
    "api/emails/send-order-notification.php", "includes/head-analytics.php",
}
IGNORE_REFERENCE_PREFIXES = ("http://", "https://", "//", "data:", "mailto:", "tel:", "#", "${", "{{")


@dataclass
class Finding:
    severity: str
    code: str
    message: str
    path: str = ""


class HeadParser(html.parser.HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.title = ""
        self.in_title = False
        self.h1 = 0
        self.meta: dict[str, str] = {}
        self.links: list[dict[str, str]] = []
        self.jsonld: list[str] = []
        self._script_type = ""
        self._script_buffer: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = {k.lower(): (v or "") for k, v in attrs}
        if tag.lower() == "title":
            self.in_title = True
        elif tag.lower() == "h1":
            self.h1 += 1
        elif tag.lower() == "meta":
            key = values.get("name") or values.get("property")
            if key:
                self.meta[key.lower()] = values.get("content", "")
        elif tag.lower() == "link":
            self.links.append(values)
        elif tag.lower() == "script":
            self._script_type = values.get("type", "").lower()
            self._script_buffer = []

    def handle_endtag(self, tag: str) -> None:
        if tag.lower() == "title":
            self.in_title = False
        elif tag.lower() == "script":
            if self._script_type == "application/ld+json":
                self.jsonld.append("".join(self._script_buffer).strip())
            self._script_type = ""
            self._script_buffer = []

    def handle_data(self, data: str) -> None:
        if self.in_title:
            self.title += data
        if self._script_type == "application/ld+json":
            self._script_buffer.append(data)


def tracked_files() -> list[pathlib.Path]:
    result = subprocess.run(
        ["git", "ls-files", "-z"], cwd=ROOT, check=True, capture_output=True
    ).stdout.decode("utf-8", errors="replace")
    return [ROOT / item for item in result.split("\0") if item]


def changed_tracked_files(base: str, head: str) -> list[pathlib.Path]:
    result = subprocess.run(
        ["git", "diff", "--name-only", "-z", "--diff-filter=ACMR", base, head],
        cwd=ROOT,
        check=True,
        capture_output=True,
    ).stdout.decode("utf-8", errors="replace")
    tracked = {str(path.relative_to(ROOT)).replace("\\", "/") for path in tracked_files()}
    files = []
    for item in result.split("\0"):
        if item and item in tracked:
            files.append(ROOT / item)
    return files


def add(findings: list[Finding], severity: str, code: str, message: str, path: pathlib.Path | str = "") -> None:
    relative = ""
    if path:
        try:
            relative = str(pathlib.Path(path).resolve().relative_to(ROOT))
        except Exception:
            relative = str(path)
    findings.append(Finding(severity, code, message, relative))


def validate_static(files: list[pathlib.Path] | None = None, *, scope: str = "full") -> dict[str, Any]:
    findings: list[Finding] = []
    all_files = tracked_files()
    files = all_files if files is None else files
    extension_counts: collections.Counter[str] = collections.Counter()
    hashes: dict[str, list[str]] = collections.defaultdict(list)
    total_bytes = 0
    empty_files = 0
    validated = collections.Counter()

    relative_set = {str(path.relative_to(ROOT)).replace("\\", "/") for path in all_files}
    if scope == "full":
        for required in sorted(REQUIRED_FILES):
            if required not in relative_set:
                add(findings, "blocker", "required_file_missing", f"Required runtime file is missing: {required}", required)

    reference_pattern = re.compile(r"(?:src|href)\s*=\s*[\"'](/[^\"'?#]+)", re.I)
    css_url_pattern = re.compile(r"url\(\s*[\"']?(/[^\"')?#]+)", re.I)
    placeholder_pattern = re.compile(
        r"\b(?:TODO|FIXME|HACK|dummy|fake|mock|lorem ipsum|example\.com|YOUR_[A-Z0-9_]+|G-XXXXXXXXXX)\b",
        re.I,
    )
    secret_literal_pattern = re.compile(
        r"(?:ghp_|github_pat_|sk-proj-|AKIA[0-9A-Z]{16}|-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----)"
    )

    for path in files:
        rel = str(path.relative_to(ROOT)).replace("\\", "/")
        if not path.exists():
            add(findings, "blocker", "tracked_file_missing", "Tracked file is missing from checkout", path)
            continue
        if path.is_symlink():
            continue
        size = path.stat().st_size
        total_bytes += size
        extension_counts[path.suffix.lower() or "[none]"] += 1
        if size == 0:
            empty_files += 1
            if path.suffix.lower() in PRODUCTION_EXTENSIONS:
                add(findings, "warning", "empty_runtime_file", "Empty tracked runtime file", path)
            continue
        if size > 10 * 1024 * 1024 and not rel.startswith(("storage/", "uploads/", "public/")):
            add(findings, "warning", "oversized_repository_file", f"Large tracked file: {size} bytes", path)

        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        hashes[digest].append(rel)
        suffix = path.suffix.lower()
        if suffix not in PRODUCTION_EXTENSIONS:
            continue
        try:
            text = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            add(findings, "warning", "invalid_utf8", "Text-like file is not UTF-8", path)
            continue

        if secret_literal_pattern.search(text):
            add(findings, "blocker", "credential_literal", "Possible credential material in tracked source", path)

        if not rel.startswith(("tests/", "docs/", "vendor/", ".github/", "scripts/ecommerce-excellence-audit.py")):
            matches = sorted({m.group(0) for m in placeholder_pattern.finditer(text)})
            if matches:
                add(findings, "warning", "production_placeholder", "Production file contains review markers: " + ", ".join(matches[:8]), path)

        if suffix == ".json":
            try:
                json.loads(text)
                validated["json"] += 1
            except json.JSONDecodeError as exc:
                add(findings, "blocker", "invalid_json", f"JSON parse error: {exc}", path)
        elif suffix == ".xml":
            try:
                ET.fromstring(text)
                validated["xml"] += 1
            except ET.ParseError as exc:
                add(findings, "blocker", "invalid_xml", f"XML parse error: {exc}", path)
        elif suffix == ".php":
            process = subprocess.run(["php", "-l", str(path)], capture_output=True, text=True)
            if process.returncode:
                add(findings, "blocker", "php_syntax", process.stderr.strip() or process.stdout.strip(), path)
            else:
                validated["php"] += 1

        if suffix in {".php", ".html", ".htm", ".js", ".css"}:
            references = [m.group(1) for m in reference_pattern.finditer(text)]
            references += [m.group(1) for m in css_url_pattern.finditer(text)]
            for reference in sorted(set(references)):
                if reference.startswith(IGNORE_REFERENCE_PREFIXES):
                    continue
                candidate = reference.lstrip("/")
                if any(token in candidate for token in ("<?=", "<?php", "${", "{{", "*")):
                    continue
                if candidate.endswith("/"):
                    continue
                if candidate not in relative_set and not (ROOT / candidate).exists():
                    add(findings, "warning", "missing_local_asset", f"Referenced local asset not found: {reference}", path)

    duplicate_groups: list[list[str]] = []
    if scope == "full":
        duplicate_groups = [group for group in hashes.values() if len(group) > 1]
        for group in duplicate_groups[:100]:
            if all(item.startswith(("vendor/", "includes/PHPMailer/")) for item in group):
                continue
            add(findings, "info", "duplicate_content", "Identical tracked files: " + ", ".join(group[:8]))

    severity_counts = collections.Counter(item.severity for item in findings)
    return {
        "mode": "static",
        "scope": scope,
        "generated_at": int(time.time()),
        "tracked_files": len(all_files),
        "scanned_files": len(files),
        "total_bytes": total_bytes,
        "empty_files": empty_files,
        "extensions": dict(extension_counts.most_common()),
        "validated": dict(validated),
        "duplicate_groups": len(duplicate_groups),
        "severity": dict(severity_counts),
        "findings": [asdict(item) for item in findings],
    }


def fetch(url: str, timeout: int = 25) -> tuple[int, dict[str, str], bytes, str]:
    request = urllib.request.Request(
        url,
        headers={
            "User-Agent": "ShopVivaliz-Ecommerce-Excellence-Audit/1.0",
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return response.status, {k.lower(): v for k, v in response.headers.items()}, response.read(), response.geturl()
    except urllib.error.HTTPError as exc:
        return exc.code, {k.lower(): v for k, v in exc.headers.items()}, exc.read(), exc.geturl()


def validate_page(url: str, body: bytes, headers: dict[str, str], findings: list[Finding]) -> None:
    parser = HeadParser()
    text = body.decode("utf-8", errors="replace")
    parser.feed(text)
    path = urllib.parse.urlsplit(url).path or "/"
    if not parser.title.strip():
        add(findings, "blocker", "missing_title", "HTML page has no title", path)
    elif len(parser.title.strip()) > 65:
        add(findings, "warning", "long_title", f"Title has {len(parser.title.strip())} characters", path)
    description = parser.meta.get("description", "").strip()
    if path not in ("/carrinho", "/checkout") and not description:
        add(findings, "warning", "missing_meta_description", "Indexable page has no meta description", path)
    if description and len(description) > 170:
        add(findings, "warning", "long_meta_description", f"Meta description has {len(description)} characters", path)
    canonical = [item.get("href", "") for item in parser.links if item.get("rel", "").lower() == "canonical"]
    if path not in ("/carrinho", "/checkout") and not canonical:
        add(findings, "blocker", "missing_canonical", "Indexable page has no canonical", path)
    if parser.h1 != 1 and path not in ("/carrinho", "/checkout"):
        add(findings, "warning", "h1_count", f"Expected one H1, found {parser.h1}", path)
    for required in ("og:title", "og:description", "og:image"):
        if path not in ("/carrinho", "/checkout") and not parser.meta.get(required):
            add(findings, "warning", "missing_open_graph", f"Missing {required}", path)
    for payload in parser.jsonld:
        if not payload:
            continue
        try:
            json.loads(payload)
        except json.JSONDecodeError as exc:
            add(findings, "blocker", "invalid_jsonld", f"JSON-LD parse error: {exc}", path)
    if path in ("/carrinho", "/checkout"):
        robots = parser.meta.get("robots", "").lower()
        if "noindex" not in robots:
            add(findings, "warning", "transaction_page_indexable", "Cart/checkout should be noindex", path)
    for header in ("content-security-policy", "strict-transport-security", "x-content-type-options"):
        if header not in headers:
            add(findings, "warning", "missing_security_header", f"Missing response header {header}", path)


def catalog_sample_product_path(base_url: str) -> string:
    """Return one currently available public product route from the live catalog API."""
    status, _, body, _ = fetch(base_url.rstrip("/") + "/api/catalog/products.php?limit=1&available=1")
    if status != 200:
        return ""
    try:
        payload = json.loads(body.decode("utf-8", errors="strict"))
    except (UnicodeDecodeError, json.JSONDecodeError):
        return ""

    rows: list[dict[str, Any]] = []
    if isinstance(payload, list):
        rows = [item for item in payload if isinstance(item, dict)]
    elif isinstance(payload, dict):
        for key in ("products", "produtos", "items", "itens", "data"):
            value = payload.get(key)
            if isinstance(value, list):
                rows = [item for item in value if isinstance(item, dict)]
                if rows:
                    break
            elif isinstance(value, dict):
                rows = [item for item in value.values() if isinstance(item, dict)]
                if rows:
                    break
        if not rows and any(key in payload for key in ("slug", "sku", "id")):
            rows = [payload]

    for product in rows:
        slug = str(product.get("slug") or "").strip()
        if slug:
            return "/produto/" + urllib.parse.quote(slug, safe="-")
    return ""


def validate_live(base_url: str) -> dict[str, Any]:
    base_url = base_url.rstrip("/")
    findings: list[Finding] = []
    checked: list[dict[str, Any]] = []
    endpoints = ["/", "/catalogo", "/sobre", "/contato", "/faq", "/blog", "/carrinho", "/checkout"]
    sample_product = catalog_sample_product_path(base_url)
    if sample_product:
        endpoints.append(sample_product)
    else:
        add(findings, "blocker", "catalog_sample_unavailable", "Live catalog API did not provide an available product for product-page validation", "/api/catalog/products.php")

    for endpoint in endpoints:
        url = base_url + endpoint
        status, headers, body, final_url = fetch(url)
        checked.append({"url": url, "status": status, "final_url": final_url, "bytes": len(body)})
        if status != 200:
            add(findings, "blocker", "public_http_status", f"Expected HTTP 200, received {status}", endpoint)
            continue
        validate_page(final_url, body, headers, findings)

    status, _, robots_body, _ = fetch(base_url + "/robots.txt")
    robots_text = robots_body.decode("utf-8", errors="replace")
    if status != 200 or "Sitemap:" not in robots_text or "User-agent:" not in robots_text:
        add(findings, "blocker", "robots_invalid", "robots.txt is unavailable or incomplete", "/robots.txt")

    status, _, sitemap_body, _ = fetch(base_url + "/sitemap.xml")
    sitemap_urls: list[str] = []
    if status != 200:
        add(findings, "blocker", "sitemap_http_status", f"Sitemap returned HTTP {status}", "/sitemap.xml")
    else:
        try:
            root = ET.fromstring(sitemap_body)
            namespace = {"sm": "http://www.sitemaps.org/schemas/sitemap/0.9"}
            sitemap_urls = [node.text.strip() for node in root.findall(".//sm:loc", namespace) if node.text]
            if len(sitemap_urls) < 50:
                add(findings, "warning", "sitemap_small", f"Sitemap contains only {len(sitemap_urls)} URLs", "/sitemap.xml")
            if len(sitemap_urls) != len(set(sitemap_urls)):
                add(findings, "blocker", "sitemap_duplicates", "Sitemap contains duplicate URLs", "/sitemap.xml")
            query_urls = [url for url in sitemap_urls if urllib.parse.urlsplit(url).query]
            if query_urls:
                add(findings, "warning", "sitemap_query_urls", f"Sitemap contains {len(query_urls)} query-string URLs", "/sitemap.xml")
        except ET.ParseError as exc:
            add(findings, "blocker", "sitemap_xml_invalid", f"Sitemap XML parse error: {exc}", "/sitemap.xml")

    status, _, feed_body, _ = fetch(base_url + "/google-merchant-feed.php", timeout=60)
    feed_items = 0
    if status != 200:
        add(findings, "blocker", "merchant_feed_http_status", f"Merchant feed returned HTTP {status}", "/google-merchant-feed.php")
    else:
        try:
            root = ET.fromstring(feed_body)
            items = root.findall("./channel/item")
            feed_items = len(items)
            if feed_items < 20:
                add(findings, "blocker", "merchant_feed_small", f"Merchant feed contains only {feed_items} items", "/google-merchant-feed.php")
            ns = {"g": "http://base.google.com/ns/1.0"}
            ids: set[str] = set()
            links: set[str] = set()
            for index, item in enumerate(items, 1):
                product_id = (item.findtext("g:id", default="", namespaces=ns) or "").strip()
                link = (item.findtext("link") or "").strip()
                title = (item.findtext("title") or "").strip()
                description = (item.findtext("description") or "").strip()
                price = (item.findtext("g:price", default="", namespaces=ns) or "").strip()
                image = (item.findtext("g:image_link", default="", namespaces=ns) or "").strip()
                if not all((product_id, link, title, description, price, image)):
                    add(findings, "blocker", "merchant_required_field", f"Feed item {index} lacks a required field", "/google-merchant-feed.php")
                if product_id in ids:
                    add(findings, "blocker", "merchant_duplicate_id", f"Duplicate Merchant id: {product_id}", "/google-merchant-feed.php")
                if link in links:
                    add(findings, "warning", "merchant_duplicate_link", f"Duplicate Merchant link: {link}", "/google-merchant-feed.php")
                ids.add(product_id)
                links.add(link)
                if len(title) > 150:
                    add(findings, "warning", "merchant_title_long", f"Merchant title exceeds 150 chars: {product_id}", "/google-merchant-feed.php")
                if not re.fullmatch(r"\d+(?:\.\d{2}) BRL", price):
                    add(findings, "blocker", "merchant_price_format", f"Invalid Merchant price for {product_id}: {price}", "/google-merchant-feed.php")
        except ET.ParseError as exc:
            add(findings, "blocker", "merchant_xml_invalid", f"Merchant XML parse error: {exc}", "/google-merchant-feed.php")

    severity_counts = collections.Counter(item.severity for item in findings)
    return {
        "mode": "live",
        "base_url": base_url,
        "generated_at": int(time.time()),
        "checked": checked,
        "sitemap_urls": len(sitemap_urls),
        "merchant_items": feed_items,
        "severity": dict(severity_counts),
        "findings": [asdict(item) for item in findings],
    }


def write_report(report: dict[str, Any], output: pathlib.Path) -> None:
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    markdown = output.with_suffix(".md")
    lines = [
        "# Ecommerce Excellence Audit",
        "",
        f"- Mode: `{report.get('mode')}`",
        f"- Scope: `{report.get('scope', 'n/a')}`",
        f"- Generated: `{report.get('generated_at')}`",
        f"- Scanned files: `{report.get('scanned_files', 'n/a')}`",
        f"- Blockers: `{report.get('severity', {}).get('blocker', 0)}`",
        f"- Warnings: `{report.get('severity', {}).get('warning', 0)}`",
        f"- Informational: `{report.get('severity', {}).get('info', 0)}`",
        "",
        "## Findings",
        "",
    ]
    for item in report.get("findings", []):
        location = f" (`{item.get('path')}`)" if item.get("path") else ""
        lines.append(f"- **{item.get('severity', '').upper()} / {item.get('code')}**{location}: {item.get('message')}")
    if not report.get("findings"):
        lines.append("- No findings.")
    markdown.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--mode", choices=("static", "live"), required=True)
    parser.add_argument("--scope", choices=("full", "changed"), default="full")
    parser.add_argument("--base", default="")
    parser.add_argument("--head", default="HEAD")
    parser.add_argument("--base-url", default="https://shopvivaliz.com.br")
    parser.add_argument("--output", default="artifacts/ecommerce-excellence-audit.json")
    parser.add_argument("--fail-on", choices=("never", "blocker", "warning"), default="blocker")
    args = parser.parse_args()

    if args.mode == "static":
        if args.scope == "changed":
            if not args.base:
                print("--base is required with --scope changed", file=sys.stderr)
                return 2
            report = validate_static(changed_tracked_files(args.base, args.head), scope="changed")
            report["base"] = args.base
            report["head"] = args.head
        else:
            report = validate_static(scope="full")
    else:
        report = validate_live(args.base_url)
    write_report(report, pathlib.Path(args.output))
    print(json.dumps({k: v for k, v in report.items() if k != "findings"}, ensure_ascii=False, indent=2))
    counts = report.get("severity", {})
    if args.fail_on == "blocker" and counts.get("blocker", 0):
        return 1
    if args.fail_on == "warning" and (counts.get("blocker", 0) or counts.get("warning", 0)):
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
