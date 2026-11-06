'use strict';

// Read-only authenticated Olist product editor capture for Amazon Seller SKUs.
// This script NEVER presses Save or writes to the Olist ERP / Amazon.
// Run on Marina as ubuntu with the pre-existing CDP browser user profile.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { chromium } = require('/home/ubuntu/amazon-returns-deploy/shared/olist-erp-browser/runtime/node_modules/playwright-core');

const ROOT = '/home/ubuntu/.shopvivaliz/runtime/amazon-margin';
const INPUT = path.join(ROOT, 'product-tab-input-v3-frozen-r9-20261106.json');
const OUTDIR = path.join(ROOT, 'product-tabs-scan-v3-20261106');
const EVIDENCE = path.join(ROOT, 'evidence');
const VALID_INTEGRATIONS = new Map([['27473', 'Amazon'], ['27478', 'Amazon Onsite'], ['27482', 'Amazon Classic']]);

function fail(message) { throw new Error(message); }
function savePrivate(filename, value) {
    const file = path.join(OUTDIR, filename);
    if (fs.existsSync(file) || fs.lstatSync(OUTDIR).isSymbolicLink()) fail('output_already_exists');
    const tmp = file + '.tmp.' + process.pid;
    fs.writeFileSync(tmp, JSON.stringify(value), {flag: 'wx', mode: 0o600});
    fs.renameSync(tmp, file);
    return file;
}

async function main() {
    const start = Number(process.argv[2]);
    const max = Number(process.argv[3]);
    if (!Number.isInteger(start) || start < 0 || !Number.isInteger(max) || max < 1 || max > 30) {
        fail('usage: node capture-olist-product-tabs.cjs START MAX_30');
    }
    const st=fs.lstatSync(INPUT);
    if (!st.isFile() || st.isSymbolicLink() || (st.mode & 0o007) !== 0 || st.size > 900000) {
        fail('active_catalog_input_not_private');
    }
    const input = JSON.parse(fs.readFileSync(INPUT, 'utf8'));
    if (input.source !== 'OLIST_V3_CURRENT_ACTIVE_PRODUCTS'
        || input.active_total !== 202
        || input.product_ids?.length !== 202
        || input.target_seller_skus?.length !== 61
        || start + max > input.active_total) {
        fail('active_catalog_not_complete_or_scope_drift');
    }
    const target = new Set(input.target_seller_skus);
    if (target.size !== input.target_seller_skus.length) fail('duplicate_target');
    fs.mkdirSync(OUTDIR, {recursive: true, mode: 0o700});
    fs.mkdirSync(EVIDENCE, {recursive: true, mode: 0o700});
    const browser = await chromium.connectOverCDP('http://127.0.0.1:9226', {timeout: 15000});
    const context=browser.contexts()[0];
    if (!context) fail('authenticated_olist_browser_profile_missing');
    const page=await context.newPage({viewport:{width:1440,height:900}});
    const records = [];
    const errors = [];
    let hits=0;
    try {
        for (const id of input.product_ids.slice(start,start+max)) {
            const result={erp_id:id,seller_skus:[],observed_at:null};
            try {
                const url='https://erp.olist.com/produtos#edit/'+id;
                await page.goto(url,{waitUntil:'domcontentloaded',timeout:23000});
                await page.waitForFunction(expected => {
                    return location.hostname === 'erp.olist.com'
                        && location.pathname === '/produtos'
                        && location.hash === '#edit/'+expected
                        && document.querySelector('input[name="id"]')?.value === String(expected);
                },id,{timeout:15000});
                await page.waitForTimeout(320);
                const data=await page.evaluate(()=>{
                    const inputs=[...document.querySelectorAll('input[name^="mapeamentoProdutosEcommerce["]')];
                    const byIndex=new Map();
                    for(const x of inputs) {
                        const m=x.name.match(/^mapeamentoProdutosEcommerce\[(\d+)\]\[([^\]]+)\]$/);
                        if (!m) continue;
                        const r=byIndex.get(m[1])||{};
                        r[m[2]]=x.value;
                        byIndex.set(m[1],r);
                    }
                    return {id:Number(document.querySelector('input[name="id"]')?.value||0),
                        type:document.querySelector('input[name="classeProduto"]')?.value||'',
                        ads:[...byIndex.values()].map(row=>({
                            sku:row.idProdutoEcommerce||'',
                            channel:row.idEcommerce||'',
                            situation:row.situacao||'',
                            status:row.status||''
                        }))};
                });
                if(data.id!==id)fail('product_identity_changed');
                result.erp_product_type=data.type;
                const found=data.ads.filter(x=>target.has(x.sku)
                    && VALID_INTEGRATIONS.has(x.channel)
                    && x.situation === 'A' && x.status === '1');
                result.seller_skus=found.map(x=>({
                    seller_sku:x.sku,integration:VALID_INTEGRATIONS.get(x.channel),
                    integration_id:x.channel
                }));
                if(found.length) {
                    await page.locator('#link-ecommerce').click({timeout:9000});
                    await page.waitForTimeout(280);
                    const displayed=await page.locator('body').innerText();
                    if (found.some(x=>!displayed.includes(x.sku)))fail('seller_sku_not_visible_in_real_olist_tab');
                    const stamp=new Date().toISOString().slice(0,10).replace(/-/g,'');
                    const basename='olist-product-tabs-v3-'+id+'-'+stamp+'.png';
                    const file=path.join(EVIDENCE,basename);
                    if (fs.existsSync(file))fail('product_screenshot_already_exists');
                    await page.screenshot({path:file,fullPage:true});
                    fs.chmodSync(file,0o600);
                    result.screenshot=basename;
                    result.screenshot_sha256=crypto.createHash('sha256')
                        .update(fs.readFileSync(file)).digest('hex');
                    result.observed_at=new Date().toISOString();
                    hits+=found.length;
                }
                records.push(result);
            } catch(e) {
                errors.push({erp_id:id,reason:e.name+': '+String(e.message).slice(0,110)});
                records.push({erp_id:id,status:'error',reason:e.name});
            }
            await page.waitForTimeout(140);
        }
    } finally { await page.close().catch(()=>{}); }
    const payload={
        schema:1,
        source:'OLIST_ERP_AUTHENTICATED_PRODUCT_TABS_CAPTURE',
        scope:{url_path:'/produtos',active_product_ids_total:input.active_total,
            input_observed_at:input.observed_at,scan_start:start,scan_count:max},
        observed_at:new Date().toISOString(),
        products:records,errors
    };
    const file=savePrivate('chunk-'+String(start).padStart(3,'0')+'-'+String(max).padStart(2,'0')+'.json',payload);
    console.log(JSON.stringify({status:errors.length?'PARTIAL':'COMPLETE',start,
        scanned:records.length,hits,errors,filename:file}));
    if(errors.length)process.exitCode=3;
}

main().catch(e=>{console.log(JSON.stringify({status:'FAILED',error:e.name,
    detail:String(e.message).slice(0,155)}));process.exitCode=2;})
    .finally(()=>{process.exit(process.exitCode||0)});
