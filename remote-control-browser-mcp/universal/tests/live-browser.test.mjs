import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve } from 'node:path';
import { BrowserSession } from '../live-browser.mjs';

const html = '<!doctype html><title>Fixture</title><input id="name"><button id="change" onclick="document.title=\'Changed\'">Change</button>';
const mk = () => mkdtempSync(resolve(tmpdir(),'sv-browser-live-test-'));
const chromium = process.env.SHOPVIVALIZ_BROWSER_UNIVERSAL_BINARY || '/opt/shopvivaliz-browser/chrome-linux/chrome';

test('live tabs preserve unsaved form data when switched', { timeout: 75000 }, async () => {
  const dir=mk();
  const session=new BrowserSession({root:dir,profileDir:resolve(dir,'profile'),binary:chromium});
  try {
    await session.start();
    await session.context.route('https://example.com/*', r=>r.fulfill({status:200,contentType:'text/html',body:html}));
    const first=await session.execute('tabs_open',{url:'https://example.com/form1'});
    assert.ok(first.active);
    await session.execute('fill',{tab_id:first.active,selector:'#name',text:'still here'});
    const second=await session.execute('tabs_open',{url:'https://example.com/form2'});
    assert.notEqual(first.active,second.active);
    const switched=await session.execute('tabs_switch',{tab_id:first.active});
    assert.equal(switched.reloaded,false);
    assert.equal(await session.activePage().locator('#name').inputValue(),'still here');
    await session.execute('click',{tab_id:first.active,selector:'#change'});
    assert.equal(await session.activePage().title(),'Changed');
    const list=await session.execute('tabs_list',{});
    assert.equal(list.tabs.length,2);
    assert.equal(list.active,first.active);
  } finally {
    await session.close().catch(()=>{});
    rmSync(dir,{recursive:true,force:true});
  }
});

test('browser restart restores tab references without claiming JavaScript state', { timeout: 75000 }, async () => {
  const dir=mk();
  let s=new BrowserSession({root:dir,profileDir:resolve(dir,'profile'),binary:chromium});
  try {
    await s.start();
    await s.context.route('https://example.com/*', r=>r.fulfill({status:200,contentType:'text/html',body:html}));
    const opened=await s.execute('tabs_open',{url:'https://example.com/form1'});
    await s.execute('fill',{tab_id:opened.active,selector:'#name',text:'unsaved'});
    await s.close();
    s=new BrowserSession({root:dir,profileDir:resolve(dir,'profile'),binary:chromium});
    await s.start();
    await s.context.route('https://example.com/*', r=>r.fulfill({status:200,contentType:'text/html',body:html}));
    const restored=await s.execute('tabs_switch',{tab_id:opened.active});
    assert.equal(restored.reloaded,true);
    assert.equal(await s.activePage().locator('#name').inputValue(),'');
    assert.equal((await s.execute('tabs_list',{})).tabs.length,1);
  } finally {
    await s.close().catch(()=>{});
    rmSync(dir,{recursive:true,force:true});
  }
});

test('isolated browser profile preserves cookie with Max-Age after restart', {timeout:75000}, async () => {
  const dir=mk();
  let s=new BrowserSession({root:dir,profileDir:resolve(dir,'profile'),binary:chromium});
  try {
    await s.start();
    await s.context.route('https://example.com/*',r=>r.fulfill({status:200,contentType:'text/html',body:html}));
    const opened=await s.execute('tabs_open',{url:'https://example.com/form1'});
    await s.activePage().evaluate(()=>{document.cookie='sv_test_cookie=persisted; Max-Age=3600; SameSite=Lax; path=/';});
    assert.match(await s.activePage().evaluate(()=>document.cookie),/sv_test_cookie=persisted/);
    await s.close();
    s=new BrowserSession({root:dir,profileDir:resolve(dir,'profile'),binary:chromium});
    await s.start();
    await s.context.route('https://example.com/*',r=>r.fulfill({status:200,contentType:'text/html',body:html}));
    const recovered=await s.execute('tabs_switch',{tab_id:opened.active});
    assert.equal(recovered.reloaded,true);
    assert.match(await s.activePage().evaluate(()=>document.cookie),/sv_test_cookie=persisted/);
  } finally {
    await s.close().catch(()=>{});
    rmSync(dir,{recursive:true,force:true});
  }
});

test('browser refuses internal and private hostnames', { timeout: 45000 }, async () => {
  const dir=mk();
  const session=new BrowserSession({root:dir,profileDir:resolve(dir,'profile'),binary:chromium});
  try {
    await session.start();
    for (const url of ['http://127.0.0.1/', 'http://169.254.169.254/', 'http://192.168.1.1/', 'http://[::1]/', 'http://[::ffff:127.0.0.1]/', 'http://100.64.1.1/']) {
      await assert.rejects(()=>session.execute('tabs_open',{url}),/blocked|private|local/i);
    }
    assert.equal((await session.execute('tabs_list',{})).tabs.length,0);
  } finally {
    await session.close().catch(()=>{});
    rmSync(dir,{recursive:true,force:true});
  }
});
