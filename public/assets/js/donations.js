let donationDraft = null;
let donationSubmitting = false;
let donationPaymentLocked = false;
let donationTransitionTimer = null;
let donationResendTimer = null;
let donationResendAvailableAt = 0;
let donationProviderIdentity = null;
let donationBoardResult = null;
let donationBoardPage = 1;
const donationBoardPageSize = 10;

// Donation board and simulated payment flow
function databaseDonationsPage() {
  setExternalProviderMode();
  clearDonationTransition();
  clearDonationResendTimer();
  setTimeout(loadDonationBoard, 0);
  return shell(
    `<div class="donation-board-ux"><header class="standard-page-heading donation-page-heading"><h1>Donation Board</h1><p>Every completed contribution helps provide food, veterinary care, and safe shelter for animals waiting for a home.</p></header><div id="donation-board" class="panel empty">Loading Donation records…</div></div>`,
  );
}

function donationBoardContent(result) {
  let page = paginatedItems(
    result.donations || [],
    donationBoardPage,
    donationBoardPageSize,
  );
  donationBoardPage = page.page;
  let contributions = page.items.length
      ? page.items
          .map(
            (donation) =>
              `<li><div class="contribution-avatar" aria-hidden="true">♥</div><div><b>${esc(donation.donor_display_name)}</b><small>${date(donation.donated_at)}</small></div><strong>${money(donation.amount)}</strong></li>`,
          )
          .join('')
      : '<li class="empty">No completed Donations have been recorded yet.</li>',
    range = page.total
      ? `Showing ${page.start + 1}–${page.end} of ${page.total} contributions`
      : 'No contributions to show';
  return `<section class="donation-public-summary" aria-labelledby="community-contributions-heading"><div class="donation-summary-copy"><span class="eyebrow" id="community-contributions-heading">Community Contributions</span>${donationTotalMarkup(result.summary.donation_total, result.summary.currency)}<p>${Number(result.summary.contribution_count) || 0} completed contribution${Number(result.summary.contribution_count) === 1 ? '' : 's'} recorded.</p></div><a class="btn donation-summary-action" href="#/donate">Make a Donation</a></section><section class="section" id="donation-contributions"><div class="section-head contribution-section-head"><div><h2>Recent Contributions</h2><p class="muted">Public display names only; private account and transaction details are never shown.</p></div><span class="muted pagination-summary" aria-live="polite">${range}</span></div><ul class="contribution-list">${contributions}</ul>${paginationMarkup(page.page, page.totalPages, 'changeDonationBoardPage', 'Donation Board pages')}</section>`;
}

function donationTotalMarkup(amount, currency = 'MYR') {
  let formatter = new Intl.NumberFormat('en-MY', {
      style: 'currency',
      currency: currency || 'MYR',
    }),
    parts = formatter.formatToParts(Number(amount) || 0),
    currencyText =
      parts.find((part) => part.type === 'currency')?.value || 'RM',
    numberText = parts
      .filter((part) => !['currency', 'literal'].includes(part.type))
      .map((part) => part.value)
      .join('');
  return `<strong class="donation-total-amount" aria-label="${esc(formatter.format(Number(amount) || 0))}"><span class="donation-total-currency">${esc(currencyText)}</span><span class="donation-total-value">${esc(numberText)}</span></strong>`;
}

function changeDonationBoardPage(page) {
  if (!donationBoardResult) return;
  let next = paginatedItems(
    donationBoardResult.donations || [],
    page,
    donationBoardPageSize,
  );
  if (next.page === donationBoardPage) return;
  donationBoardPage = next.page;
  let target = $('#donation-board');
  if (target) target.innerHTML = donationBoardContent(donationBoardResult);
  paginationScroll('#donation-contributions');
}

