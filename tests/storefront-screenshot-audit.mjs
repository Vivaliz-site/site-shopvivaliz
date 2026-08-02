import { chromium } from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';

const baseURL = process.env.BASE_URL || 'http://127.0.0.1:8090';
const outputDir = process.env.OUTPUT_DIR || 'test-results/storefront-audit';
await fs.mkdir(outputDir, { recursive: true });

const profiles = [
  { name: 'desktop', viewport: { width: 1440, height: 1000 }, throttle: null },
  {
    name: 'fast-3g',
    viewport: { width: 390, height: 844 },
    throttle: { latency: 150, downloadThroughput: 1_600_000 / 8, uploadThroughput: 768_000 / 8 }
  },
  {
    name: 'slow-4g',
    viewport: { width: 390, height: 844 },
    throttle: { latency: 100, downloadThroughput: 4_000_000 / 8, uploadThroughput: 3_000_000 / 8 }
  }
];

const routes = [
  { name: 'home', url: '/', expected: [200] },
  { name: 'catalogo', url: '/catalogo', expected: [200] },
  { name: 'produto', url: '/produto/chave-teste-140mm-100500v', expected: [200, 404] },
  { name: 'carrinho', url: '/carrinho', expected: [200] },
  { name: 'checkout', url: '/checkout', expected: [200] },
  { name: '404', url: '/rota-inexistente-auditoria-404', expected: [404] }
];

const browser = await chromium.launch({ headless: true });
const report = { generated_at: new Date().toISOString(), base_url: baseURL, pages: [], memory: null };
let failed = false;

async function installMetrics(page) {
  await page.addInitScript(() => {
    window.__svCls = 0;
    window.__svLongestTask = 0;
    try {
      new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
          if (!entry.hadRecentInput) window.__svCls += entry.value;
        }
      }).observe({ type: 'layout-shift', buffered: true });
      new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
          window.__svLongestTask = Math.max(window.__svLongestTask, entry.duration || 0);
        }
      }).observe({ type: 'longtask', buffered: true });
    } catch (_) {}
  });
}

async function scrollPage(page) {
  await page.evaluate(async () => {
    const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const maximum = Math.max(document.body.scrollHeight, document.documentElement.scrollHeight);
    for (let y = 0; y < maximum; y += Math.max(320, Math.floor(window.innerHeight * 0.75))) {
      window.scrollTo(0, y);
      await pause(90);
    }
    window.scrollTo(0, 0);
  });
}

for (const profile of profiles) {
  const context = await browser.newContext({
    viewport: profile.viewport,
    locale: 'pt-BR',
    colorScheme: 'light',
    reducedMotion: 'reduce'
  });

  for (const route of routes) {
    const page = await context.newPage();
    await installMetrics(page);
    const consoleErrors = [];
    const networkErrors = [];

    page.on('console', (message) => {
      if (message.type() === 'error') consoleErrors.push(message.text());
    });
    page.on('response', (response) => {
      const url = new URL(response.url());
      const firstParty = url.origin === new URL(baseURL).origin;
      if (firstParty && response.status() >= 400) {
        const isExpectedDocument = response.request().isNavigationRequest()
          && route.expected.includes(response.status());
        if (!isExpectedDocument) networkErrors.push(`${response.status()} ${url.pathname}`);
      }
    });
    page.on('requestfailed', (request) => {
      const url = new URL(request.url());
      if (url.origin === new URL(baseURL).origin) {
        networkErrors.push(`FAILED ${url.pathname}: ${request.failure()?.errorText || 'unknown'}`);
      }
    });

    if (profile.throttle) {
      const client = await context.newCDPSession(page);
      await client.send('Network.enable');
      await client.send('Network.emulateNetworkConditions', {
        offline: false,
        ...profile.throttle,
        connectionType: 'cellular3g'
      });
    }

    const response = await page.goto(baseURL + route.url, { waitUntil: 'domcontentloaded', timeout: 45_000 });
    const status = response?.status() || 0;
    await page.waitForTimeout(1200);
    await scrollPage(page);
    await page.waitForTimeout(300);

    const metrics = await page.evaluate(() => ({
      cls: Number(window.__svCls || 0),
      longestTaskMs: Number(window.__svLongestTask || 0),
      scrollWidth: Math.max(document.body.scrollWidth, document.documentElement.scrollWidth),
      clientWidth: document.documentElement.clientWidth,
      title: document.title
    }));
    const horizontalOverflow = Math.max(0, metrics.scrollWidth - metrics.clientWidth);
    const screenshot = path.join(outputDir, `${profile.name}-${route.name}.png`);
    await page.screenshot({ path: screenshot, fullPage: true });

    const record = {
      profile: profile.name,
      route: route.url,
      status,
      title: metrics.title,
      cls: metrics.cls,
      longest_task_ms: metrics.longestTaskMs,
      horizontal_overflow_px: horizontalOverflow,
      console_errors: consoleErrors,
      network_errors: networkErrors,
      screenshot
    };
    report.pages.push(record);

    if (!route.expected.includes(status) || horizontalOverflow > 4 || metrics.cls > 0.5 || consoleErrors.length || networkErrors.length) {
      failed = true;
    }
    await page.close();
  }
  await context.close();
}

// Navegacao repetida para detectar crescimento grosseiro de heap em 10 paginas.
const memoryContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const memoryPage = await memoryContext.newPage();
const memoryClient = await memoryContext.newCDPSession(memoryPage);
await memoryClient.send('Performance.enable');
async function heapSize() {
  const result = await memoryClient.send('Performance.getMetrics');
  return Number(result.metrics.find((metric) => metric.name === 'JSHeapUsedSize')?.value || 0);
}
await memoryPage.goto(baseURL + '/', { waitUntil: 'domcontentloaded' });
const heapBefore = await heapSize();
for (let index = 0; index < 10; index += 1) {
  const route = routes[index % routes.length];
  await memoryPage.goto(baseURL + route.url, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await memoryPage.waitForTimeout(150);
}
await memoryClient.send('HeapProfiler.collectGarbage').catch(() => {});
const heapAfter = await heapSize();
const heapGrowth = heapAfter - heapBefore;
report.memory = {
  heap_before_bytes: heapBefore,
  heap_after_bytes: heapAfter,
  heap_growth_bytes: heapGrowth,
  growth_ratio: heapBefore > 0 ? heapAfter / heapBefore : null
};
if (heapGrowth > 64 * 1024 * 1024 && heapBefore > 0 && heapAfter / heapBefore > 2.5) failed = true;
await memoryContext.close();

await fs.writeFile(path.join(outputDir, 'report.json'), JSON.stringify(report, null, 2));
await browser.close();

console.log(JSON.stringify(report, null, 2));
if (failed) process.exit(1);
