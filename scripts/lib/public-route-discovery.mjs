const ASSET_RE = /\.(?:avif|bmp|css|gif|ico|jpe?g|js|json|map|mp3|mp4|pdf|png|svg|txt|webm|webp|woff2?|xml)(?:$|\?)/i;
const PRIVATE_PREFIXES = ['/admin', '/api/', '/includes/', '/config/', '/vendor/', '/node_modules/', '/.git/'];

export function extractLocs(xml = '') {
  return [...String(xml).matchAll(/<loc>\s*([^<]+?)\s*<\/loc>/gi)].map((match) => decodeEntities(match[1].trim()));
}

function decodeEntities(value) {
  return value.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'");
}

export function normalizeAuditRoute(candidate, baseUrl) {
  let url;
  try { url = new URL(candidate, baseUrl); } catch { return null; }
  const base = new URL(baseUrl);
  if (url.origin !== base.origin) return null;
  const path = url.pathname || '/';
  if (ASSET_RE.test(path)) return null;
  if (PRIVATE_PREFIXES.some((prefix) => path === prefix.replace(/\/$/, '') || path.startsWith(prefix))) return null;
  return `${path}${url.search}`;
}

export async function discoverPublicRoutes({ baseUrl, fetchImpl = fetch, seedSitemaps = ['/sitemap.xml'] } = {}) {
  if (!baseUrl) throw new Error('baseUrl is required');
  const base = new URL(baseUrl);
  const queue = seedSitemaps.map((entry) => new URL(entry, base).href);
  const seenSitemaps = new Set();
  const routes = new Set();
  while (queue.length) {
    const sitemapUrl = queue.shift();
    if (seenSitemaps.has(sitemapUrl)) continue;
    seenSitemaps.add(sitemapUrl);
    let response;
    try { response = await fetchImpl(sitemapUrl, { redirect: 'follow' }); } catch { continue; }
    if (!response?.ok) continue;
    const xml = await response.text();
    for (const loc of extractLocs(xml)) {
      let url;
      try { url = new URL(loc, base); } catch { continue; }
      if (url.origin !== base.origin) continue;
      if (/\.xml$/i.test(url.pathname)) {
        if (!seenSitemaps.has(url.href)) queue.push(url.href);
        continue;
      }
      const route = normalizeAuditRoute(url.href, base.href);
      if (route) routes.add(route);
    }
  }
  return [...routes].sort((a, b) => a.localeCompare(b));
}