async function loadDonationBoard() {
  let target = $('#donation-board');
  if (!target) return;
  try {
    let response = await fetch(
        new URL('api/donations.php', window.location.href),
        { credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    donationBoardResult = result;
    donationBoardPage = 1;
    target.className = '';
    target.innerHTML = donationBoardContent(result);
  } catch (error) {
    target.className = 'panel empty error-state';
    target.innerHTML = `<h2>Unable to load the Donation Board</h2><p>${esc(error.message || 'Please try again.')}</p><button class="btn" onclick="loadDonationBoard()">Retry</button>`;
  }
}

/** Creates a fresh request key so a repeated submission can be recognized by the API. */
function newDonationRequestKey() {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID();
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(
    /[xy]/g,
    (character) => {
      let random = (Math.random() * 16) | 0,
        value = character === 'x' ? random : (random & 3) | 8;
      return value.toString(16);
    },
  );
}
function donationPaymentOptions(selected = 'card') {
  let methods = [
    ['card', 'Card', 'Pay securely by card', 'card'],
    ['online_banking', 'Online Banking', 'Authorise through your bank', 'coin'],
    ['e_wallet', 'E-Wallet', 'Pay with a Malaysian e-wallet', 'heart'],
  ];
  return methods
    .map(
      ([value, label, description, icon]) =>
        `<label class="demo-payment-option"><input type="radio" name="demo-payment-method" value="${value}" ${selected === value ? 'checked' : ''}><span class="payment-option-icon">${uiIcon(icon)}</span><span><b>${label}</b><small>${description}</small></span></label>`,
    )
    .join('');
}
function donationProgress(currentStep) {
  let current = Math.min(2, Math.max(1, Number(currentStep) || 1));
  return `<nav class="stray-form-progress donation-progress" aria-label="Donation progress"><ol><li class="${current === 1 ? 'is-current' : 'is-complete'}" ${current === 1 ? 'aria-current="step"' : ''}><span aria-hidden="true">${current === 1 ? '1' : ''}</span><b>Donation details</b></li><li class="${current === 2 ? 'is-current' : ''}" ${current === 2 ? 'aria-current="step"' : ''}><span aria-hidden="true">2</span><b>Review &amp; Payment</b></li></ol></nav>`;
}
function donationFlowContent(markup) {
  return `<div class="form-shell-content donation-form-content">${markup}</div>`;
}
function setDonationProgress(currentStep) {
  let target = $('#donation-progress');
  if (!target) return;
  target.hidden = !currentStep;
  target.innerHTML = currentStep ? donationProgress(currentStep) : '';
}
function setDonationPaymentSubflow(active) {
  let page = $('.donation-ux');
  if (page) page.classList.toggle('is-payment-subflow', Boolean(active));
}
function donationSuggestedAmounts(selectedAmount = '') {
  let descriptions = {
    10: 'Small support',
    25: 'Medical care',
    50: 'Shelter support',
    100: 'Larger contribution',
  };
  return [10, 25, 50, 100]
    .map((value) => {
      let selected = Number(selectedAmount) === value;
      return `<button type="button" class="suggested-amount-option${selected ? ' selected' : ''}" data-amount="${value}" aria-pressed="${selected ? 'true' : 'false'}" onclick="selectDonationAmount(${value})"><span class="amount-check">${uiIcon('check')}</span><strong>RM${value}</strong><small>${descriptions[value]}</small></button>`;
    })
    .join('');
}
function donationDetailsMarkup() {
  let draft = donationDraft || {},
    amount = draft.amount || '',
    name = draft.donorName || '',
    anonymous = Boolean(draft.isAnonymous),
    method = draft.paymentMethod || 'card';
  return `<section class="form-step"><header class="form-section-header donation-flow-heading"><h2>Donation details</h2><p>Choose an amount and payment method.</p></header><form onsubmit="reviewDonation(event)" novalidate><section class="form-section donation-details-section"><h3>Donation information</h3><div class="field"><label>Suggested amount</label><div class="suggested-amounts">${donationSuggestedAmounts(amount)}</div></div><div class="field donation-field-gap"><label for="donate-amount">Custom amount (MYR)</label><input id="donate-amount" type="number" min="1" max="99999999.99" step="0.01" class="input" value="${esc(amount)}" placeholder="e.g. 50.00" oninput="syncDonationSuggestedAmount(this.value)" required></div><div class="field donation-field-gap"><label for="donate-name">Display name</label><input id="donate-name" class="input" maxlength="150" value="${esc(name)}" placeholder="Your name" required></div><div class="field donation-field-gap"><label class="donation-checkbox"><input id="donate-anonymous" type="checkbox" ${anonymous ? 'checked' : ''}> <span>Show my donation publicly as Anonymous Supporter</span></label></div></section><fieldset class="form-section demo-payment-methods"><legend>Payment method</legend>${donationPaymentOptions(method)}</fieldset><div id="donation-validation" class="validation" role="alert" aria-live="polite"></div><div class="page-actions form-actions donation-actions"><a class="btn outline" href="#/donations">Cancel</a><button class="btn" type="submit">Review donation</button></div></form></section>`;
}
function databaseDonatePage() {
  setExternalProviderMode();
  clearDonationTransition();
  clearDonationResendTimer();
  donationDraft = {
    amount: '',
    donorName: '',
    isAnonymous: false,
    paymentMethod: 'card',
    idempotencyKey: newDonationRequestKey(),
  };
  donationSubmitting = false;
  donationPaymentLocked = false;
  donationProviderIdentity = null;
  setTimeout(loadDonationContributorName, 0);
  return shell(
    `<div class="donation-ux"><header class="standard-page-heading donation-page-heading"><h1>Make a Donation</h1><p>Support food, medical care, and safe shelter for animals waiting for a home.</p></header><div id="donation-progress">${donationProgress(1)}</div><div id="donation-flow" class="panel form-shell donation-flow">${donationFlowContent(donationDetailsMarkup())}</div></div>`,
  );
}
function setDonationValidation(message) {
  let target = $('#donation-validation');
  if (target) target.textContent = message || '';
}
function donationScroll() {
  let target = $('#donation-flow');
  if (target)
    target.scrollIntoView({
      behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
        ? 'auto'
        : 'smooth',
      block: 'start',
    });
}
function donationDemoReference() {
  return `PAY-${String(donationDraft?.idempotencyKey || 'PENDING')
    .slice(0, 8)
    .toUpperCase()}`;
}
function setExternalProviderMode(provider = '') {
  document.body.classList.toggle('bank-provider-active', provider === 'bank');
  document.body.classList.toggle(
    'wallet-provider-active',
    provider === 'wallet',
  );
  document.body.classList.toggle(
    'payment-provider-fullscreen',
    provider === 'bank' || provider === 'wallet',
  );
}
function setBankProviderMode(active) {
  setExternalProviderMode(active ? 'bank' : '');
}
function setPaymentTransitionMode(active) {
  document.body.classList.toggle('payment-transition-active', Boolean(active));
}
function clearDonationTransition() {
  if (donationTransitionTimer) {
    clearTimeout(donationTransitionTimer);
    donationTransitionTimer = null;
  }
}
function scheduleDonationTransition(callback, delay) {
  clearDonationTransition();
  donationTransitionTimer = setTimeout(() => {
    donationTransitionTimer = null;
    callback();
  }, delay);
}
function clearDonationResendTimer() {
  if (donationResendTimer) {
    clearInterval(donationResendTimer);
    donationResendTimer = null;
  }
}
function syncDonationSuggestedAmount(amount) {
  document.querySelectorAll('.suggested-amount-option').forEach((option) => {
    let selected =
      Number(option.dataset.amount) === Number(amount) &&
      String(amount).trim() !== '';
    option.classList.toggle('selected', selected);
    option.setAttribute('aria-pressed', selected ? 'true' : 'false');
  });
}
function selectDonationAmount(amount) {
  let input = $('#donate-amount');
  if (input) {
    input.value = Number(amount).toFixed(2);
    syncDonationSuggestedAmount(input.value);
    input.focus();
  }
}
function reviewDonation(event) {
  event?.preventDefault();
  let amount = $('#donate-amount')?.value.trim() || '',
    donorName = $('#donate-name')?.value.trim() || '',
    isAnonymous = Boolean($('#donate-anonymous')?.checked),
    paymentMethod =
      document.querySelector('input[name="demo-payment-method"]:checked')
        ?.value || '';
  if (
    !/^\d{1,8}(?:\.\d{1,2})?$/.test(amount) ||
    Number(amount) < 1 ||
    Number(amount) > 99999999.99
  )
    return setDonationValidation(
      'Enter an amount from MYR 1.00 to MYR 99,999,999.99 using no more than two decimal places.',
    );
  if (!donorName || donorName.length > 150)
    return setDonationValidation(
      'Enter a display name of up to 150 characters.',
    );
  let labels = {
    card: 'Card',
    online_banking: 'Online Banking',
    e_wallet: 'E-Wallet',
  };
  if (!labels[paymentMethod])
    return setDonationValidation('Select a payment method.');
  donationDraft = {
    ...donationDraft,
    amount: Number(amount).toFixed(2),
    donorName,
    isAnonymous,
    paymentMethod,
    paymentMethodLabel: labels[paymentMethod],
    providerDisplayLabel: '',
  };
  renderDonationReview();
  donationScroll();
}
function renderDonationReview(message = '') {
  setExternalProviderMode();
  setPaymentTransitionMode(false);
  setDonationPaymentSubflow(false);
  setDonationProgress(2);
  clearDonationTransition();
  clearDonationResendTimer();
  let target = $('#donation-flow');
  if (!target || !donationDraft) return;
  target.innerHTML = donationFlowContent(
    `<section class="form-step"><header class="form-section-header donation-flow-heading"><h2>Review donation</h2><p>Your donation is recorded only after payment authorisation succeeds.</p></header><dl class="donation-review"><div><dt>Amount</dt><dd>${money(donationDraft.amount)}</dd></div><div><dt>Payment method</dt><dd>${esc(donationDraft.paymentMethodLabel)}</dd></div><div><dt>Public donor name</dt><dd>${esc(donationDraft.isAnonymous ? 'Anonymous Supporter' : donationDraft.donorName)}</dd></div></dl>${message ? `<p class="notice info donation-return-message">${esc(message)}</p>` : ''}<div id="donation-validation" class="validation" role="alert" aria-live="polite"></div><div class="page-actions form-actions donation-actions"><button class="btn outline" type="button" onclick="editDonationDetails()">Back</button><button id="donate-provider" class="btn" type="button" onclick="openDonationProvider()">Continue to Payment</button></div></section>`,
  );
}
function editDonationDetails() {
  setExternalProviderMode();
  setPaymentTransitionMode(false);
  setDonationPaymentSubflow(false);
  setDonationProgress(1);
  clearDonationTransition();
  clearDonationResendTimer();
  let target = $('#donation-flow');
  if (target) {
    target.innerHTML = donationFlowContent(donationDetailsMarkup());
    donationScroll();
  }
}
function clearDonationSensitiveInputs() {
  [
    '#demo-cardholder',
    '#demo-card-number',
    '#demo-card-expiry',
    '#demo-card-cvv',
    '#bank-login-username',
    '#bank-login-password',
    '#wallet-login-phone',
    '#payment-verification-code',
    '#pawpay-verification-code',
  ].forEach((selector) => {
    let input = $(selector);
    if (input) input.value = '';
  });
}
function clearFreshProviderFields(selectors, focusSelector = '') {
  setTimeout(() => {
    selectors.forEach((selector) => {
      let input = $(selector);
      if (input) input.value = '';
    });
    let focus = $(focusSelector);
    if (focus) focus.focus();
  }, 80);
}
function invalidatePaymentVerification() {
  if (
    !donationDraft ||
    !['online_banking', 'e_wallet'].includes(donationDraft.paymentMethod)
  )
    return;
  fetch(new URL('api/payment-verification.php', window.location.href), {
    method: 'POST',
    credentials: 'same-origin',
    keepalive: true,
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: 'cancel',
      context: donationDraft.paymentMethod,
      idempotency_key: donationDraft.idempotencyKey,
    }),
  }).catch(() => {});
}
function cancelDonationPayment(
  message = 'Payment cancelled. No donation was recorded.',
) {
  clearDonationSensitiveInputs();
  clearDonationTransition();
  clearDonationResendTimer();
  invalidatePaymentVerification();
  setExternalProviderMode();
  setPaymentTransitionMode(false);
  donationPaymentLocked = false;
  if (donationDraft) donationDraft.providerDisplayLabel = '';
  renderDonationReview(message);
  donationScroll();
}
function rejectDonationPayment() {
  cancelDonationPayment('Payment was not completed. No donation was recorded.');
}

