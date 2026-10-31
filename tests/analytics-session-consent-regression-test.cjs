const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = process.env.TRACKING_TEST_ROOT || path.join(__dirname, '..');
const eventsSource = fs.readFileSync(path.join(root, 'js/shopvivaliz-google-events.js'), 'utf8');
const privacySource = fs.readFileSync(path.join(root, 'js/privacy-consent-v1.js'), 'utf8');
function browser(cookieText, entries = {}, ga4MeasurementId = 'G-1H55K1TZ5D') {
  const cookies = new Map(cookieText.split(';').map(s => s.trim()).filter(Boolean).map(s => {const i=s.indexOf('='); return [s.slice(0,i),s.slice(i+1)];}));
  const saved = new Map(Object.entries(entries));
  const storage = { getItem: key => saved.get(key) || null, setItem: (key,value) => saved.set(key,String(value)), removeItem: key => saved.delete(key) };
  const beacons = [];
  const document = {readyState:'loading',addEventListener(){},getElementById(){return null;},documentElement:{style:{removeProperty(){}}}};
  Object.defineProperty(document,'cookie',{get:()=>Array.from(cookies,([key,value])=>key+'='+value).join('; '),set:value=>{const first=value.split(';')[0];const i=first.indexOf('=');cookies.set(first.slice(0,i),first.slice(i+1));}});
  const window = {ShopVivalizTrackingConfig:{ga4MeasurementId},location:{pathname:'/audit-fixture',search:'',protocol:'https:',href:'https://example.invalid/audit-fixture',reload(){window.reloaded=true;}},gtag(){},crypto:{randomUUID:()=> 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'}};
  const context={window,document,localStorage:storage,sessionStorage:storage,URL,URLSearchParams,Uint8Array,Blob:class {constructor(parts){this.parts=parts;}},navigator:{sendBeacon:(url,blob)=>{assert.equal(url,'/api/analytics/funnel-event.php');beacons.push(JSON.parse(blob.parts.join('')));return true;}}};
  vm.createContext(context);
  vm.runInContext(eventsSource,context);
  return {context,window,saved,beacons,push:()=>window.ShopVivalizGoogleEvents.push('view_item',{currency:'BRL',value:10,items:[{item_id:'TEST',price:10,quantity:1}]})};
}

const baseCookie='sv_privacy_consent=accepted; _ga=GA1.1.123456789.1700000000';
const cases=[
  {name:'Official GS2 session is preserved',cookie:baseCookie+'; _ga_1H55K1TZ5D=GS2.1.s2222222222$o1',want:'123456789.1700000000|2222222222'},
  {name:'Retired stream before official must not change attribution',cookie:baseCookie+'; _ga_QWYPLYMZ9=GS2.1.s1111111111$o1; _ga_1H55K1TZ5D=GS2.1.s2222222222$o1',want:'123456789.1700000000|2222222222'},
  {name:'Unrelated stream cannot supply missing official session',cookie:baseCookie+'; _ga_QWYPLYMZ9=GS2.1.s1111111111$o1',want:'123456789.1700000000'},
  {name:'Legacy GS1 format for official stream remains supported',cookie:baseCookie+'; _ga_1H55K1TZ5D=GS1.1.2222222222.1.0.2222222300.0.0.0',want:'123456789.1700000000|2222222222'},
  {name:'Explicit configured stream is selected',cookie:baseCookie+'; _ga_1H55K1TZ5D=GS2.1.s2222222222$o1; _ga_TEST42=GS2.1.s3333333333$o1',config:'G-TEST42',want:'123456789.1700000000|3333333333'},
  {name:'Malformed official cookie is omitted safely',cookie:baseCookie+'; _ga_1H55K1TZ5D=%E0%A4%A',want:'123456789.1700000000'},
];
let failures=0;
function check(name,fn){try{fn();console.log('PASS: '+name);}catch(error){failures++;console.error('FAIL: '+name+' — '+error.message);}}
for(const fixture of cases)check(fixture.name,()=>{const app=browser(fixture.cookie,{},fixture.config);app.push();assert.equal(app.beacons.length,1);assert.equal(app.beacons[0].client_id,fixture.want);});
check('Stored GA identity is not reused after browser cookies disappear',()=>{const app=browser('sv_privacy_consent=accepted',{'sv_funnel_client_v1':'123456789.1700000000|2222222222'});app.push();assert.equal(app.beacons[0].client_id,'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');});
check('Essential-only choice clears the saved GA identity immediately',()=>{const app=browser(baseCookie,{'sv_funnel_client_v1':'123456789.1700000000|2222222222'});vm.runInContext(privacySource,app.context);app.window.ShopVivalizPrivacy.essentialOnly();assert.equal(app.saved.has('sv_funnel_client_v1'),false);app.push();assert.equal(app.beacons.length,0);});
check('Resetting consent clears the saved GA identity before reloading',()=>{const app=browser(baseCookie,{'sv_funnel_client_v1':'123456789.1700000000|2222222222'});vm.runInContext(privacySource,app.context);app.window.ShopVivalizPrivacy.reset();assert.equal(app.saved.has('sv_funnel_client_v1'),false);assert.equal(app.window.reloaded,true);});
check('First-party non-GA fallback remains valid after browser cookies disappear',()=>{const app=browser('sv_privacy_consent=accepted',{'sv_funnel_client_v1':'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'});app.push();assert.equal(app.beacons[0].client_id,'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb');});
process.exitCode=failures?1:0;
