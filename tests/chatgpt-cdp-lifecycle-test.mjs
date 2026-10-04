import assert from 'node:assert/strict';

// A transport double is necessary to reproduce a renderer that never replies.
// Cdp remains real: assertions cover consumer promises and pending cleanup.
class Socket extends EventTarget {
  send(value) { this.last = JSON.parse(value); }
  reply(result) { this.dispatchEvent(new MessageEvent('message', {data:JSON.stringify({id:this.last.id,result})})); }
  close() { this.dispatchEvent(new Event('close')); }
}
const bounded = promise => Promise.race([promise.then(() => 'resolved', e => e.message), new Promise(r => setTimeout(() => r('test_deadline'), 150))]);
export async function runCdpLifecycleTests(Cdp) {
  {
    const socket = new Socket(); const cdp = new Cdp(socket, {commandTimeoutMs:20});
    assert.match(await bounded(cdp.evaluate('42')), /CDP command timed out/, 'unresponsive renderer must reject instead of retaining its caller forever');
    assert.equal(cdp.pending.size, 0); cdp.close();
  }
  for (const signal of ['close','error']) {
    const socket = new Socket(); const cdp = new Cdp(socket, {commandTimeoutMs:1000});
    const first = bounded(cdp.send('Page.enable')); const second = bounded(cdp.send('Runtime.enable'));
    socket.dispatchEvent(new Event(signal));
    assert.match(await first, /CDP connection closed/); assert.match(await second, /CDP connection closed/);
    assert.equal(cdp.pending.size, 0); cdp.close();
    assert.match(await bounded(cdp.send('Page.enable')), /CDP connection closed/);
  }
  {
    const socket = new Socket(); const cdp = new Cdp(socket, {commandTimeoutMs:20});
    const result = cdp.evaluate('42'); socket.reply({result:{value:42}});
    assert.equal(await result,42); assert.equal(cdp.pending.size,0); cdp.close();
  }
  {
    const socket = new Socket(); const cdp = new Cdp(socket, {commandTimeoutMs:1000});
    const result = bounded(cdp.send('Page.enable')); cdp.close();
    assert.match(await result,/CDP connection closed/); assert.equal(cdp.pending.size,0);
  }
  {
    const socket = new Socket(); socket.send = () => {throw Error('socket_send_failed');};
    const cdp = new Cdp(socket, {commandTimeoutMs:1000});
    assert.equal(await bounded(cdp.send('Page.enable')),'socket_send_failed');
    assert.equal(cdp.pending.size,0); cdp.close();
  }
  console.log('CDP_LIFECYCLE_TEST=PASS');
}
