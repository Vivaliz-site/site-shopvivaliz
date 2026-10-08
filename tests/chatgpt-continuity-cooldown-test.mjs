import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { test, after } from 'node:test';
import { setTimeout as sleep } from 'node:timers/promises';
const state = fs.mkdtempSync(path.join(os.tmpdir(), 'continuity-cooldown-'));
process.env.SHOPVIVALIZ_AGENT_TASK_STATE_DIR = state;
process.env.CHATGPT_CONTINUITY_MONITOR_FALLBACK_FILE = path.join(state, '_fallback.json');
const { reinforcementHealthPayload, reinforcementLoop, attemptNudge, sendContinueMessage } = await import('../scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs');
after(() => fs.rmSync(state, { recursive: true, force: true }));
test('platform additional checks cannot retain a healthy readiness result', () => {
 const value = reinforcementHealthPayload({action:'additional_checks_cooldown',sent:false,progress_confirmed:false,failure_reason:'additional_checks'}, '2026-10-03T14:00:00.000Z', {action:'confirmed_progress',degraded:false,progress_confirmed:true});
 assert.equal(value.degraded,true);
 assert.equal(value.progress_confirmed,false);
 assert.equal(value.failure_reason,'additional_checks');
});
test('five-minute cooldown renews local heartbeat without a browser check', async () => {
 const stop=new Error('finished isolated cooldown test');
 let now=0,calls=0,waits=0,previous='';
 await assert.rejects(() => reinforcementLoop(
  async () => {calls++;return {action:'additional_checks_cooldown',sent:false,progress_confirmed:false,failure_reason:'additional_checks',cross_device_discovery:false};},
  () => now,
  async () => {
   const value=JSON.parse(fs.readFileSync(path.join(state,'_chatgpt-continuity-monitor-state.json')));
   assert.notEqual(value.updated_at,previous,'cooldown must not starve the 180-second freshness gate');
   previous=value.updated_at;
   if (++waits===6) throw stop;
   now+=30000;
   await sleep(15);
  }, () => true, () => true), error => error===stop);
 assert.equal(calls,1);
});
function adapter({initialChecks=false,checksAfterReload=false,streamStatus='IN_PROGRESS'}={}) {
 let checks=initialChecks,generating=true;
 const writes=[];
 return {writes,async evaluate(expression) {
  const source=String(expression);
  if(source==='location.pathname') return '/c/test-bound-conversation';
  if(source.includes('continuity-error-banner-probe')) return checks;
  if(source.includes('continuity-additional-checks-probe')) return checks;
  if(source.includes('location.reload()')) {writes.push('reload');checks||=checksAfterReload;return true;}
  if(source.includes('stale-complete-stop-clear')) {writes.push('stop');generating=false;return true;}
  if(source.includes('/stream_status')) return {http_status:200,status:streamStatus};
  if(source.includes('stop-button')) return generating;
  if(source.includes('snapshotSource')) return {count:0,lastText:'',lastLength:0,surfaceText:'',surfaceLength:0,conversationPath:'/c/test-bound-conversation'};
  if(source.includes('continuity-conversation-identity-probe')) return '/c/test-bound-conversation';
  if(source.includes('b.click()')) {writes.push('send');return true;}
  if(source.includes('insertText')||source.includes('proto.value')) {writes.push('type');return true;}
  return false;
 },close(){}};
}
test('checks appearing after reattach prevent Stop and continuation', async () => {
 const cdp=adapter({checksAfterReload:true,streamStatus:'COMPLETE'});
 const result=await attemptNudge('isolated-test',async()=>cdp,async()=>false,async()=>true);
 assert.equal(result.failure_reason,'additional_checks');
 assert.equal(result.sent,false);
 assert.equal(result.result_status,'STALLED_NOT_CONFIRMED');
 assert.deepEqual(cdp.writes,['reload']);
});
test('direct send rejects platform checks before editing a draft', async () => {
 const cdp=adapter({initialChecks:true});
 assert.equal(await sendContinueMessage(cdp),false);
 assert.deepEqual(cdp.writes,[]);
});
test('worker installation precedes authenticated-session readiness', () => {
 const root=path.resolve(path.dirname(new URL(import.meta.url).pathname),'..');
 const body=fs.readFileSync(path.join(root,'scripts/install-chatgpt-continuity-backend-bridge.sh'),'utf8');
 const installed=body.indexOf('systemctl --user is-active --quiet "$unit"');
 const readiness=body.indexOf('sudo -n systemctl start "$browser_guardian_service"');
 assert.ok(installed>=0&&readiness>installed,'expired authentication must not prevent the newly copied worker from restarting');
 assert.ok(body.indexOf('echo "CHATGPT_CONTINUITY_BACKEND_SERVICE=PASS"')>readiness,'readiness PASS remains behind the real guardian gate');
});
