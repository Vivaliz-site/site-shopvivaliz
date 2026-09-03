import { execFile } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { promisify } from 'node:util';
import { chromium } from 'playwright';
import { mapWithConcurrency, resolveAuditConcurrency } from './lib/audit-concurrency.mjs';
import { discoverPublicRoutes } from './lib/public-route-discovery.mjs';
import { reportableResourceFailure } from './lib/public-page-health.mjs';

const execFileAsync = promisify(execFile);
const baseUrl = (process.env.E2E_BASE_URL || 'https://shopvivaliz.com.br').replace(/\/$/, '');
const proxyServer = process.env.E2E_PROXY_SERVER || '';
const outDir = process.env.PLAYWRIGHT_ARTIFACTS_DIR || join(process.cwd(), 'artifacts', 'public-layout-audit');
const mandatoryRoutes = ['/', '/catalogo/', '/carrinho/', '/contato/', '/faq/', '/politica-privacidade/', '/politica-devolucoes/', '/politica-entrega/', '/termos/', '/sobre/', '/blog/', '/avaliacoes.php'];
const explicitRoutes = (process.env.PUBLIC_AUDIT_ROUTES || '').split(',').map((value) => value.trim()).filter(Boolean);
const auditConcurrency = resolveAuditConcurrency();