function providerHeader(title, description) {
  return `<header class="form-section-header donation-flow-heading provider-heading"><h2>${esc(title)}</h2><p>${esc(description)}</p></header>`;
}
function providerSummary(extra = '') {
  return `<dl class="provider-summary"><div><dt>Merchant</dt><dd>AdoptAPet</dd></div><div><dt>Amount</dt><dd>${money(donationDraft.amount)}</dd></div>${extra}</dl>`;
}
function providerActions(
  primary,
  back = 'renderDonationReview();donationScroll()',
) {
  return `<div class="page-actions form-actions donation-actions provider-actions"><button class="btn outline" type="button" onclick="${back}">Back</button><button class="btn outline provider-cancel" type="button" onclick="cancelDonationPayment()">Cancel</button>${primary}</div>`;
}
async function ensureDonationProviderIdentity(refresh = false) {
  if (donationProviderIdentity && !refresh) return donationProviderIdentity;
  let response = await fetch(
      new URL('api/payment-verification.php', window.location.href),
      { credentials: 'same-origin' },
    ),
    result = await response.json();
  if (!response.ok || !result.success)
    throw new Error(result.message || 'Unable to load your payment identity.');
  donationProviderIdentity = result.identity;
  return donationProviderIdentity;
}
function paymentProviderError(error) {
  donationPaymentLocked = false;
  setDonationValidation(
    error?.message || 'Unable to open the payment provider. Please try again.',
  );
}
async function openDonationProvider() {
  if (!donationDraft || donationPaymentLocked) return;
  if (donationDraft.paymentMethod === 'card') return renderDemoCardProvider();
  if (donationDraft.paymentMethod === 'online_banking')
    return renderDemoBankSelection();
  renderDemoWalletSelection();
}

function renderDemoCardProvider() {
  setBankProviderMode(false);
  setDonationPaymentSubflow(true);
  let target = $('#donation-flow');
  if (!target) return;
  target.innerHTML = donationFlowContent(
    `<section class="form-step payment-provider">${providerHeader('Card Payment', 'Enter your card details to continue.')}${providerSummary()}<form onsubmit="confirmDemoCard(event)" autocomplete="off" novalidate><div class="provider-form-grid"><div class="field full"><label for="demo-cardholder">Cardholder Name</label><input id="demo-cardholder" name="payment_cardholder" class="input" autocomplete="cc-name" maxlength="40" placeholder="Name on card"></div><div class="field full"><label for="demo-card-number">Card Number</label><input id="demo-card-number" name="payment_card_number" class="input" type="text" inputmode="numeric" autocomplete="cc-number" maxlength="19" placeholder="Enter card number"></div><div class="field"><label for="demo-card-expiry">Expiry Date</label><input id="demo-card-expiry" name="payment_card_expiry" class="input" type="text" inputmode="numeric" autocomplete="cc-exp" maxlength="5" placeholder="MM/YY"></div><div class="field"><label for="demo-card-cvv">CVV</label><input id="demo-card-cvv" name="payment_card_cvv" class="input" type="password" inputmode="numeric" autocomplete="cc-csc" maxlength="3" placeholder="3 digits"></div></div><div id="donation-validation" class="validation" role="alert" aria-live="polite"></div>${providerActions(`<button id="demo-card-pay" class="btn" type="submit">Pay ${money(donationDraft.amount)}</button>`)}</form></section>`,
  );
  donationScroll();
}
function confirmDemoCard(event) {
  event?.preventDefault();
  if (donationPaymentLocked) return;
  let cardholder = $('#demo-cardholder')?.value.trim() || '',
    number = ($('#demo-card-number')?.value || '').replace(/\s/g, ''),
    expiry = $('#demo-card-expiry')?.value.trim() || '',
    cvv = $('#demo-card-cvv')?.value || '';
  if (
    cardholder !== 'ADOPTAPET TEST' ||
    number !== '4242424242424242' ||
    expiry !== '12/30' ||
    cvv !== '123'
  )
    return setDonationValidation(
      'The card details could not be verified. Check each field and try again.',
    );
  clearDonationSensitiveInputs();
  donationDraft.providerDisplayLabel = 'Card';
  beginDonationProcessing('card');
}

