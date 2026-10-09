import assert from 'node:assert/strict';
import vm from 'node:vm';

// Small structural DOM fixture. Execute the production browser program itself,
// not a canned result chosen by inspecting its source. No browser or network.
class Element {
  constructor(tag, attrs = {}, children = []) {
    this.tagName = tag.toLowerCase(); this.attrs = attrs; this.children = children;
    for (const child of children) if (child instanceof Element) child.parentElement = this;
  }
  get textContent() { return this.children.map(c => typeof c === 'string' ? c : c.textContent).join(' '); }
  get innerText() { return this.textContent; }
  matches(selector) {
    return selector.split(',').some(raw => {
      const s = raw.trim();
      if (s.startsWith('#')) return this.attrs.id === s.slice(1);
      const attr = s.match(/^\[([\w-]+)(?:(\^?=)"([^"]*)")?\]$/);
      if (attr) return Object.hasOwn(this.attrs, attr[1]) && (!attr[2] || (attr[2] === '^=' ? String(this.attrs[attr[1]]).startsWith(attr[3]) : this.attrs[attr[1]] === attr[3]));
      return this.tagName === s;
    });
  }
  querySelectorAll(selector) {
    return this.children.flatMap(child => child instanceof Element
      ? [...(child.matches(selector) ? [child] : []), ...child.querySelectorAll(selector)] : []);
  }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  cloneNode(deep) { return new Element(this.tagName, { ...this.attrs }, deep ? this.children.map(c => typeof c === 'string' ? c : c.cloneNode(true)) : []); }
  remove() { if (this.parentElement) this.parentElement.children = this.parentElement.children.filter(c => c !== this); }
}
const el = (tag, attrs, ...children) => new Element(tag, attrs, children);
const message = 'Could not load this ChatGPT conversation. Try again';
function browserFixture(body, pathname = '/c/fixture-thread-id') {
  const document = { body, querySelector: s => body.querySelector(s), querySelectorAll: s => body.querySelectorAll(s) };
  return { evaluate: expression => vm.runInNewContext(expression, { document, location: { pathname } }) };
}

export async function runUnavailableSurfaceTests({ conversationUnavailablePresent }) {
  const cases = [
    ['genuine current conversation error is detected', el('body', {}, el('main', {}, el('div', { role: 'alert' }, message))), true],
    ['historical assistant quote is not current unavailability', el('body', {}, el('main', {}, el('div', { 'data-message-author-role': 'assistant' }, message))), false],
    ['historical user message is not current unavailability', el('body', {}, el('main', {}, el('div', { 'data-message-author-role': 'user' }, message))), false],
    ['virtualized turn without role marker is excluded', el('body', {}, el('main', {}, el('div', { 'data-turn-key': 'old-turn' }, message))), false],
    ['alternate conversation-role marker is excluded', el('body', {}, el('main', {}, el('div', { 'data-conversation-role': 'assistant' }, message))), false],
    ['conversation-turn testid is excluded', el('body', {}, el('main', {}, el('div', { 'data-testid': 'conversation-turn-12' }, message))), false],
    ['article transcript is excluded', el('body', {}, el('main', {}, el('article', {}, message))), false],
    ['sidebar failure is not this conversation failure', el('body', {}, el('aside', {}, message), el('main', {}, 'Healthy conversation')), false],
    ['floating chat failure outside main is excluded', el('body', {}, el('main', {}, 'Healthy conversation'), el('div', { role: 'dialog' }, message)), false],
    ['quoted code is not an application error', el('body', {}, el('main', {}, el('pre', {}, el('code', {}, message)))), false],
    ['composer draft is not an application error', el('body', {}, el('main', {}, el('div', { contenteditable: 'true', role: 'textbox' }, message))), false],
    ['hidden stale error is ignored', el('body', {}, el('main', {}, el('div', { hidden: '' }, message))), false],
    ['aria-hidden stale error is ignored', el('body', {}, el('main', {}, el('div', { 'aria-hidden': 'true' }, message))), false],
    ['history does not hide a separate current error', el('body', {}, el('main', {}, el('article', {}, 'Old response'), el('div', { role: 'alert' }, message))), true],
    ['missing main fails closed instead of scanning other chats', el('body', {}, message), false],
    ['fragmented error markup is recognized', el('body', {}, el('main', {}, 'Could not load', el('strong', {}, 'this ChatGPT'), 'conversation.')), true],
  ];
  const failures = [];
  for (const [name, body, expected] of cases) {
    try {
      const before = body.textContent;
      assert.equal(await conversationUnavailablePresent(browserFixture(body)), expected, name);
      assert.equal(body.textContent, before, 'detector must not modify the live fixture tree');
      console.log(`UNAVAILABLE_SURFACE PASS ${name}`);
    } catch (error) { failures.push(name); console.error(`UNAVAILABLE_SURFACE FAIL ${name}: ${error.message}`); }
  }
  assert.equal(await conversationUnavailablePresent(browserFixture(el('body', {}, el('main', {}, message)), '/uc/fixture-thread-id')), true);
  assert.equal(await conversationUnavailablePresent(browserFixture(el('body', {}, el('main', {}, message)), '/settings')), false);
  assert.equal(failures.length, 0, failures.join('; '));
  console.log('UNAVAILABLE_SURFACE_TEST=PASS');
}
if (import.meta.url === `file://${process.argv[1]}`) {
  await runUnavailableSurfaceTests(await import('../scripts/chatgpt-continuity/chatgpt-continuity-bridge-worker.mjs'));
}
