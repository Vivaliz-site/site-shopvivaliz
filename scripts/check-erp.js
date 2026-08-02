const https = require('https');
const fs = require('fs');

https.get('https://shopvivaliz.com.br/erp/health', res => {
  let data = '';
  res.on('data', chunk => data += chunk);
  res.on('end', () => {
    try {
      const json = JSON.parse(data);
      fs.writeFileSync('erp-health.json', JSON.stringify(json, null, 2));
      if (!json.ok) process.exit(1);
    } catch {
      process.exit(1);
    }
  });
}).on('error', () => process.exit(1));