const donationBanks = [
  'Maybank',
  'CIMB Bank',
  'Public Bank',
  'RHB Bank',
  'Hong Leong Bank',
  'Bank Islam',
  'AmBank',
];
function renderDemoBankSelection() {
  setBankProviderMode(false);
  setDonationPaymentSubflow(true);
  let target = $('#donation-flow');
  if (!target) return;
  let selected = donationBanks.includes(donationDraft.demoBank)
    ? donationDraft.demoBank
    : 'Maybank';
  target.innerHTML = donationFlowContent(
    `<section class="form-step payment-provider bank-selection">${providerHeader('Online Banking', 'Select a bank to continue.')}${providerSummary(`<div><dt>Reference</dt><dd>${esc(donationDemoReference())}</dd></div>`)}<fieldset class="provider-option-grid demo-bank-options"><legend>Select bank</legend>${donationBanks.map((bank) => `<label class="provider-selection-option demo-bank-option"><input type="radio" name="demo-bank" value="${esc(bank)}" ${selected === bank ? 'checked' : ''}><span class="provider-selection-card"><b>${esc(bank)}</b><small>Online banking</small></span></label>`).join('')}</fieldset><div id="donation-validation" class="validation" role="alert" aria-live="polite"></div>${providerActions('<button class="btn" type="button" onclick="continueDemoBank()">Continue to Bank</button>')}</section>`,
  );
  donationScroll();
}
async function continueDemoBank() {
  let bank =
    document.querySelector('input[name="demo-bank"]:checked')?.value || '';
  if (!donationBanks.includes(bank))
    return setDonationValidation('Select a bank.');
  if (donationPaymentLocked) return;
  donationDraft.demoBank = bank;
  donationPaymentLocked = true;
  setDonationValidation('');
  try {
    await ensureDonationProviderIdentity();
    donationPaymentLocked = false;
    renderBankRedirect();
  } catch (error) {
    paymentProviderError(error);
  }
}
function bankProviderKey() {
  return (
    {
      Maybank: 'maybank',
      'CIMB Bank': 'cimb',
      'Public Bank': 'public-bank',
      'RHB Bank': 'rhb',
      'Hong Leong Bank': 'hong-leong',
      'Bank Islam': 'bank-islam',
      AmBank: 'ambank',
    }[donationDraft?.demoBank] || 'maybank'
  );
}
function bankProviderShell(markup) {
  return `<section class="bank-provider-shell bank-provider-theme bank-theme-${bankProviderKey()}"><div class="bank-provider-card">${markup}</div></section>`;
}
function bankProviderHeader(title, description = '') {
  return `<header class="bank-provider-header"><span class="bank-provider-name">${esc(donationDraft?.demoBank || 'Online Banking')}</span><h1>${esc(title)}</h1>${description ? `<p>${esc(description)}</p>` : ''}</header>`;
}
function bankAccountPaymentFacts() {
  return `<section class="provider-detail-section"><h2>Account</h2><dl class="bank-provider-facts"><div><dt>Account</dt><dd>Savings Account<br>•••• 4821</dd></div><div><dt>Available balance</dt><dd>RM XX,XXX.XX</dd></div></dl></section><section class="provider-detail-section"><h2>Payment Details</h2><dl class="bank-provider-facts"><div><dt>Merchant</dt><dd>AdoptAPet</dd></div><div><dt>Donation amount</dt><dd>${money(donationDraft.amount)}</dd></div><div><dt>Reference</dt><dd>${esc(donationDemoReference())}</dd></div></dl></section>`;
}
function renderBankRedirect() {
  setExternalProviderMode('bank');
  let target = $('#donation-flow'),
    bank = donationDraft?.demoBank || 'Maybank';
  if (!target) return;
  target.innerHTML = bankProviderShell(
    `<div class="bank-transition" role="status" aria-live="polite"><div class="demo-payment-spinner bank-spinner" aria-hidden="true"></div><h1>Redirecting to ${esc(bank)}...</h1><p>Opening the payment provider.</p><button class="bank-link-button" type="button" onclick="cancelDonationPayment()">Cancel payment</button></div>`,
  );
  scrollTo(0, 0);
  scheduleDonationTransition(renderBankAccess, 850);
}
function renderBankAccess() {
  setExternalProviderMode('bank');
  let target = $('#donation-flow');
  if (!target) return;
  target.innerHTML = bankProviderShell(
    `${bankProviderHeader('Online Banking Login', 'Enter your AdoptAPet username and password to continue.')}<form class="bank-login-form" onsubmit="submitBankLogin(event)" autocomplete="off" novalidate><div class="field"><label for="bank-login-username">Username</label><input id="bank-login-username" name="fyp_bank_access_id" class="input" type="text" autocomplete="off" maxlength="20" autocapitalize="none" spellcheck="false" data-lpignore="true" data-1p-ignore="true" required></div><div class="field"><label for="bank-login-password">Password</label><div class="password-wrap"><input id="bank-login-password" name="fyp_bank_access_secret" class="input" type="password" autocomplete="new-password" data-lpignore="true" data-1p-ignore="true" required><button type="button" class="password-toggle" onclick="togglePassword('bank-login-password',this)" aria-label="Show password" title="Show password">${uiIcon('eye')}</button></div></div><div id="donation-validation" class="validation" role="alert" aria-live="polite"></div><div class="bank-provider-actions"><button class="bank-link-button" type="button" onclick="cancelDonationPayment()">Cancel</button><button id="bank-login-submit" class="bank-primary-button" type="submit">Log In</button></div></form>`,
  );
  scrollTo(0, 0);
  clearFreshProviderFields(
    ['#bank-login-username', '#bank-login-password'],
    '#bank-login-username',
  );
}
function renderBankPaymentApproval() {
  setExternalProviderMode('bank');
  let target = $('#donation-flow'),
    identity = donationProviderIdentity || {};
  if (!target) return;
  target.innerHTML = bankProviderShell(
    `${bankProviderHeader('Payment Authorisation', 'Review the account and payment details.')}<section class="provider-welcome"><p>Welcome,</p><strong>${esc(identity.name || donationDraft.donorName)}</strong></section>${bankAccountPaymentFacts()}<div id="donation-validation" class="validation" role="alert" aria-live="polite"></div><div class="bank-provider-actions"><button class="bank-secondary-button" type="button" onclick="rejectDonationPayment()">Reject Payment</button><button id="bank-approve-payment" class="bank-primary-button" type="button" onclick="requestBankVerification()">Approve Payment</button></div>`,
  );
  scrollTo(0, 0);
}

