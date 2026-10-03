import assert from 'node:assert/strict';
import fs from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = fs.readFileSync(new URL('../assets/checkout.js', import.meta.url), 'utf8');

function checkout() {
  const listeners = {};
  const destinations = [];
  const window = {
    PlandalfMepr: {
      memberships: { 9: { thankYouUrl: 'http://wp.test/thank-you/?membership_id=9' } },
    },
    location: {
      href: 'http://wp.test/register/gold-monthly/',
      assign: (url) => destinations.push(url),
    },
  };
  const document = {
    addEventListener: (name, handler) => { listeners[name] = handler; },
  };
  vm.runInNewContext(source, { window, document, URL });
  return { listeners, destinations };
}

function event(invoice, membershipId = '9') {
  return {
    target: { closest: () => ({ getAttribute: () => membershipId }) },
    detail: { invoice, session: { completed_invoices: [invoice] } },
  };
}

test('paid purchase immediately redirects to MemberPress with invoice reference', () => {
  const { listeners, destinations } = checkout();
  const invoice = { invoice_number: 'RCP-123', ulid: '01TESTINVOICE' };
  listeners['plandalf:purchase'](event(invoice));

  assert.equal(destinations.length, 1);
  const destination = new URL(destinations[0]);
  assert.equal(destination.pathname, '/thank-you/');
  assert.equal(destination.searchParams.get('plandalf_invoice'), 'RCP-123');
  assert.equal(destination.searchParams.get('trans_num'), 'RCP-123');
  assert.equal(destination.searchParams.get('plandalf_ref'), '01TESTINVOICE');
});

test('ignores unrelated and incomplete purchases and redirects only once', () => {
  const { listeners, destinations } = checkout();
  const invoice = { invoice_number: 'RCP-123', ulid: '01TESTINVOICE' };
  listeners['plandalf:purchase'](event(invoice, '10'));
  listeners['plandalf:purchase'](event({ invoice_number: 'RCP-123' }));
  assert.equal(destinations.length, 0);

  listeners['plandalf:purchase'](event(invoice));
  listeners['plandalf:complete'](event(invoice));
  assert.equal(destinations.length, 1);
});

function activation(pendingPage = false) {
  const destinations = [], timers = [], requests = [], replies = [];
  let now = 0;
  function node(tag) {
    return {tag, style: {}, hidden: false, children: [], handlers: {}, textContent: '',
      setAttribute() {}, appendChild(child) {this.children.push(child);},
      addEventListener(name, callback) {this.handlers[name] = callback;}, remove() {this.removed = true;}};
  }
  const body = node('body');
  const window = {
    PlandalfMepr: {waitingFor: 'RCP-123', ref: 'PRIVATE_TEST_REFERENCE', statusUrl: 'http://wp.test/status', i18n: {activating: 'Activating', ready: 'Active', setPassword: 'Choose password', slow: 'Activation delayed', retry: 'Check membership status'}},
    location: {href: 'http://wp.test/thank-you/?plandalf_ref=PRIVATE_TEST_REFERENCE', assign: url => destinations.push(url), reload: () => destinations.push('reload')},
    history: {replaceState(_a, _b, url) {this.cleanUrl = url;}},
  };
  vm.runInNewContext(source, {window, document: {body, createElement: node, addEventListener() {}, querySelector: selector => pendingPage && selector === '.plandalf-mepr-pending' ? {} : null}, URL,
    Date: {now: () => now}, setTimeout: (callback, delay) => timers.push({callback, delay}),
    fetch: url => { requests.push(url); return new Promise(resolve => replies.push(value => resolve({json: () => Promise.resolve(value)}))); },
  });
  return {window, banner: body.children[0], requests, replies, timers, destinations, advance: ms => {now += ms;}};
}
const flush = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };

test('delayed activation can retry without losing the private reference or submitting another payment', async () => {
  const run = activation();
  run.advance(31000); run.replies.shift()({}); await flush();
  assert.equal(run.banner.textContent, 'Activation delayed');
  const retry = run.banner.children[0];
  assert.equal(retry.textContent, 'Check membership status');
  assert.equal(retry.hidden, false);
  retry.handlers.click(); retry.handlers.click();
  assert.equal(run.requests.length, 2, 'only one status request while a retry is pending');
  assert.equal(new URL(run.requests[1]).searchParams.get('ref'), 'PRIVATE_TEST_REFERENCE');
  assert.equal(new URL(run.window.history.cleanUrl).searchParams.has('plandalf_ref'), false);
  run.replies.shift()({set_password_url: 'http://wp.test/set-password/test-fixture'}); await flush();
  assert.equal(run.banner.textContent, 'Choose password');
  run.timers.find(timer => timer.delay === 1200).callback();
  assert.deepEqual(run.destinations, ['http://wp.test/set-password/test-fixture']);
});

test('retry resumes polling and confirms an existing member once the delayed event is applied', async () => {
  const run = activation();
  run.advance(31000); run.replies.shift()({}); await flush();
  run.banner.children[0].handlers.click();
  run.replies.shift()({}); await flush();
  const poll = run.timers.find(timer => timer.delay === 1500);
  assert.ok(poll); poll.callback();
  run.replies.shift()({applied: true}); await flush();
  assert.equal(run.banner.textContent, 'Active');
  assert.equal(run.destinations.length, 0);
});


test('applied event refreshes a server-rendered pending page instead of leaving the activation message', async () => {
  const run = activation(true);
  run.replies.shift()({applied: true}); await flush();
  assert.equal(run.banner.textContent, 'Active');
  assert.equal(run.destinations.length, 0, 'show confirmation before refreshing');
  const refresh = run.timers.find(timer => timer.delay === 1200);
  assert.ok(refresh, 'refresh the pending page after confirming activation');
  refresh.callback();
  assert.deepEqual(run.destinations, ['reload']);
});

test('an already-rendered receipt does not reload repeatedly', async () => {
  const run = activation(false);
  run.replies.shift()({applied: true}); await flush();
  for (const timer of run.timers) timer.callback();
  assert.deepEqual(run.destinations, []);
  assert.equal(run.banner.removed, true);
});

test('new-member password handoff takes precedence over refreshing the pending page', async () => {
  const run = activation(true);
  run.replies.shift()({applied: true, set_password_url: 'http://wp.test/set-password/test-fixture'}); await flush();
  run.timers.find(timer => timer.delay === 1200).callback();
  assert.deepEqual(run.destinations, ['http://wp.test/set-password/test-fixture']);
});
