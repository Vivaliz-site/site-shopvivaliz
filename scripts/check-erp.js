const https = require('https');
const fs = require('fs');

const endpoint = 'https://shopvivaliz.com.br/erp/health';
const maxAttempts = 3;

function fetchHealth() {
  return new Promise((resolve, reject) => {
    const request = https.get(endpoint, {
      headers: { 'User-Agent': 'ShopVivaliz-ERP-Policy-Check/1.0' },
      timeout: 10000,
    }, response => {
      let data = '';
      response.setEncoding('utf8');
      response.on('data', chunk => data += chunk);
      response.on('end', () => {
        if (response.statusCode !== 200) {
          reject(new Error(`http_${response.statusCode || 'unknown'}`));
          return;
        }
        try {
          const json = JSON.parse(data);
          fs.writeFileSync('erp-health.json', JSON.stringify(json, null, 2));
          if (!json.ok) {
            reject(new Error('erp_reported_not_ok'));
            return;
          }
          resolve(json);
        } catch {
          reject(new Error('invalid_json'));
        }
      });
    });
    request.on('timeout', () => request.destroy(new Error('timeout')));
    request.on('error', reject);
  });
}

async function main() {
  let lastError = 'unknown';
  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    try {
      const json = await fetchHealth();
      console.log(`erp_health_ok=true attempt=${attempt}`);
      console.log(`erp_checks=${Object.keys(json.checks || {}).join(',')}`);
      return;
    } catch (error) {
      lastError = error instanceof Error ? error.message : 'unknown';
      if (attempt < maxAttempts) {
        console.warn(`erp_health_retry=true attempt=${attempt} reason=${lastError}`);
        await new Promise(resolve => setTimeout(resolve, attempt * 2000));
      }
    }
  }
  console.error(`erp_health_ok=false attempts=${maxAttempts} reason=${lastError}`);
  process.exit(1);
}

main().catch(() => process.exit(1));