async function paymentVerificationRequest(action, context, details = '') {
  let body = { action, context, idempotency_key: donationDraft.idempotencyKey };
  if (action === 'verify') body.code = details;
  if (action === 'bank_login') {
    body.username = details.username;
    body.password = details.password;
  }
  let response = await fetch(
      new URL('api/payment-verification.php', window.location.href),
      {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      },
    ),
    result = await response.json();
  return { response, result };
}
function setPaymentButtonBusy(button, busy, label = 'Please wait…') {
  return setButtonBusy(button, busy, label);
}
function setVerificationCodeButtonBusy(button, busy) {
  if (button && busy)
    button.style.setProperty(
      '--verification-button-width',
      `${Math.ceil(button.getBoundingClientRect().width)}px`,
    );
  let changed = setPaymentButtonBusy(button, busy, busy ? 'Sending code' : '');
  if (button) {
    button.classList.toggle('verification-code-sending', busy);
    if (!busy) button.style.removeProperty('--verification-button-width');
  }
  return changed;
}
async function submitBankLogin(event) {
  event?.preventDefault();
  if (donationPaymentLocked) return;
  let usernameInput = $('#bank-login-username'),
    passwordInput = $('#bank-login-password'),
    username = usernameInput?.value.trim() || '',
    password = passwordInput?.value || '',
    button = $('#bank-login-submit');
  if (!username || !password)
    return setDonationValidation('Enter your username and password.');
  donationPaymentLocked = true;
  setDonationValidation('');
  setPaymentButtonBusy(button, true, 'Signing in…');
  try {
    let request = paymentVerificationRequest('bank_login', 'online_banking', {
      username,
      password,
    });
    if (passwordInput) passwordInput.value = '';
    password = '';
    let { response, result } = await request;
    if (!response.ok || !result.success) {
      setDonationValidation(
        result.message || 'Incorrect username or password.',
      );
      return;
    }
    if (donationProviderIdentity && result.full_name)
      donationProviderIdentity.name = result.full_name;
    clearDonationSensitiveInputs();
    donationPaymentLocked = false;
    renderBankPaymentApproval();
  } catch (error) {
    setDonationValidation('Unable to sign in. Please try again.');
  } finally {
    password = '';
    if (donationPaymentLocked) {
      donationPaymentLocked = false;
      if (button?.isConnected) setPaymentButtonBusy(button, false);
    }
  }
}
async function sendDonationVerificationCode(context, buttonId) {
  if (donationPaymentLocked) return null;
  let button = $(buttonId);
  donationPaymentLocked = true;
  setDonationValidation('');
  setVerificationCodeButtonBusy(button, true);
  try {
    let { response, result } = await paymentVerificationRequest(
      'send',
      context,
    );
    if (!response.ok || !result.success) {
      if (result.cooldown_seconds)
        startDonationResendCountdown(context, result.cooldown_seconds);
      setDonationValidation(
        result.message ||
          'The verification email could not be sent. Please try again.',
      );
      return null;
    }
    donationResendAvailableAt =
      Date.now() + (Number(result.resend_after_seconds) || 60) * 1000;
    return result;
  } catch (error) {
    setDonationValidation(
      'The verification email could not be sent. Please try again.',
    );
    return null;
  } finally {
    donationPaymentLocked = false;
    setVerificationCodeButtonBusy(button, false);
  }
}
async function requestBankVerification() {
  let result = await sendDonationVerificationCode(
    'online_banking',
    '#bank-approve-payment',
  );
  if (result)
    renderBankVerification(result.masked_email, result.resend_after_seconds);
}
function verificationActions(context, submitLabel) {
  let wallet = context === 'e_wallet',
    container = wallet
      ? 'bank-provider-actions wallet-provider-actions'
      : 'bank-provider-actions',
    link = wallet ? 'bank-link-button wallet-link-button' : 'bank-link-button',
    secondary = wallet
      ? 'bank-secondary-button wallet-secondary-button'
      : 'bank-secondary-button',
    primary = wallet
      ? 'bank-primary-button wallet-primary-button'
      : 'bank-primary-button';
  return `<div class="${container}"><button class="${link}" type="button" onclick="cancelDonationPayment()">Cancel</button><button id="${context}-resend-code" class="${secondary}" type="button" onclick="resendDonationVerification('${context}')">Resend Code</button><button id="${context}-verify-code" class="${primary}" type="submit">${esc(submitLabel)}</button></div>`;
}
function renderBankVerification(
  maskedEmail = donationProviderIdentity?.masked_email ||
    'your registered email',
) {
  setExternalProviderMode('bank');
  let target = $('#donation-flow');
  if (!target) return;
  target.innerHTML = bankProviderShell(
    `${bankProviderHeader('Verify Transaction', 'Enter the six-digit code sent to your registered email.')}<p class="provider-email-destination">We sent a verification code to:<strong>${esc(maskedEmail)}</strong></p><form class="bank-verification-form" onsubmit="verifyDonationPaymentCode(event,'online_banking')" novalidate><div class="field"><label for="payment-verification-code">Verification Code</label><input id="payment-verification-code" class="input code-input" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="6-digit code"></div><div id="resend-status" class="provider-status" role="status" aria-live="polite"></div><div id="donation-validation" class="validation" role="alert" aria-live="polite"></div>${verificationActions('online_banking', 'Verify & Continue')}</form>`,
  );
  scrollTo(0, 0);
  startDonationResendCountdown('online_banking');
}
function startDonationResendCountdown(context, seconds = null) {
  clearDonationResendTimer();
  if (seconds !== null)
    donationResendAvailableAt = Date.now() + Number(seconds) * 1000;
  let button = $(`#${context}-resend-code`),
    status = $('#resend-status');
  if (!button) return;
  let update = () => {
    let remaining = Math.max(
      0,
      Math.ceil((donationResendAvailableAt - Date.now()) / 1000),
    );
    button.disabled = remaining > 0;
    button.textContent =
      remaining > 0 ? `Resend Code (${remaining}s)` : 'Resend Code';
    if (status)
      status.textContent =
        remaining > 0 ? 'You can request another code shortly.' : '';
    if (remaining <= 0) clearDonationResendTimer();
  };
  update();
  if (button.disabled) donationResendTimer = setInterval(update, 1000);
}
async function resendDonationVerification(context) {
  let result = await sendDonationVerificationCode(
    context,
    `#${context}-resend-code`,
  );
  if (!result) return;
  let status = $('#resend-status');
  if (status) status.textContent = 'A new verification code was sent.';
  donationResendAvailableAt =
    Date.now() + (Number(result.resend_after_seconds) || 60) * 1000;
  startDonationResendCountdown(context);
}
async function verifyDonationPaymentCode(event, context) {
  event?.preventDefault();
  if (donationPaymentLocked) return;
  let input = $(
      context === 'e_wallet'
        ? '#pawpay-verification-code'
        : '#payment-verification-code',
    ),
    code = input?.value.trim() || '',
    button = $(`#${context}-verify-code`),
    verified = false;
  if (!/^\d{6}$/.test(code))
    return setDonationValidation('Incorrect or expired verification code.');
  donationPaymentLocked = true;
  setDonationValidation('');
  setPaymentButtonBusy(button, true, 'Verifying…');
  try {
    let { response, result } = await paymentVerificationRequest(
      'verify',
      context,
      code,
    );
    if (!response.ok || !result.success) {
      if (input) input.value = '';
      setDonationValidation(
        result.message || 'Incorrect or expired verification code.',
      );
      return;
    }
    verified = true;
    clearDonationSensitiveInputs();
    clearDonationResendTimer();
    donationPaymentLocked = false;
    if (context === 'online_banking') {
      donationDraft.providerDisplayLabel = `Online Banking — ${donationDraft.demoBank || 'Maybank'}`;
      beginDonationProcessing('bank');
    } else renderWalletPaymentApproval();
  } catch (error) {
    setDonationValidation('Unable to verify the code. Please try again.');
  } finally {
    if (!verified) {
      donationPaymentLocked = false;
      setPaymentButtonBusy(button, false);
    }
  }
}

