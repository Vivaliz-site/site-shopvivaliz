import { chromium } from 'playwright';
import fs from 'node:fs/promises';
import path from 'node:path';

const baseURL = process.env.BASE_URL || 'http://127.0.0.1:8090';
const baseOrigin = new URL(baseURL).origin;
const outputDir = process.env.OUTPUT_DIR || 'test-results/storefront-audit';
const placeholderImage = await fs.readFile(path.join(process.cwd(), 'images/product-placeholder.svg'));
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

async function installDeterministicNetwork(page) {
  await page.route('**/*', async (route) => {
    const request = route.request();
    let url;
    try {
      url = new URL(request.url());
    } catch (error) {
      await route.continue();
      return;
    }

    if (url.origin === baseOrigin || ['data:', 'blob:', 'about:'].includes(url.protocol)) {
      await route.continue();
      return;
    }

    // A auditoria isolada mede a aplicação e não a disponibilidade de Google
    // Fonts, pixels, gateways ou CDNs. Recursos externos recebem respostas
    // determinísticas para que Fast 3G não bloqueie document.fonts.ready.
    const type = request.resourceType();
    if (type === 'stylesheet') {
      await route.fulfill({ status: 200, contentType: 'text/css; charset=utf-8', body: '/* external stylesheet disabled in CI */' });
      return;
    }
    if (type === 'script') {
      await route.fulfill({ status: 200, contentType: 'application/javascript; charset=utf-8', body: '/* external script disabled in CI */' });
      return;
    }
    if (type === 'image') {
      await route.fulfill({ status: 200, contentType: 'image/svg+xml', body: placeholderImage });
      return;
    }
    if (type === 'font') {
      await route.fulfill({ status: 204, body: '' });
      return;
    }
    await route.fulfill({ status: 204, body: '' });
  });
}

async function installMetrics(page) {
  await page.addInitScript(() => {
    window.__svCls = 0;
    window.__svLongestTask = 0;
    window.__svLayoutShiftSources = [];
    try {
      new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
          if (entry.hadRecentInput) continue;
          window.__svCls += entry.value;
          const sources = Array.from(entry.sources || []).map((source) => {
            const node = source.node;
            if (!(node instanceof Element)) return 'unknown';
            const id = node.id ? `#${node.id}` : '';
            const classes = node.classList && node.classList.length
              ? `.${Array.from(node.classList).slice(0, 3).join('.')}`
              : '';
            return `${node.tagName.toLowerCase()}${id}${classes}`;
          });
          window.__svLayoutShiftSources.push({ value: entry.value, sources });
        }
      }).observe({ type: 'layout-shift', buffered: true });
      new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
          window.__svLongestTask = Math.max(window.__svLongestTask, entry.duration || 0);
        }
      }).observe({ type: 'longtask', buffered: true });
    } catch (error) {
      window.__svMetricSetupError = String(error && error.message ? error.message : error);
    }
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

async function collectInitialMetrics(page) {
  return page.evaluate(() => {
    const viewportWidth = document.documentElement.clientWidth;
    const all = Array.from(document.querySelectorAll('body *'));
    const overflowElements = all.map((node) => {
      const rect = node.getBoundingClientRect();
      const style = window.getComputedStyle(node);
      const id = node.id ? `#${node.id}` : '';
      const classes = node.classList && node.classList.length
        ? `.${Array.from(node.classList).slice(0, 4).join('.')}`
        : '';
      return {
        selector: `${node.tagName.toLowerCase()}${id}${classes}`,
        left: Math.round(rect.left * 10) / 10,
        right: Math.round(rect.right * 10) / 10,
        width: Math.round(rect.width * 10) / 10,
        scroll_width: node instanceof HTMLElement ? node.scrollWidth : 0,
        position: style.position,
        transform: style.transform,
        overflow_x: style.overflowX
      };
    }).filter((item) => item.right > viewportWidth + 4 || item.left < -4 || item.scroll_width > viewportWidth + 4)
      .sort((a, b) => Math.max(b.right - viewportWidth, b.scroll_width - viewportWidth) - Math.max(a.right - viewportWidth, a.scroll_width - viewportWidth))
      .slice(0, 20);

    return {
      cls: Number(window.__svCls || 0),
      longestTaskMs: Number(window.__svLongestTask || 0),
      layoutShiftSources: Array.from(window.__svLayoutShiftSources || []).sort((a, b) => b.value - a.value).slice(0, 12),
      metricSetupError: String(window.__svMetricSetupError || ''),
      scrollWidth: Math.max(document.body.scrollWidth, document.documentElement.scrollWidth),
      clientWidth: viewportWidth,
      title: document.title,
      overflowElements
    };
  });
}

