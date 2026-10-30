'use strict';
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const source = fs.readFileSync(require('path').join(__dirname, '../js/sms-credits.js'), 'utf8');
let checks = 0;
async function scenario(admin, failure = false) {
  const ids = ['smsCredits', 'smsCreditsRefresh', 'smsCreditsStatus', 'smsCreditsBalance', 'smsCreditsUpdated'];
  const elements = Object.fromEntries(ids.map(id => [id, { hidden: true, textContent: '', handlers: {}, addEventListener(event, fn) { this.handlers[event] = fn; } }]));
  let requests = [];
  const fetch = async (url, options) => {
    requests.push({ url, options });
    if (url.includes('sms-settings')) return { ok: true, json: async () => ({ can_manage: admin, csrfToken: 'mock-csrf' }) };
    return { ok: !failure, json: async () => ({ balance: '12.50', updated_at: 1000, message: 'Balance loaded from Semaphore. No SMS was sent.' }) };
  };
  vm.runInNewContext(source, { document: { getElementById: id => elements[id] }, fetch, Date });
  await new Promise(resolve => setImmediate(resolve));
  assert.strictEqual(elements.smsCredits.hidden, !admin); checks++;
  assert.strictEqual(requests.length, 1); checks++;
  elements.smsCredits.open = true;
  elements.smsCredits.handlers.toggle();
  elements.smsCredits.handlers.toggle();
  await new Promise(resolve => setImmediate(resolve));
  assert.strictEqual(requests.length, admin ? 2 : 1); checks++;
  if (admin) {
    assert.strictEqual(requests[1].options.headers['X-CSRF-Token'], 'mock-csrf'); checks++;
    assert.strictEqual(elements.smsCreditsRefresh.disabled, false); checks++;
    assert.strictEqual(elements.smsCreditsBalance.textContent, failure ? '' : '12.50'); checks++;
    if (!failure) { assert.strictEqual(elements.smsCreditsStatus.textContent, 'Balance up to date. Refresh is available once a minute.'); checks++; elements.smsCredits.handlers.toggle(); assert.strictEqual(requests.length, 2); checks++; }
  }
}
(async () => {
  await scenario(false); await scenario(true); await scenario(true, true);
  assert(!source.includes('innerHTML') && !source.includes('apikey')); checks++;
  console.log(`${checks} Semaphore credits JavaScript checks passed.`);
})().catch(error => { console.error(error); process.exit(1); });