const donationWallets = [
  ["Touch 'n Go eWallet", 'tng'],
  ['Boost', 'boost'],
  ['GrabPay', 'grab'],
  ['MAE Wallet', 'mae'],
  ['ShopeePay', 'shopee'],
];
function walletProviderName() {
  return donationWallets.some(([name]) => name === donationDraft?.demoWallet)
    ? donationDraft.demoWallet
    : "Touch 'n Go eWallet";
}
function walletProviderKey() {
  return (
    donationWallets.find(([name]) => name === walletProviderName())?.[1] ||
    'tng'
  );
}
function renderDemoWalletSelection() {
  setExternalProviderMode();
  setDonationPaymentSubflow(true);
  let target = $('#donation-flow');
  if (!target) return;
  let selected = walletProviderName();
  target.innerHTML = donationFlowContent(
    `<section class="form-step payment-provider wallet-selection">${providerHeader('E-Wallet', 'Select an e-wallet provider to continue.')}${providerSummary()}<fieldset class="provider-option-grid demo-wallet-options"><legend>Select e-wallet</legend>${donationWallets.map(([name, key]) => `<label class="provider-selection-option demo-wallet-option wallet-${key}"><input type="radio" name="demo-wallet" value="${esc(name)}" ${selected === name ? 'checked' : ''}><span class="provider-selection-card"><b>${esc(name)}</b><small>Mobile payment</small></span></label>`).join('')}</fieldset><div id="donation-validation" class="validation" role="alert" aria-live="polite"></div>${providerActions('<button class="btn" type="button" onclick="continueDemoWallet()">Continue to E-Wallet</button>')}</section>`,
  );
  donationScroll();
}
function continueDemoWallet() {
  let wallet =
    document.querySelector('input[name="demo-wallet"]:checked')?.value || '';
  if (!donationWallets.some(([name]) => name === wallet))
    return setDonationValidation('Select an e-wallet provider.');
  donationDraft.demoWallet = wallet;
  renderWalletRedirect();
}
function walletProviderShell(markup) {
  return `<section class="bank-provider-shell wallet-provider-shell wallet-theme-${walletProviderKey()}"><div class="bank-provider-card wallet-provider-card">${markup}</div></section>`;
}
function walletProviderHeader(title, description = '') {
  return `<header class="bank-provider-header wallet-provider-header"><span class="bank-provider-name wallet-provider-name">${esc(walletProviderName())}</span><h1>${esc(title)}</h1>${description ? `<p>${esc(description)}</p>` : ''}</header>`;
}
function renderWalletRedirect() {
  setExternalProviderMode('wallet');
  let target = $('#donation-flow'),
    wallet = walletProviderName();
  if (!target) return;
  clearDonationSensitiveInputs();
  target.innerHTML = walletProviderShell(
    `<div class="bank-transition wallet-transition" role="status" aria-live="polite"><div class="demo-payment-spinner wallet-spinner" aria-hidden="true"></div><h1>Redirecting to ${esc(wallet)}...</h1><p>Opening the payment provider.</p><button class="bank-link-button wallet-link-button" type="button" onclick="cancelDonationPayment()">Cancel payment</button></div>`,
  );
  scrollTo(0, 0);
  scheduleDonationTransition(renderWalletAccess, 800);
}
async function renderWalletAccess() {
  setExternalProviderMode('wallet');
  let target = $('#donation-flow'),
    wallet = walletProviderName(),
    key = walletProviderKey();
  if (!target) return;
  try {
    await ensureDonationProviderIdentity(true);
    target.innerHTML = walletProviderShell(
      `${walletProviderHeader(`Sign in to ${wallet}`, 'Enter your mobile number to continue.')}<form class="bank-login-form wallet-access-form" onsubmit="requestWalletVerification(event)" autocomplete="off" novalidate><div class="field"><label for="wallet-login-phone">Phone Number</label><input id="wallet-login-phone" name="fyp_${key}_wallet_mobile" class="input" type="tel" inputmode="tel" autocomplete="off" maxlength="20" data-lpignore="true" data-1p-ignore="true" placeholder="e.g. 012-345 6789" required></div><div id="donation-validation" class="validation" role="alert" aria-live="polite"></div><div class="bank-provider-actions wallet-provider-actions"><button class="bank-link-button wallet-link-button" type="button" onclick="cancelDonationPayment()">Cancel</button><button id="pawpay-send-code" class="bank-primary-button wallet-primary-button" type="submit">Send Verification Code</button></div></form>`,
    );
    scrollTo(0, 0);
    clearFreshProviderFields(['#wallet-login-phone'], '#wallet-login-phone');
  } catch (error) {
    setExternalProviderMode();
    renderDonationReview(
      error.message ||
        'Unable to open the e-wallet provider. Please try again.',
    );
    donationScroll();
  }
}
async function requestWalletVerification(event) {
  event?.preventDefault();
  let input = $('#wallet-login-phone'),
    phone = input?.value.trim() || '',
    normalised = phone.replace(/[\s()-]/g, '');
  if (!/^(?:\+?60|0)1\d{8,9}$/.test(normalised))
    return setDonationValidation(
      'Enter a valid Malaysian-style mobile number.',
    );
  let result = await sendDonationVerificationCode(
    'e_wallet',
    '#pawpay-send-code',
  );
  if (result) {
    if (input) input.value = '';
    renderWalletVerification(result.masked_email, result.resend_after_seconds);
  }
}
function renderWalletVerification(
  maskedEmail = donationProviderIdentity?.masked_email ||
    'your registered email',
) {
  setExternalProviderMode('wallet');
  let target = $('#donation-flow');
  if (!target) return;
  target.innerHTML = walletProviderShell(
    `${walletProviderHeader(`Verify ${walletProviderName()} Access`, 'A verification code has been sent to your registered email.')}<p class="provider-email-destination">Email:<strong>${esc(maskedEmail)}</strong></p><form class="bank-verification-form" onsubmit="verifyDonationPaymentCode(event,'e_wallet')" novalidate><div class="field"><label for="pawpay-verification-code">Verification Code</label><input id="pawpay-verification-code" class="input code-input" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="6-digit code"></div><div id="resend-status" class="provider-status" role="status" aria-live="polite"></div><div id="donation-validation" class="validation" role="alert" aria-live="polite"></div>${verificationActions('e_wallet', 'Continue')}</form>`,
  );
  scrollTo(0, 0);
  startDonationResendCountdown('e_wallet');
}
function renderWalletPaymentApproval() {
  setExternalProviderMode('wallet');
  let target = $('#donation-flow'),
    identity = donationProviderIdentity || {};
  if (!target) return;
  target.innerHTML = walletProviderShell(
    `${walletProviderHeader('Payment Approval', 'Review and approve this wallet payment.')}<section class="provider-welcome"><p>Welcome,</p><strong>${esc(identity.name || donationDraft.donorName)}</strong></section><dl class="bank-provider-facts wallet-provider-facts"><div><dt>Wallet Balance</dt><dd>RM XXX.XX</dd></div><div><dt>Merchant</dt><dd>AdoptAPet</dd></div><div><dt>Donation Amount</dt><dd>${money(donationDraft.amount)}</dd></div><div><dt>Reference</dt><dd>${esc(donationDemoReference())}</dd></div></dl><div id="donation-validation" class="validation" role="alert" aria-live="polite"></div><div class="bank-provider-actions wallet-provider-actions"><button class="bank-secondary-button wallet-secondary-button" type="button" onclick="cancelDonationPayment()">Cancel</button><button id="pawpay-approve-payment" class="bank-primary-button wallet-primary-button" type="button" onclick="approveWalletPayment()">Approve Payment</button></div>`,
  );
  scrollTo(0, 0);
}
function approveWalletPayment() {
  if (donationPaymentLocked) return;
  donationDraft.providerDisplayLabel = `E-Wallet — ${walletProviderName()}`;
  beginDonationProcessing('wallet');
}

