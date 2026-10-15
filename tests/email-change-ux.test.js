const { match, doesNotMatch } = require('./source-assert');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'public', 'assets', 'js', 'profile.js'), 'utf8');
match(source, /\brender\(\);\s*$/);

const elements = new Map();
let responseBody = null;
let responseOk = false;
let clock = 0;
let timerTick = null;
const context = vm.createContext({
  $: selector => elements.get(selector) || null,
  window: { location: { href: 'http://adoptapet.test/' }, addEventListener() {} },
  state: { user: { email: 'old@example.test' } },
  URL,
  Date: { now: () => clock },
  setTimeout: () => 1,
  setInterval: callback => { timerTick = callback; return 1; },
  clearInterval: () => { timerTick = null; },
  fetch: async () => ({ ok: responseOk, json: async () => responseBody }),
  setButtonBusy: (button, busy) => { button.busy = busy; return true; },
  toast() {},
  esc: value => String(value),
  passwordInput: () => '',
  emailInput: () => '',
});
vm.runInContext(source.replace(/\brender\(\);\s*$/, ''), context);

async function run() {
  const password = { value: 'test-input-only', focused: false, focus() { this.focused = true; } };
  const newEmail = { value: 'next@example.test' };
  const message = { textContent: '' };
  const submit = { isConnected: true };
  elements.set('#email-change-password', password);
  elements.set('#email-change-new-email', newEmail);
  elements.set('#email-change-message', message);
  elements.set('#email-change-start', submit);
  responseBody = { success: false, code: 'current_password_incorrect', message: 'Current password is incorrect.' };
  await vm.runInContext('startEmailChange({ preventDefault() {} })', context);
  assert.equal(message.textContent, 'Current password is incorrect.');
  assert.equal(newEmail.value, 'next@example.test');
  assert.equal(password.value, '');
  assert.equal(password.focused, true);
  assert.equal(submit.busy, false);

  const verificationCode = { value: '000000' };
  const verify = { isConnected: true };
  elements.set('#email-change-code', verificationCode);
  elements.set('#email-change-verify', verify);
  responseBody = { success: false, message: 'Invalid verification code. 4 attempts remaining.', attempts_remaining: 4 };
  await vm.runInContext('verifyEmailChange({ preventDefault() {} })', context);
  assert.equal(message.textContent, 'Invalid verification code. 4 attempts remaining.');
  assert.equal(verificationCode.value, '');

  const resend = {
    isConnected: true,
    disabled: false,
    textContent: '',
    getAttribute: () => null,
  };
  elements.set('#email-change-resend', resend);
  vm.runInContext('startEmailChangeResendTimer(60)', context);
  assert.equal(resend.disabled, true);
  assert.equal(resend.textContent, 'Resend code in 60s');
  clock = 50000;
  timerTick();
  assert.equal(resend.textContent, 'Resend code in 10s');
  clock = 60000;
  timerTick();
  assert.equal(resend.disabled, false);
  assert.equal(resend.textContent, 'Resend code');

  const display = { value: 'old@example.test' };
  const emailSummary = { textContent: 'old@example.test' };
  context.document = { querySelectorAll: selector => selector === '.profile-email-value' ? [display] : [{ querySelectorAll: () => [{ textContent: 'Casey' }, emailSummary] }] };
  context.updateCurrentUserProfile = data => { context.state.user.email = data.email; };
  context.closeEmailChangeModal = () => {};
  vm.runInContext("authenticatedProfile={email:'old@example.test'}", context);
  responseOk = true;
  responseBody = { success: true, email: 'verified@example.test', csrf_token: 'rotated-token', message: 'Verified.' };
  verificationCode.value = '123456';
  await vm.runInContext('verifyEmailChange({ preventDefault() {} })', context);
  assert.equal(vm.runInContext('authenticatedProfile.email', context), 'verified@example.test');
  assert.equal(context.state.user.email, 'verified@example.test');
  assert.equal(context.state.csrfToken, 'rotated-token');
  assert.equal(display.value, 'verified@example.test');
  assert.equal(emailSummary.textContent, 'verified@example.test');

  console.log('Change Email UI focus, preservation, cooldown and verified-email state refresh checks passed.');
}

run().catch(error => { console.error(error); process.exitCode = 1; });
