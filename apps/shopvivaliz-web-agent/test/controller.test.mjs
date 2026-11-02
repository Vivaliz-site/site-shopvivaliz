import {test} from 'node:test';
import assert from 'node:assert/strict';
import {TakeoverController} from '../src/controller.mjs';

function fake(){
  const actions=[];
  return {actions,async open(url){actions.push(['open',url]);return {url:'https://example.org/',title:'Example'}},async inspect(){actions.push(['inspect']);return {title:'Example',controls:[]}},async click(s){actions.push(['click',s]);return {title:'After click'}},async screenshot(){actions.push(['frame']);return Buffer.from('JPG')},async mouse(x,y){actions.push(['mouse',x,y])},async type(text){actions.push(['type',text])},async key(key){actions.push(['key',key])},async close(){actions.push(['close'])}};
}

test('human takeover pauses all agent page reads/actions, returns without secret in model content',async()=>{
  const driver=fake(),controller=new TakeoverController(driver);
  const s=await controller.open('https://example.org');
  assert.equal(s.mode,'agent');
  const handoff=await controller.handoff(s.session_id);
  const cap=handoff.private.capability;
  assert.ok(cap.length>32);
  assert.ok(!JSON.stringify(handoff.public).includes(cap));
  assert.equal(handoff.public.mode,'human');
  await assert.rejects(controller.inspect(s.session_id),/human_takeover_active/);
  await assert.rejects(controller.click(s.session_id,'#delete'),/human_takeover_active/);
  await assert.rejects(controller.open('https://example.org'),/human_takeover_active/);
  await assert.rejects(controller.close(s.session_id),/human_takeover_active/);
  await assert.rejects(controller.humanAction(s.session_id,'wrong','type',{text:'secret'}),/invalid_capability/);
  assert.deepEqual(await controller.humanFrame(s.session_id,cap),Buffer.from('JPG'));
  await controller.humanAction(s.session_id,cap,'mouse',{x:500,y:300});
  await controller.humanAction(s.session_id,cap,'type',{text:'fake-test-value'});
  await controller.humanAction(s.session_id,cap,'key',{key:'Enter'});
  assert.equal((await controller.release(s.session_id,cap)).mode,'agent');
  await assert.rejects(controller.humanFrame(s.session_id,cap),/human_takeover_inactive/);
  assert.equal((await controller.inspect(s.session_id)).title,'Example');
  assert.deepEqual(driver.actions.slice(-4),[['mouse',500,300],['type','fake-test-value'],['key','Enter'],['inspect']]);
});

test('expired capability cannot grant access, cannot unlock agent, only reissue',async()=>{
  let clock=1000;
  const controller=new TakeoverController(fake(),{now:()=>clock,handoffTtlMs:100});
  const s=await controller.open('https://example.org');
  const first=await controller.handoff(s.session_id);
  clock=1101;
  await assert.rejects(controller.humanFrame(s.session_id,first.private.capability),/handoff_expired/);
  await assert.rejects(controller.release(s.session_id,first.private.capability),/handoff_expired/);
  assert.equal(controller.status(s.session_id).mode,'human');
  assert.equal(controller.status(s.session_id).waiting_for_human,true);
  await assert.rejects(controller.inspect(s.session_id),/human_takeover_active/);
  const next=await controller.handoff(s.session_id);
  await assert.rejects(controller.release(s.session_id,first.private.capability),/invalid_capability/);
  await controller.release(s.session_id,next.private.capability);
  assert.equal(controller.status(s.session_id).mode,'agent');
});

test('concurrent human action and agent action are serialized without bypass',async()=>{
  const controller=new TakeoverController(fake());
  const s=await controller.open('https://example.org');
  const h=await controller.handoff(s.session_id);
  const [action,attempt]=await Promise.allSettled([controller.humanAction(s.session_id,h.private.capability,'key',{key:'Tab'}),controller.click(s.session_id,'button')]);
  assert.equal(action.status,'fulfilled');
  assert.equal(attempt.status,'rejected');
  assert.match(attempt.reason.message,/human_takeover_active/);
});