function providerShellForContext(context, markup) {
  if (context === 'bank') return bankProviderShell(markup);
  if (context === 'wallet') return walletProviderShell(markup);
  return donationFlowContent(markup);
}
function providerNameForContext(context) {
  if (context === 'bank') return donationDraft?.demoBank || 'Online Banking';
  if (context === 'wallet') return walletProviderName();
  return 'Card Payment';
}
function setProviderContextMode(context) {
  setExternalProviderMode(
    context === 'bank' ? 'bank' : context === 'wallet' ? 'wallet' : '',
  );
  setPaymentTransitionMode(true);
}
function donationProcessingMarkup() {
  return `<section class="donation-processing" role="status" aria-live="polite"><div class="demo-payment-spinner" aria-hidden="true"></div><h2 id="payment-processing-status">Authorising payment…</h2><p>Please keep this page open while the transaction completes.</p><div id="donation-validation" class="validation" role="alert"></div></section>`;
}
async function beginDonationProcessing(context = 'card') {
  if (donationPaymentLocked || donationSubmitting || !donationDraft) return;
  donationPaymentLocked = true;
  setDonationPaymentSubflow(true);
  clearDonationSensitiveInputs();
  clearDonationTransition();
  clearDonationResendTimer();
  let target = $('#donation-flow');
  if (!target) {
    donationPaymentLocked = false;
    return;
  }
  setProviderContextMode(context);
  target.innerHTML = providerShellForContext(
    context,
    donationProcessingMarkup(),
  );
  if (context === 'bank' || context === 'wallet') scrollTo(0, 0);
  else donationScroll();
  let reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  if (reduced) {
    let status = $('#payment-processing-status');
    if (status) status.textContent = 'Processing payment…';
    await new Promise((resolve) => setTimeout(resolve, 1650));
  } else {
    for (const [message, delay] of [
      ['Confirming transaction…', 600],
      ['Completing payment…', 600],
    ]) {
      await new Promise((resolve) => setTimeout(resolve, delay));
      let status = $('#payment-processing-status');
      if (status) status.textContent = message;
    }
    await new Promise((resolve) => setTimeout(resolve, 550));
  }
  renderProviderPaymentSuccessful(context);
}
function renderProviderPaymentSuccessful(context) {
  setProviderContextMode(context);
  let target = $('#donation-flow');
  if (!target) return;
  let markup = `<div class="bank-transition bank-success" role="status" aria-live="polite"><div class="bank-success-mark" aria-hidden="true">✓</div><span class="bank-provider-name">${esc(providerNameForContext(context))}</span><h1>Payment Successful</h1><p>Your transaction has been authorised.</p></div>`;
  target.innerHTML = providerShellForContext(context, markup);
  if (context === 'bank' || context === 'wallet') scrollTo(0, 0);
  else donationScroll();
  scheduleDonationTransition(() => renderProviderReturn(context), 700);
}
function renderProviderReturn(context) {
  setProviderContextMode(context);
  let target = $('#donation-flow');
  if (!target) return;
  let markup = `<div class="bank-transition" role="status" aria-live="polite"><div class="demo-payment-spinner ${context === 'wallet' ? 'wallet-spinner' : 'bank-spinner'}" aria-hidden="true"></div><h1>Returning to AdoptAPet...</h1><p>Finalising your donation receipt.</p></div>`;
  target.innerHTML = providerShellForContext(context, markup);
  if (context === 'bank' || context === 'wallet') scrollTo(0, 0);
  else donationScroll();
  scheduleDonationTransition(() => {
    setExternalProviderMode();
    setPaymentTransitionMode(false);
    makeDatabaseDonation();
  }, 750);
}