async function saveScreenshot(page, screenshotPath) {
  // CDP não espera indefinidamente por document.fonts.ready, ao contrário de
  // page.screenshot. A captura continua sendo full-page e pixel a pixel.
  const client = await page.context().newCDPSession(page);
  await client.send('Page.enable');
  const layout = await client.send('Page.getLayoutMetrics');
  const cssSize = layout.cssContentSize || { x: 0, y: 0, width: 1440, height: 1200 };
  const clip = {
    x: Math.max(0, Math.floor(cssSize.x || 0)),
    y: Math.max(0, Math.floor(cssSize.y || 0)),
    width: Math.max(1, Math.min(2400, Math.ceil(cssSize.width || 1440))),
    height: Math.max(1, Math.min(20000, Math.ceil(cssSize.height || 1200))),
    scale: 1
  };
  const shot = await client.send('Page.captureScreenshot', {
    format: 'png',
    fromSurface: true,
    captureBeyondViewport: true,
    clip
  });
  await fs.writeFile(screenshotPath, Buffer.from(shot.data, 'base64'));
  await client.detach();
  return 'cdp-full-page';
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
    page.setDefaultTimeout(15_000);
    await installDeterministicNetwork(page);
    await installMetrics(page);
    const rawConsoleErrors = [];
    const networkErrors = [];

    page.on('console', (message) => {
      if (message.type() === 'error') {
        rawConsoleErrors.push({ text: message.text(), url: message.location().url || '' });
      }
    });
    page.on('response', (response) => {
      const url = new URL(response.url());
      const firstParty = url.origin === baseOrigin;
      if (firstParty && response.status() >= 400) {
        const isExpectedDocument = response.request().isNavigationRequest()
          && route.expected.includes(response.status());
        if (!isExpectedDocument) networkErrors.push(`${response.status()} ${url.pathname}`);
      }
    });
    page.on('requestfailed', (request) => {
      const url = new URL(request.url());
      if (url.origin === baseOrigin) {
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
      await client.detach();
    }

    const response = await page.goto(baseURL + route.url, { waitUntil: 'domcontentloaded', timeout: 45_000 });
    const status = response?.status() || 0;
    await page.waitForTimeout(1800);

    // CLS é medido apenas na janela inicial. Rolar programaticamente não
    // representa interação humana e, sem esta separação, gera falsos shifts.
    const metrics = await collectInitialMetrics(page);
    const horizontalOverflow = Math.max(0, metrics.scrollWidth - metrics.clientWidth);

    await scrollPage(page);
    await page.waitForTimeout(300);

    const expectedErrorDocument = route.expected.some((expectedStatus) => expectedStatus >= 400)
      && route.expected.includes(status);
    const consoleErrors = rawConsoleErrors.filter((entry) => {
      const genericResourceError = /^Failed to load resource: the server responded with a status of \d+/i.test(entry.text);
      const sameDocument = entry.url === '' || entry.url === baseURL + route.url;
      return !(expectedErrorDocument && genericResourceError && sameDocument);
    }).map((entry) => entry.text);

    const screenshot = path.join(outputDir, `${profile.name}-${route.name}.png`);
    const screenshotMode = await saveScreenshot(page, screenshot);

    const record = {
      profile: profile.name,
      route: route.url,
      status,
      title: metrics.title,
      initial_cls: metrics.cls,
      longest_task_ms: metrics.longestTaskMs,
      layout_shift_sources: metrics.layoutShiftSources,
      metric_setup_error: metrics.metricSetupError,
      horizontal_overflow_px: horizontalOverflow,
      overflow_elements: metrics.overflowElements,
      console_errors: consoleErrors,
      network_errors: networkErrors,
      screenshot,
      screenshot_mode: screenshotMode
    };
    report.pages.push(record);

    if (!route.expected.includes(status)
      || horizontalOverflow > 4
      || metrics.cls > 0.25
      || metrics.metricSetupError !== ''
      || consoleErrors.length
      || networkErrors.length) {
      failed = true;
    }
    await page.close();
  }
  await context.close();
}

// Navegacao repetida para detectar crescimento grosseiro de heap em 10 paginas.
const memoryContext = await browser.newContext({ viewport: { width: 1280, height: 900 } });
const memoryPage = await memoryContext.newPage();
await installDeterministicNetwork(memoryPage);
const memoryClient = await memoryContext.newCDPSession(memoryPage);
await memoryClient.send('Performance.enable');
async function heapSize() {
  const result = await memoryClient.send('Performance.getMetrics');
  return Number(result.metrics.find((metric) => metric.name === 'JSHeapUsedSize')?.value || 0);
}
await memoryPage.goto(baseURL + '/', { waitUntil: 'domcontentloaded', timeout: 45_000 });
const heapBefore = await heapSize();
for (let index = 0; index < 10; index += 1) {
  const route = routes[index % routes.length];
  await memoryPage.goto(baseURL + route.url, { waitUntil: 'domcontentloaded', timeout: 45_000 });
  await memoryPage.waitForTimeout(150);
}
await memoryClient.send('HeapProfiler.collectGarbage');
const heapAfter = await heapSize();
const heapGrowth = heapAfter - heapBefore;
report.memory = {
  heap_before_bytes: heapBefore,
  heap_after_bytes: heapAfter,
  heap_growth_bytes: heapGrowth,
  growth_ratio: heapBefore > 0 ? heapAfter / heapBefore : null
};
if (heapGrowth > 64 * 1024 * 1024 && heapBefore > 0 && heapAfter / heapBefore > 2.5) failed = true;
await memoryClient.detach();
await memoryContext.close();

await fs.writeFile(path.join(outputDir, 'report.json'), JSON.stringify(report, null, 2));
await browser.close();

console.log(JSON.stringify(report, null, 2));
if (failed) process.exit(1);
