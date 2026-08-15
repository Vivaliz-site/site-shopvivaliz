#!/usr/bin/env python3
"""Audit public ShopVivaliz URL integrity without changing production data.

Checks the same failure classes that commonly appear in Site Audit tools:
- sitemap URLs that redirect, time out, or return 4xx/5xx;
- internal links that redirect or break;
- canonicals that redirect/break;
- sitemap pages with no incoming internal link.

The script is read-only. It never calls authenticated endpoints and never
writes price, stock, catalog, order, customer, or integration data.
"""
from __future__ import annotations

import argparse
import concurrent.futures
import html.parser
import json
import socket
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
from collections import Counter, defaultdict, deque
from dataclasses import asdict, dataclass
from pathlib import Path
from typing import Iterable

DEFAULT_BASE = "https://shopvivaliz.com.br"
USER_AGENT = "ShopVivaliz-SEO-Integrity-Audit/1.1 (+https://shopvivaliz.com.br)"
HTML_TYPES = ("text/html", "application/xhtml+xml")
MAX_DISCOVERY_PAGES = 50


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: ANN001
        return None


NO_REDIRECT_OPENER = urllib.request.build_opener(NoRedirect)


@dataclass(frozen=True)
class Probe:
    url: str
    status: int
    elapsed_ms: int
    content_type: str = ""
    location: str = ""
    error: str = ""
    body: str = ""

    @property
    def timed_out(self) -> bool:
        text = self.error.lower()
        return "timed out" in text or "timeout" in text

    @property
    def is_redirect(self) -> bool:
        return 300 <= self.status < 400

    @property
    def is_broken(self) -> bool:
        return self.status >= 400 or self.status == 0


class PageParser(html.parser.HTMLParser):
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.links: set[str] = set()
        self.canonical = ""

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        values = {str(k).lower(): (v or "") for k, v in attrs}
        if tag.lower() == "a":
            href = values.get("href", "").strip()
            if href:
                self.links.add(href)
        elif tag.lower() == "link":
            rel = values.get("rel", "").lower().split()
            if "canonical" in rel:
                self.canonical = values.get("href", "").strip()


def canonicalize(base: str, raw: str) -> str | None:
    raw = raw.strip()
    if not raw or raw.startswith(("#", "mailto:", "tel:", "javascript:", "data:")):
        return None
    url = urllib.parse.urljoin(base + "/", raw)
    parts = urllib.parse.urlsplit(url)
    base_parts = urllib.parse.urlsplit(base)
    if parts.scheme not in {"http", "https"} or parts.netloc.lower() != base_parts.netloc.lower():
        return None
    path = parts.path or "/"
    return urllib.parse.urlunsplit((base_parts.scheme, base_parts.netloc, path, parts.query, ""))


def is_discovery_page(url: str, base: str) -> bool:
    """Return True for bounded pagination pages that expose otherwise hidden links."""
    parts = urllib.parse.urlsplit(url)
    base_parts = urllib.parse.urlsplit(base)
    if parts.netloc.lower() != base_parts.netloc.lower():
        return False
    if parts.path not in {"/catalogo/", "/blog/"}:
        return False
    params = urllib.parse.parse_qs(parts.query, keep_blank_values=True)
    if set(params) != {"pagina"}:
        return False
    values = params.get("pagina") or []
    return len(values) == 1 and values[0].isdigit() and int(values[0]) >= 2


def probe(url: str, timeout: float, keep_body: bool = True) -> Probe:
    request = urllib.request.Request(
        url,
        method="GET",
        headers={"User-Agent": USER_AGENT, "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"},
    )
    started = time.monotonic()
    try:
        with NO_REDIRECT_OPENER.open(request, timeout=timeout) as response:
            raw = response.read(1_500_000 if keep_body else 1)
            content_type = response.headers.get_content_type() or ""
            body = raw.decode(response.headers.get_content_charset() or "utf-8", errors="replace") if keep_body else ""
            return Probe(
                url=url,
                status=int(response.status),
                elapsed_ms=int((time.monotonic() - started) * 1000),
                content_type=content_type,
                location=response.headers.get("Location", ""),
                body=body,
            )
    except urllib.error.HTTPError as exc:
        raw = b""
        try:
            raw = exc.read(1_500_000 if keep_body else 1)
        except Exception:
            raw = b""
        content_type = exc.headers.get_content_type() if exc.headers else ""
        charset = exc.headers.get_content_charset() if exc.headers else None
        body = raw.decode(charset or "utf-8", errors="replace") if keep_body else ""
        return Probe(
            url=url,
            status=int(exc.code),
            elapsed_ms=int((time.monotonic() - started) * 1000),
            content_type=content_type or "",
            location=exc.headers.get("Location", "") if exc.headers else "",
            error=str(exc.reason or ""),
            body=body,
        )
    except (TimeoutError, socket.timeout, urllib.error.URLError, OSError) as exc:
        return Probe(
            url=url,
            status=0,
            elapsed_ms=int((time.monotonic() - started) * 1000),
            error=str(getattr(exc, "reason", exc)),
        )


