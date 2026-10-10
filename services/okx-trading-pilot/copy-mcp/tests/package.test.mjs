import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

async function readJson(path){
  try{return JSON.parse(await readFile(new URL(path,import.meta.url),'utf8'))}catch{return null}
}

test('copy MCP declares every direct runtime dependency reproducibly',async()=>{
  const pkg=await readJson('../package.json');
  assert.ok(pkg,'package.json is required');
  assert.equal(pkg.type,'module');
  assert.equal(pkg.dependencies?.['@modelcontextprotocol/sdk'],'1.32.0');
  assert.equal(pkg.dependencies?.['@okx_ai/okx-trade-mcp'],'1.4.8');
  assert.equal(pkg.dependencies?.['smol-toml'],'1.9.0');
  assert.equal(pkg.scripts?.test,'node --test tests/*.test.mjs');
});

test('copy MCP commits an npm lockfile for clean installs',async()=>{
  const lock=await readJson('../package-lock.json');
  assert.ok(lock,'package-lock.json is required');
  assert.equal(lock.lockfileVersion,3);
  const root=lock.packages?.[''];
  assert.equal(root?.dependencies?.['@modelcontextprotocol/sdk'],'1.32.0');
  assert.equal(root?.dependencies?.['@okx_ai/okx-trade-mcp'],'1.4.8');
  assert.equal(root?.dependencies?.['smol-toml'],'1.9.0');
});
