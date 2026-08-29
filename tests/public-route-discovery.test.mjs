import test from 'node:test';
import assert from 'node:assert/strict';
import { discoverPublicRoutes, extractLocs, normalizeAuditRoute } from '../scripts/lib/public-route-discovery.mjs';

test('extractLocs reads sitemap loc entries', () => {
  assert.deepEqual(extractLocs('<urlset><url><loc>https://shopvivaliz.com.br/a/</loc></url><url><loc>https://shopvivaliz.com.br/b.php</loc></url></urlset>'), [
    'https://shopvivaliz.com.br/a/',
    'https://shopvivaliz.com.br/b.php',
  ]);
});

test('normalizeAuditRoute keeps same-origin HTML routes and rejects assets/private paths', () => {
  const base = 'https://shopvivaliz.com.br';
  assert.equal(normalizeAuditRoute('https://shopvivaliz.com.br/produto/teste/', base), '/produto/teste/');
  assert.equal(normalizeAuditRoute('https://shopvivaliz.com.br/avaliacoes.php', base), '/avaliacoes.php');
  assert.equal(normalizeAuditRoute('https://example.com/x/', base), null);
  assert.equal(normalizeAuditRoute('https://shopvivaliz.com.br/images/a.webp', base), null);
  assert.equal(normalizeAuditRoute('https://shopvivaliz.com.br/admin/', base), null);
  assert.equal(normalizeAuditRoute('https://shopvivaliz.com.br/api/test', base), null);
});

test('discoverPublicRoutes follows nested sitemap indexes, deduplicates and preserves public pages', async () => {
  const docs = new Map([
    ['https://shopvivaliz.com.br/sitemap.xml', '<sitemapindex><sitemap><loc>https://shopvivaliz.com.br/sitemap-pages.xml</loc></sitemap><sitemap><loc>https://shopvivaliz.com.br/sitemap-products.xml</loc></sitemap></sitemapindex>'],
    ['https://shopvivaliz.com.br/sitemap-pages.xml', '<urlset><url><loc>https://shopvivaliz.com.br/</loc></url><url><loc>https://shopvivaliz.com.br/faq/</loc></url><url><loc>https://example.com/foreign/</loc></url></urlset>'],
    ['https://shopvivaliz.com.br/sitemap-products.xml', '<urlset><url><loc>https://shopvivaliz.com.br/produto/a/</loc></url><url><loc>https://shopvivaliz.com.br/faq/</loc></url><url><loc>https://shopvivaliz.com.br/images/a.jpg</loc></url></urlset>'],
  ]);
  const fetchImpl = async (url) => ({ ok: docs.has(url), text: async () => docs.get(url) || '' });
  assert.deepEqual(await discoverPublicRoutes({ baseUrl: 'https://shopvivaliz.com.br', fetchImpl }), ['/', '/faq/', '/produto/a/']);
});