def parse_sitemap(xml_text: str, base: str) -> list[str]:
    root = ET.fromstring(xml_text)
    urls: list[str] = []
    for element in root.iter():
        if element.tag.endswith("loc") and element.text:
            value = canonicalize(base, element.text.strip())
            if value and value not in urls:
                urls.append(value)
    return urls


def parallel_probe(urls: Iterable[str], timeout: float, workers: int, keep_body: bool) -> dict[str, Probe]:
    unique = list(dict.fromkeys(urls))
    results: dict[str, Probe] = {}
    with concurrent.futures.ThreadPoolExecutor(max_workers=workers) as pool:
        future_map = {pool.submit(probe, url, timeout, keep_body): url for url in unique}
        for future in concurrent.futures.as_completed(future_map):
            url = future_map[future]
            try:
                results[url] = future.result()
            except Exception as exc:
                results[url] = Probe(url=url, status=0, elapsed_ms=0, error=f"worker_error: {exc}")
    return results


def collect_page_links(
    source: str,
    result: Probe,
    base: str,
    incoming: Counter[str],
    link_sources: dict[str, set[str]],
    canonical_sources: dict[str, set[str]],
) -> None:
    if result.status != 200 or result.content_type not in HTML_TYPES:
        return
    page = PageParser()
    try:
        page.feed(result.body)
    except Exception:
        return
    for href in page.links:
        target = canonicalize(base, href)
        if target:
            incoming[target] += 1
            link_sources[target].add(source)
    canonical = canonicalize(base, page.canonical) if page.canonical else None
    if canonical:
        canonical_sources[canonical].add(source)


def crawl_discovery_pages(
    base: str,
    timeout: float,
    incoming: Counter[str],
    link_sources: dict[str, set[str]],
    canonical_sources: dict[str, set[str]],
) -> dict[str, Probe]:
    """Follow catalog/blog pagination so orphan detection sees the full link graph."""
    queue: deque[str] = deque(
        sorted(url for url in link_sources if is_discovery_page(url, base))
    )
    seen: set[str] = set()
    results: dict[str, Probe] = {}

    while queue and len(seen) < MAX_DISCOVERY_PAGES:
        url = queue.popleft()
        if url in seen:
            continue
        seen.add(url)
        result = probe(url, timeout, keep_body=True)
        results[url] = result
        collect_page_links(url, result, base, incoming, link_sources, canonical_sources)
        for target in sorted(link_sources):
            if target not in seen and target not in queue and is_discovery_page(target, base):
                queue.append(target)

    return results


