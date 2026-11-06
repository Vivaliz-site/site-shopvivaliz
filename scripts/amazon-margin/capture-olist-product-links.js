#!/usr/bin/env node
'use strict';

/**
 * Read ONLY: product edit -> "Anúncios" in an already authenticated Olist
 * browser, cross-checked against the *current* official Olist V3 product index.
 * Stores original channel Seller SKUs (Amazon/Onsite/Classic) and screenshots
 * in a private runtime file. Never posts, saves, or edits ERP objects.
 *
 * Invocation:
 * node capture-olist-product-links.js <start-index> <count <=25>
 */
const fs = require('fs');
const crypto = require('crypto');
const {chromium} = require('/home/ubuntu/amazon-returns-deploy/shared/olist-erp-browser/runtime/node_modules/playwright-core');

const base = '/home/ubuntu/.shopvivaliz/runtime/amazon-margin';
const inputPath = process.env.SV_AMAZON_OLIST_PRODUCT_TABS_INPUT
  || base + '/product-tab-audit-input-active-all-20261106.json';
const outputPath = process.env.SV_AMAZON_OLIST_PRODUCT_TABS_OUTPUT
  || base + '/olist-active-product-edit-capture-20261106.json';
const evidenceDir = base + '/evidence/olist-product-tabs';
const channels = {'27473':'Amazon', '27478':'Amazon Onsite', '27482':'Amazon Classic'};
const start = Number(process.argv[2] || 0);
const count = Number(process.argv[3] || 20);

if (!Number.isInteger(start) || !Number.isInteger(count) || start < 0 || count < 1 || count > 25) {
  throw Error('invalid_scan_batch');
}
function readPrivateJson(path) {
  const stat = fs.lstatSync(path);
  if (stat.isSymbolicLink() || stat.size > 4 * 1024 * 1024 || (stat.mode & 0o007)) {
    throw Error('private_capture_input_unsafe');
  }
  return JSON.parse(fs.readFileSync(path, 'utf8'));
}
function saveAtomic(data) {
  const tmp = outputPath + '.tmp-' + process.pid;
  fs.writeFileSync(tmp, JSON.stringify(data), {mode: 0o600, flag: 'wx'});
  fs.renameSync(tmp, outputPath);
}
function summarize(data) {
  const bySku = new Map();
  for (const record of Object.values(data.records)) {
    for (const binding of record.bindings) {
      if (!bySku.has(binding.seller_sku)) bySku.set(binding.seller_sku, new Set());
      bySku.get(binding.seller_sku).add(record.product_id);
    }
  }
  return {
    products_read: Object.keys(data.records).length,
    products_failed: Object.keys(data.errors).length,
    seller_skus_observed: bySku.size,
    duplicate_product_links: [...bySku.values()].filter(s => s.size > 1).length
  };
}

(async () => {
  const input = readPrivateJson(inputPath);
  const ids = input.product_ids;
  if (!Array.isArray(ids) || ids.length !== input.active_total || ids.length < 1 || ids.length > 10000) {
    throw Error('active_product_index_incomplete');
  }
  const targets = new Set(input.target_seller_skus || []);
  const data = fs.existsSync(outputPath) ? readPrivateJson(outputPath) : {
    schema: 1,
    source: 'OLIST_ERP_AUTHENTICATED_PRODUCT_EDIT_UI',
    active_count: ids.length,
    observed_at: new Date().toISOString(),
    input_observed_at: input.observed_at,
    channels,
    records: {},
    errors: {}
  };
  if (data.source !== 'OLIST_ERP_AUTHENTICATED_PRODUCT_EDIT_UI' || data.active_count !== ids.length
      || data.input_observed_at !== input.observed_at) {
    throw Error('capture_schema_mismatch');
  }
  fs.mkdirSync(evidenceDir, {recursive: true, mode: 0o700});
  const browser = await chromium.connectOverCDP('http://127.0.0.1:9226', {timeout: 14000});
  const page = await browser.contexts()[0].newPage({viewport: {width: 1440, height: 900}});
  let processed = 0, relevant = 0;
  try {
    for (const id of ids.slice(start, start + count)) {
      const key = String(id);
      if (data.records[key]) continue;
      try {
        await page.goto('https://erp.olist.com/produtos#edit/' + key,
          {waitUntil: 'domcontentloaded', timeout: 19000});
        await page.waitForFunction(
          id => document.querySelector('input[name="id"]')?.value === String(id),
          key, {timeout: 13000}
        );
        await page.waitForTimeout(160);
        const pageData = await page.evaluate(channels => {
          const formId = document.querySelector('input[name="id"]')?.value;
          const groups = {};
          for (const el of document.querySelectorAll(
            'input[name^="mapeamentoProdutosEcommerce["]'
          )) {
            const m = el.name.match(/^mapeamentoProdutosEcommerce\[(\d+)\]\[([^\]]+)\]$/);
            if (m) (groups[m[1]] ||= {})[m[2]] = el.value;
          }
          const bindings = [];
          for (const raw of Object.values(groups)) {
            if (!channels[raw.idEcommerce] || !raw.idProdutoEcommerce) continue;
            bindings.push({
              seller_sku: raw.idProdutoEcommerce,
              channel_id: raw.idEcommerce,
              channel: channels[raw.idEcommerce],
              situacao: raw.situacao || null,
              status: raw.status || null,
              mapping_id: raw.id || null
            });
          }
          return {form_id: formId, bindings};
        }, channels);
        if (pageData.form_id !== key) throw Error('erp_product_form_identity_mismatch');
        const sku = String(input.products[key]?.sku || '');
        if (!sku) throw Error('official_v3_product_sku_missing');
        const record = {
          product_id: Number(key),
          erp_sku: sku,
          url_path: '/produtos#edit/' + key,
          observed_at: new Date().toISOString(),
          bindings: pageData.bindings
        };
        const targetBindings = pageData.bindings.filter(b => targets.has(b.seller_sku));
        if (targetBindings.length) {
          await page.locator('#link-ecommerce').click({timeout: 4500});
          await page.waitForTimeout(130);
          const screenshotDate = record.observed_at.slice(0, 10).replace(/-/g, '');
          const screenshot = evidenceDir + '/product-' + key + '-' + screenshotDate + '.png';
          await page.screenshot({path: screenshot, fullPage: false});
          fs.chmodSync(screenshot, 0o600);
          record.screenshot_sha256 =
            crypto.createHash('sha256').update(fs.readFileSync(screenshot)).digest('hex');
          relevant += targetBindings.length;
        }
        data.records[key] = record;
        delete data.errors[key];
      } catch (e) {
        data.errors[key] = (e?.name || 'Error') + ': ' + String(e?.message || '').slice(0, 110);
      }
      processed++;
      if (processed % 5 === 0) saveAtomic(data);
      await page.waitForTimeout(140);
    }
    data.updated_at = new Date().toISOString();
    saveAtomic(data);
    console.log(JSON.stringify({
      batch_start: start, batch_count: count, processed, target_bindings_in_batch: relevant,
      ...summarize(data), evidence_private: true,
      last_errors: Object.entries(data.errors).slice(-3)
    }));
  } finally {
    await page.close().catch(() => {});
  }
  process.exit(0);
})().catch(e => {
  console.log(JSON.stringify({failed: true, error: String(e.message || e).slice(0, 150)}));
  process.exit(2);
});