const auditFetch = async (url, options = {}) => {
  if (!proxyServer) return fetch(url, options);
  const curlProxy = proxyServer.replace(/^socks5:\/\//, 'socks5h://');
  try {
    const { stdout } = await execFileAsync('curl', [
      '-fsSL', '--max-time', '30', '--proxy', curlProxy, String(url),
    ], { maxBuffer: 16 * 1024 * 1024 });
    return { ok: true, status: 200, text: async () => stdout };
  } catch (error) {
    return {
      ok: false,
      status: Number(error?.code) || 0,
      text: async () => '',
    };
  }
};

const discoveredRoutes = explicitRoutes.length ? [] : await discoverPublicRoutes({ baseUrl, fetchImpl: auditFetch });
const routes = [...new Set([...(explicitRoutes.length ? explicitRoutes : discoveredRoutes), ...mandatoryRoutes])].sort((a, b) => a.localeCompare(b));
const profiles = [
  { name: 'mobile', width: 390, height: 844, isMobile: true },
  { name: 'desktop', width: 1440, height: 1000, isMobile: false },
];

if (!routes.length) throw new Error('No public routes discovered for audit');
console.log(`Public layout audit: ${routes.length} routes x ${profiles.length} profiles`);
console.log(`Public layout audit concurrency: ${Math.min(auditConcurrency, routes.length)}`);
mkdirSync(outDir, { recursive: true });
const browser = await chromium.launch({
  headless: true,
  proxy: proxyServer ? { server: proxyServer } : undefined,
});
const failures = [];
const results = [];

for (const profile of profiles) {
  const context = await browser.newContext({
    viewport: { width: profile.width, height: profile.height },
    deviceScaleFactor: 1,
    isMobile: profile.isMobile,
    hasTouch: profile.isMobile,
  });

  const profileResults = await mapWithConcurrency(routes, auditConcurrency, async (route) => {
    const page = await context.newPage();
    const pageErrors = [];
    const resourceFailures = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
    page.on('response', (response) => {
      const request = response.request();
      if (reportableResourceFailure({
        url: response.url(),
        status: response.status(),
        resourceType: request.resourceType(),
        baseUrl,
      })) {
        resourceFailures.push(`${response.status()} ${request.resourceType()} ${response.url()}`);
      }
    });

    const url = `${baseUrl}${route.startsWith('/') ? route : `/${route}`}`;
    const slug = route === '/' ? 'home' : route.replace(/^\/+|\/+$/g, '').replace(/[^a-z0-9]+/gi, '-').slice(0, 120);
    try {
      const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
      const status = response?.status() ?? 0;
      await page.waitForTimeout(1000);
      await page.screenshot({ path: join(outDir, `${profile.name}-${slug || 'page'}.png`), fullPage: true });
      const metrics = await page.evaluate(({ isMobile }) => {
        const doc = document.documentElement;
        const body = document.body;
        const footer = document.querySelector('footer');
        const navs = [...document.querySelectorAll('.sv-mobile-nav-bar,.sv-mobile-bottom-nav')];
        const fixedUi = [...document.querySelectorAll('.sv-support-dock,#sv-liz-launcher,.sv-mobile-nav-bar,.sv-mobile-bottom-nav')]
          .filter((el) => {
            const style = getComputedStyle(el);
            return style.display !== 'none' && style.visibility !== 'hidden' && Number.parseFloat(style.opacity || '1') > 0.05 && el.getClientRects().length > 0;
          })
          .map((el) => {
            const r = el.getBoundingClientRect();
            return { selector: el.id ? `#${el.id}` : `.${el.classList[0]}`, left: r.left, right: r.right, top: r.top, bottom: r.bottom };
          });
        const footerRect = footer?.getBoundingClientRect() || null;
        const brokenImages = [...document.images]
          .filter((img) => img.complete && img.naturalWidth === 0)
          .map((img) => img.currentSrc || img.src || img.alt || 'unknown')
          .slice(0, 10);
        return {
          viewportWidth: innerWidth,
          viewportHeight: innerHeight,
          scrollWidth: Math.max(doc.scrollWidth, body?.scrollWidth || 0),
          footerCount: document.querySelectorAll('footer').length,
          footerRect: footerRect ? { left: footerRect.left, right: footerRect.right, width: footerRect.width } : null,
          navCount: navs.length,
          lizCount: document.querySelectorAll('#sv-liz-launcher').length,
          supportDockCount: document.querySelectorAll('.sv-support-dock').length,
          fixedUi,
          bodyPaddingBottom: parseFloat(getComputedStyle(body).paddingBottom) || 0,
          brokenImages,
          isMobile,
        };
      }, { isMobile: profile.isMobile });

      const localFailures = [];
      if (status < 200 || status >= 400) localFailures.push(`HTTP ${status}`);
      if (metrics.scrollWidth > metrics.viewportWidth + 2) localFailures.push(`horizontal overflow ${metrics.scrollWidth}px > ${metrics.viewportWidth}px`);
      if (metrics.footerCount !== 1) localFailures.push(`expected exactly one footer, found ${metrics.footerCount}`);
      if (metrics.lizCount > 1) localFailures.push(`duplicate Liz launcher (${metrics.lizCount})`);
      if (metrics.supportDockCount > 1) localFailures.push(`duplicate support dock (${metrics.supportDockCount})`);
      if (metrics.brokenImages.length) localFailures.push(`broken images: ${metrics.brokenImages.join(', ')}`);
      if (pageErrors.length) localFailures.push(`page errors: ${pageErrors.slice(0, 5).join(' | ')}`);
      if (resourceFailures.length) localFailures.push(`failed core resources: ${resourceFailures.slice(0, 10).join(' | ')}`);
      if (metrics.footerRect && (metrics.footerRect.left < -2 || metrics.footerRect.right > metrics.viewportWidth + 2)) localFailures.push('footer extends outside viewport');
      for (const item of metrics.fixedUi) {
        if (item.left < -2 || item.right > metrics.viewportWidth + 2 || item.top < -2 || item.bottom > metrics.viewportHeight + 2) {
          localFailures.push(`${item.selector} outside viewport`);
        }
      }
      if (profile.isMobile) {
        if (metrics.navCount !== 1) localFailures.push(`expected one mobile navigation, found ${metrics.navCount}`);
        if (metrics.navCount === 1 && metrics.bodyPaddingBottom < 70) localFailures.push(`mobile body bottom padding too small (${metrics.bodyPaddingBottom}px)`);
      }

      return {
        result: { profile: profile.name, route, url: page.url(), status, metrics, pageErrors, resourceFailures, failures: localFailures },
        failures: localFailures.map((message) => `${profile.name} ${route}: ${message}`),
      };
    } catch (error) {
      return {
        result: { profile: profile.name, route, url, pageErrors, resourceFailures, failures: [error.message] },
        failures: [`${profile.name} ${route}: ${error.message}`],
      };
    } finally {
      await page.close();
    }
  });

  for (const item of profileResults) {
    results.push(item.result);
    failures.push(...item.failures);
  }
  await context.close();
}

await browser.close();
console.log(JSON.stringify({ ok: failures.length === 0, baseUrl, routeCount: routes.length, outDir, failures, results }, null, 2));
if (failures.length) process.exit(1);
