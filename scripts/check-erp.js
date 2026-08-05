const http = require('http');
const https = require('https');
const fs = require('fs');

const endpoint = process.env.ERP_HEALTH_ENDPOINT || 'https://shopvivaliz.com.br/erp/health';

function boundedInteger(value, fallback, minimum, maximum) {
  const parsed = Number.parseInt(String(value || ''), 10);
  if (!Number.isFinite(parsed)) return fallback;
  return Math.min(maximum, Math.max(minimum, parsed));
}

const maxAttempts = boundedInteger(process.env.ERP_HEALTH_MAX_ATTEMPTS, 10, 1, 20);
const retryDelayMs = boundedInteger(process.env.ERP_HEALTH_RETRY_DELAY_MS, 10000, 1000, 60000);
const timeoutMs = boundedInteger(process.env.ERP_HEALTH_TIMEOUT_MS, 30000, 1000, 120000);
const transientStatusCodes = new Set([408, 425, 429, 500, 502, 503, 504]);

class HealthCheckError extends Error {
  constructor(message, options = {}) {
    super(message);
    this.name = 'HealthCheckError';
    this.statusCode = options.statusCode || 0;
    this.transient = options.transient !== false;
    this.retryAfterMs = options.retryAfterMs || 0;
  }
}

function buildAttemptUrl(attempt) {
  const url = new URL(endpoint);
  url.searchParams.set('policy_check', `${Date.now()}-${attempt}`);
  return url;
}

function parseRetryAfter(value) {
  if (!value) return 0;

  const seconds = Number.parseInt(String(value), 10);
  if (Number.isFinite(seconds) && seconds >= 0) {
    return Math.min(seconds * 1000, 60000);
  }

  const date = Date.parse(String(value));
  if (!Number.isNaN(date)) {
    return Math.min(Math.max(0, date - Date.now()), 60000);
  }

  return 0;
}

function failedChecksSummary(body) {
  try {
    const payload = JSON.parse(body);
    const failed = [];

    for (const [groupName, groupValue] of Object.entries(payload.checks || {})) {
      if (groupValue === false) {
        failed.push(groupName);
        continue;
      }

      if (!groupValue || typeof groupValue !== 'object') continue;
      if (groupValue.ok === false) failed.push(groupName);

      for (const [checkName, checkValue] of Object.entries(groupValue.checks || {})) {
        if (checkValue === false) failed.push(`${groupName}.${checkName}`);
      }
    }

    return failed.length > 0 ? ` failed_checks=${failed.join(',')}` : '';
  } catch {
    return '';
  }
}

function fetchHealth(attempt) {
  return new Promise((resolve, reject) => {
    const url = buildAttemptUrl(attempt);
    const transport = url.protocol === 'http:' ? http : https;
    const request = transport.get(url, {
      family: 4,
      agent: false,
      headers: {
        Accept: 'application/json',
        Connection: 'close',
        'User-Agent': 'ShopVivaliz-ERP-Policy-Check/2.0',
      },
      timeout: timeoutMs,
    }, response => {
      let data = '';
      response.setEncoding('utf8');
      response.on('data', chunk => data += chunk);
      response.on('end', () => {
        const statusCode = response.statusCode || 0;

        if (statusCode !== 200) {
          reject(new HealthCheckError(
            `http_${statusCode || 'unknown'}${failedChecksSummary(data)}`,
            {
              statusCode,
              transient: transientStatusCodes.has(statusCode),
              retryAfterMs: parseRetryAfter(response.headers['retry-after']),
            },
          ));
          return;
        }

        try {
          const json = JSON.parse(data);
          if (!json.ok) {
            reject(new HealthCheckError(
              `erp_reported_not_ok${failedChecksSummary(data)}`,
              { statusCode, transient: true },
            ));
            return;
          }

          fs.writeFileSync('erp-health.json', JSON.stringify(json, null, 2));
          resolve(json);
        } catch (error) {
          if (error instanceof HealthCheckError) {
            reject(error);
            return;
          }
          reject(new HealthCheckError('invalid_json', { statusCode, transient: false }));
        }
      });
    });

    request.on('timeout', () => request.destroy(new HealthCheckError('timeout')));
    request.on('error', error => {
      if (error instanceof HealthCheckError) {
        reject(error);
        return;
      }
      reject(new HealthCheckError(error && error.code ? `network_${error.code}` : 'network_error'));
    });
  });
}

function sleep(milliseconds) {
  return new Promise(resolve => setTimeout(resolve, milliseconds));
}

async function main() {
  let lastError = new HealthCheckError('unknown');

  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    try {
      const json = await fetchHealth(attempt);
      console.log(`erp_health_ok=true attempt=${attempt}`);
      console.log(`erp_checks=${Object.keys(json.checks || {}).join(',')}`);
      return;
    } catch (error) {
      lastError = error instanceof HealthCheckError
        ? error
        : new HealthCheckError('unknown');

      const canRetry = lastError.transient && attempt < maxAttempts;
      if (!canRetry) break;

      const waitMs = Math.max(retryDelayMs, lastError.retryAfterMs);
      console.warn(
        `erp_health_retry=true attempt=${attempt} reason=${lastError.message} wait_ms=${waitMs}`,
      );
      await sleep(waitMs);
    }
  }

  console.error(
    `erp_health_ok=false attempts=${maxAttempts} reason=${lastError.message}`,
  );
  process.exit(1);
}

main().catch(error => {
  const reason = error instanceof Error ? error.message : 'unknown';
  console.error(`erp_health_ok=false reason=${reason}`);
  process.exit(1);
});