async function loadDonationContributorName() {
  let input = $('#donate-name');
  if (!input) return;
  try {
    let response = await fetch(
        new URL('api/profile.php', window.location.href),
        { credentials: 'same-origin' },
      ),
      result = await response.json();
    if (response.ok && result.success) {
      donationProviderIdentity = {
        name: result.profile.name,
        email: result.profile.email,
        phone: result.profile.phone || '',
      };
      if (!input.value) {
        input.value = result.profile.name;
        donationDraft.donorName = result.profile.name;
      }
    }
  } catch (error) {}
}

// Donation completion and receipt retrieval
async function makeDatabaseDonation() {
  if (donationSubmitting || !donationDraft) return;
  donationSubmitting = true;
  setDonationValidation('');
  try {
    let response = await fetch(
        new URL('api/donations.php', window.location.href),
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            amount: donationDraft.amount,
            donor_name: donationDraft.donorName,
            is_anonymous: donationDraft.isAnonymous,
            payment_method: donationDraft.paymentMethod,
            idempotency_key: donationDraft.idempotencyKey,
          }),
        },
      ),
      result = await response.json();
    if (!response.ok || !result.success) {
      renderDonationReview(
        result.message ||
          'The payment could not be completed. Check My Activity before trying again.',
      );
      return;
    }
    state.lastDonation = result.donation;
    setDonationPaymentSubflow(false);
    setDonationProgress(0);
    toast(result.message, result.receipt_email_sent ? 'success' : 'info');
    let receiptAction = result.receipt_available
        ? `<a class="btn" href="api/documents.php?type=donation_receipt&amp;id=${result.donation.id}">Download Receipt</a>`
        : `<a class="btn" href="#/activity">View My Activity</a>`,
      displayMethod =
        donationDraft.providerDisplayLabel ||
        result.donation.demo_payment_method_label;
    $('#donation-flow').innerHTML = donationFlowContent(
      `<div class="donation-success" role="status"><div class="donation-success-mark" aria-hidden="true">✓</div><span class="eyebrow">Payment completed</span><h2>Donation Successful</h2><p>Thank you. Your contribution has been recorded.</p><dl class="donation-review"><div><dt>Amount</dt><dd>${money(result.donation.amount)}</dd></div><div><dt>Transaction reference</dt><dd>${esc(result.donation.transaction_reference)}</dd></div><div><dt>Date</dt><dd>${date(result.donation.donated_at)}</dd></div><div><dt>Payment method</dt><dd>${esc(displayMethod)}</dd></div></dl><p class="notice ${result.receipt_email_sent ? 'success' : 'warning'}">${result.receipt_email_sent ? 'A copy of your receipt has been sent to your registered email address.' : result.receipt_available ? 'Your donation was recorded successfully. The receipt is available for download, but the email could not be sent.' : 'Your donation was recorded successfully. The receipt can be prepared from My Activity.'}</p><div class="page-actions form-actions donation-actions">${receiptAction}<a class="btn outline" href="#/activity">View My Activity</a><a class="btn outline" href="#/donations">Donation Board</a></div></div>`,
    );
  } catch (error) {
    renderDonationReview(
      'The payment result could not be confirmed. Try again safely with the same donation request.',
    );
    setDonationValidation('No sensitive payment fields were sent.');
  } finally {
    donationSubmitting = false;
    donationPaymentLocked = false;
  }
}

function databaseReceiptPage(id) {
  if (state.role !== 'Registered User')
    return shell(
      '<div class="empty panel">Please <a href="#/login">log in</a> with a verified Registered User account to view this Donation confirmation.</div>',
    );
  setTimeout(() => loadDonationReceipt(id), 0);
  return shell(
    '<div id="donation-receipt" class="panel empty">Loading Donation confirmation…</div>',
  );
}

async function loadDonationReceipt(id) {
  let target = $('#donation-receipt');
  if (!target) return;
  try {
    let response = await fetch(
        new URL(
          `api/donations.php?id=${encodeURIComponent(id)}`,
          window.location.href,
        ),
        { credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    let donation = result.donation;
    target.className = '';
    target.innerHTML = `<div class="no-print"><a class="btn sm" href="api/documents.php?type=donation_receipt&amp;id=${donation.id}">Download PDF Receipt</a> <button class="btn outline sm" onclick="window.print()">Print Preview</button> <a class="btn outline sm" href="#/activity">My Activity</a></div><article class="receipt"><div class="receipt-header"><div><div class="brand receipt-brand">${publicBrandLogo('receipt-brand-logo')}</div><small>Web-Based Pet Adoption System</small></div><div><b>DONATION CONFIRMATION</b><br><small>Donation #${donation.id}</small></div></div><p>Received from: <b>${esc(donation.donor_name)}</b>${donation.is_anonymous ? ' <span class="muted">(shown publicly as Anonymous Supporter)</span>' : ''}</p><p>Date: ${date(donation.donated_at)}</p><p>Payment method: <b>${esc(donation.demo_payment_method_label)}</b></p><div class="receipt-total">${money(donation.amount)}</div><p>Transaction reference: <b>${esc(donation.transaction_reference || '—')}</b></p><p>Thank you for supporting the welfare of rescued animals.</p><p class="footer-note">Card, bank, and wallet credentials are not stored with this receipt.</p></article>`;
  } catch (error) {
    target.className = 'panel empty error-state';
    target.innerHTML = `<h2>Donation confirmation unavailable</h2><p>${esc(error.message || 'Please try again later.')}</p><a class="btn outline" href="#/activity">View My Activity</a>`;
  }
}

window.addEventListener('hashchange', () => {
  if (
    location.hash !== '#/donate' &&
    (document.body.classList.contains('payment-provider-fullscreen') ||
      document.body.classList.contains('payment-transition-active'))
  ) {
    invalidatePaymentVerification();
    clearDonationSensitiveInputs();
    clearDonationTransition();
    clearDonationResendTimer();
    setExternalProviderMode();
    setPaymentTransitionMode(false);
    donationPaymentLocked = false;
  }
});

render();