def markdown_report(summary: dict, issues: dict[str, list[dict]]) -> str:
    lines = [
        "# ShopVivaliz SEO integrity audit",
        "",
        f"- Base: `{summary['base']}`",
        f"- Sitemap URLs: **{summary['sitemap_urls']}**",
        f"- Discovery pagination pages crawled: **{summary['discovery_pages']}**",
        f"- Internal targets checked: **{summary['internal_targets']}**",
        f"- Sitemap timeouts: **{summary['sitemap_timeouts']}**",
        f"- Sitemap 4XX/5XX: **{summary['sitemap_broken']}**",
        f"- Sitemap redirects: **{summary['sitemap_redirects']}**",
        f"- Broken internal targets: **{summary['broken_internal']}**",
        f"- Redirecting internal targets: **{summary['redirecting_internal']}**",
        f"- Broken/redirecting canonicals: **{summary['canonical_issues']}**",
        f"- Orphan sitemap pages: **{summary['orphans']}**",
        "",
    ]
    for section, rows in issues.items():
        lines.append(f"## {section}")
        if not rows:
            lines.extend(["", "None.", ""])
            continue
        lines.append("")
        for row in rows[:500]:
            details = ", ".join(f"{k}={v}" for k, v in row.items())
            lines.append(f"- {details}")
        lines.append("")
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default=DEFAULT_BASE)
    parser.add_argument("--timeout", type=float, default=12.0)
    parser.add_argument("--workers", type=int, default=6)
    parser.add_argument("--json", default="seo-integrity-audit.json")
    parser.add_argument("--markdown", default="seo-integrity-audit.md")
    parser.add_argument("--fail-on-issues", action="store_true")
    args = parser.parse_args()

    base = args.base.rstrip("/")
    workers = max(1, min(args.workers, 10))
    sitemap_url = base + "/sitemap.xml"
    sitemap_probe = probe(sitemap_url, args.timeout, keep_body=True)
    if sitemap_probe.status != 200:
        print(f"ERROR sitemap={sitemap_url} status={sitemap_probe.status} error={sitemap_probe.error}", file=sys.stderr)
        return 2

    try:
        sitemap_urls = parse_sitemap(sitemap_probe.body, base)
    except Exception as exc:
        print(f"ERROR invalid sitemap: {exc}", file=sys.stderr)
        return 2

    sitemap_results = parallel_probe(sitemap_urls, args.timeout, workers, keep_body=True)
    incoming: Counter[str] = Counter()
    link_sources: dict[str, set[str]] = defaultdict(set)
    canonical_sources: dict[str, set[str]] = defaultdict(set)

    for source, result in sitemap_results.items():
        collect_page_links(source, result, base, incoming, link_sources, canonical_sources)

    discovery_results = crawl_discovery_pages(
        base,
        args.timeout,
        incoming,
        link_sources,
        canonical_sources,
    )

    internal_targets = sorted(link_sources)
    target_results = parallel_probe(internal_targets, args.timeout, workers, keep_body=False)
    canonical_results = parallel_probe(sorted(canonical_sources), args.timeout, workers, keep_body=False)

    issues: dict[str, list[dict]] = {
        "Sitemap timeouts": [],
        "Sitemap 4XX/5XX": [],
        "Sitemap redirects": [],
        "Broken internal links": [],
        "Redirecting internal links": [],
        "Canonical issues": [],
        "Orphan sitemap pages": [],
    }

    for url in sitemap_urls:
        result = sitemap_results[url]
        if result.timed_out:
            issues["Sitemap timeouts"].append({"url": url, "elapsed_ms": result.elapsed_ms, "error": result.error})
        if result.is_broken:
            issues["Sitemap 4XX/5XX"].append({"url": url, "status": result.status, "error": result.error})
        elif result.is_redirect:
            issues["Sitemap redirects"].append({"url": url, "status": result.status, "location": result.location})

    for target, sources in sorted(link_sources.items()):
        result = target_results[target]
        source_list = sorted(sources)
        if result.is_broken:
            issues["Broken internal links"].append({
                "target": target,
                "status": result.status,
                "sources": source_list[:8],
                "source_count": len(source_list),
                "error": result.error,
            })
        elif result.is_redirect:
            issues["Redirecting internal links"].append({
                "target": target,
                "status": result.status,
                "location": result.location,
                "sources": source_list[:8],
                "source_count": len(source_list),
            })

    for canonical, sources in sorted(canonical_sources.items()):
        result = canonical_results[canonical]
        if result.is_broken or result.is_redirect:
            issues["Canonical issues"].append({
                "canonical": canonical,
                "status": result.status,
                "location": result.location,
                "sources": sorted(sources)[:8],
                "source_count": len(sources),
                "error": result.error,
            })

    root_url = base + "/"
    for url in sitemap_urls:
        if url == root_url:
            continue
        if incoming[url] == 0:
            issues["Orphan sitemap pages"].append({"url": url})

    summary = {
        "base": base,
        "sitemap_urls": len(sitemap_urls),
        "discovery_pages": len(discovery_results),
        "internal_targets": len(internal_targets),
        "sitemap_timeouts": len(issues["Sitemap timeouts"]),
        "sitemap_broken": len(issues["Sitemap 4XX/5XX"]),
        "sitemap_redirects": len(issues["Sitemap redirects"]),
        "broken_internal": len(issues["Broken internal links"]),
        "redirecting_internal": len(issues["Redirecting internal links"]),
        "canonical_issues": len(issues["Canonical issues"]),
        "orphans": len(issues["Orphan sitemap pages"]),
        "status_counts": dict(sorted(Counter(p.status for p in sitemap_results.values()).items())),
    }
    payload = {
        "summary": summary,
        "issues": issues,
        "sitemap_results": {url: asdict(result) | {"body": ""} for url, result in sitemap_results.items()},
        "discovery_results": {url: asdict(result) | {"body": ""} for url, result in discovery_results.items()},
    }
    Path(args.json).write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    Path(args.markdown).write_text(markdown_report(summary, issues), encoding="utf-8")
    print(json.dumps(summary, ensure_ascii=False, indent=2))

    issue_total = sum([
        summary["sitemap_timeouts"],
        summary["sitemap_broken"],
        summary["sitemap_redirects"],
        summary["broken_internal"],
        summary["redirecting_internal"],
        summary["canonical_issues"],
        summary["orphans"],
    ])
    if args.fail_on_issues and issue_total > 0:
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())