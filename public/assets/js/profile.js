let authenticatedProfile = null;
let activityProfileData = null;
let activityPages = { donations: 1, strays: 1 };
let profileImageRemovalRequested = false;
let emailChangeResendTimer = null,
  emailChangeResendUntil = 0,
  emailChangeFocusReturn = null;
const activityPageSize = 10;

function databaseProfilePage() {
  setTimeout(loadProfile, 0);
  return shell(
    `${pageHero('profile', 'My Profile', 'Manage your personal information, profile photo, preferences, and account settings.')}<div id="profile-panel" class="panel profile-form-shell" aria-busy="true">${skeletonList(3, 'Loading your profile')}</div>`,
  );
}

function databaseActivityPage() {
  setTimeout(loadProfileActivity, 0);
  return shell(
    `<div class="activity-ux"><header class="activity-heading"><div class="activity-heading-copy"><h1>My Activity</h1><p>View your donation history and submitted stray reports.</p></div><a class="btn outline" href="#/applications">My Applications</a></header><div id="activity-panel" aria-busy="true">${skeletonList(4, 'Loading your activity')}</div></div>`,
  );
}

function profileHistoryEmpty(title, message, action = '') {
  return `<div class="empty profile-history-empty"><h3>${esc(title)}</h3><p>${esc(message)}</p>${action}</div>`;
}

function profileApplicationHistory(applications) {
  if (!applications.length)
    return profileHistoryEmpty(
      'No adoption applications yet',
      'Your submitted applications will appear here.',
      '<a class="btn sm" href="#/pets">Browse Pets</a>',
    );
  return `<div class="table-wrap"><table class="table profile-history-table"><thead><tr><th>Pet</th><th>Status</th><th>Submitted</th><th>Last updated</th><th>Document</th><th>Details</th></tr></thead><tbody>${applications.map((application) => `<tr><td><b>${esc(application.pet_name)}</b><br><small class="muted">${petStatusLabel(application.pet_species)}${application.pet_breed ? ` · ${esc(application.pet_breed)}` : ''}</small></td><td>${applicationBadge(application.application_status)}</td><td>${date(application.submitted_at)}</td><td>${date(application.last_updated_at || application.submitted_at)}</td><td>${application.application_status === 'approved' ? `<a class="btn outline sm" href="api/documents.php?type=adoption_certificate&amp;id=${application.id}">Certificate</a>` : '—'}</td><td><a class="link-btn" href="#/application/${application.id}">View</a></td></tr>`).join('')}</tbody></table></div>`;
}

function profileDonationHistory(donations) {
  if (!donations.length)
    return profileHistoryEmpty(
      'No donations yet',
      'Your personal Donation history will appear here.',
      '<a class="btn sm" href="#/donate">Make a Donation</a>',
    );
  return `<div class="table-wrap"><table class="table profile-history-table activity-donation-table"><thead><tr><th>Amount</th><th>Date</th><th>Status</th><th>Document</th></tr></thead><tbody>${donations.map((donation) => `<tr><td data-label="Amount"><b>${esc(donation.currency)} ${Number(donation.amount).toFixed(2)}</b></td><td data-label="Date">${date(donation.donation_date)}</td><td data-label="Status"><span class="badge ${esc(donation.donation_status)}">${petStatusLabel(donation.donation_status)}</span></td><td data-label="Document">${donation.donation_status === 'completed' && donation.transaction_reference ? `<a class="btn outline sm" href="api/documents.php?type=donation_receipt&amp;id=${donation.id}">Receipt</a>` : '—'}</td></tr>`).join('')}</tbody></table></div>`;
}

function meaningfulStrayUpdate(report) {
  // Initial submission populates both timestamps; later admin changes update updated_at.
  let submitted = String(report.submitted_at || ''),
    updated = String(report.updated_at || '');
  return updated && submitted && updated > submitted
    ? date(report.updated_at)
    : '—';
}

function openActivityStrayRow(event) {
  let row = event.currentTarget;
  if (event.type === 'click') {
    if (event.target.closest('a,button,input,select,textarea')) return;
  } else if (event.type === 'keydown') {
    if (event.target !== row || !['Enter', ' '].includes(event.key)) return;
    event.preventDefault();
  } else return;
  row.querySelector('a.table-action')?.click();
}

function profileStrayHistory(reports) {
  if (!reports.length)
    return profileHistoryEmpty(
      'No submitted stray reports',
      'Stray sightings you submit will appear here.',
      '<a class="btn sm" href="#/report-stray">Report a Stray</a>',
    );
  return `<div class="table-wrap"><table class="table profile-history-table profile-stray-table"><thead><tr><th>Reference</th><th>Animal</th><th>Location</th><th>Status</th><th>Submitted</th><th>Latest update</th><th><span class="sr-only">Open report</span></th></tr></thead><tbody>${reports.map((report) => `<tr tabindex="0" aria-label="Open Stray Report ${esc(strayReference(report.id))}" onclick="openActivityStrayRow(event)" onkeydown="openActivityStrayRow(event)"><td data-label="Reference"><b>${esc(strayReference(report.id))}</b></td><td data-label="Animal"><b>${petStatusLabel(report.animal_type)}</b><br><small class="muted">${esc(strayUrgencyLabel(report.urgency))}</small></td><td data-label="Location">${esc(report.location_text)}</td><td data-label="Status"><span class="badge ${esc(report.report_status)}">${esc(strayStatusLabel(report.report_status))}</span></td><td data-label="Submitted">${date(report.submitted_at)}</td><td data-label="Latest update">${meaningfulStrayUpdate(report)}</td><td data-label="Actions"><a class="table-action" href="#/stray/${report.id}" aria-label="View Stray Report ${esc(strayReference(report.id))}"><span aria-hidden="true">›</span></a></td></tr>`).join('')}</tbody></table></div>`;
}

function profileForm(profileData) {
  let displayName = profileData.username || profileData.name || '?',
    initial = esc(displayName.charAt(0)),
    picture = profileData.profile_image_path
      ? `<img src="${esc(profileData.profile_image_path)}" alt="Profile photo">`
      : `<span>${initial}</span>`;
  let imageState = profileData.profile_image_path
    ? 'The current profile photo will be kept unless replaced or removed.'
    : 'Your initials will be shown when no profile photo is set.';
  return `<section class="profile-overview"><div class="profile-avatar">${picture}</div><div class="profile-public-identity"><h2>${esc(displayName)}</h2><p><b>${esc(profileData.name)}</b>${profileData.location ? ` · ${esc(profileData.location)}` : ' · Location not added'}</p><span class="badge active">${petStatusLabel(profileData.account_status)}</span> <span class="badge ${profileData.email_verified ? 'approved' : 'pending'}">Email ${profileData.email_verified ? 'verified' : 'not verified'}</span></div></section><section class="profile-section"><h2>Personal and Contact Information</h2><div class="form-grid"><div class="field full"><label for="profile-username">Username</label><input id="profile-username" class="input" minlength="3" maxlength="20" pattern="[A-Za-z0-9_]{3,20}" autocomplete="username" aria-describedby="profile-username-helper profile-username-message" value="${esc(profileData.username)}"><p id="profile-username-helper" class="helper">3–20 characters. Letters, numbers and underscores only.</p><div id="profile-username-message" class="validation" role="alert" aria-live="polite"></div></div><div class="field"><label for="profile-name">Full name</label><input id="profile-name" class="input" maxlength="150" autocomplete="name" value="${esc(profileData.name)}"></div><div class="field profile-email-field"><label for="profile-email">Email address</label><input id="profile-email" type="email" class="input profile-email-value" value="${esc(profileData.email)}" disabled title="Email changes require a separate verification flow."><div class="profile-email-action-row"><small class="helper">Email is changed through secure verification.</small><button type="button" class="link-btn" onclick="openEmailChangeModal(this)">Change Email</button></div></div><div class="field"><label for="profile-phone">Phone number</label><input id="profile-phone" class="input" maxlength="30" value="${esc(profileData.phone || '')}"></div><div class="field"><label for="profile-location">Location</label><input id="profile-location" class="input" maxlength="150" value="${esc(profileData.location || '')}"></div><div class="field full profile-photo-field">${imageUploadControl('profile-image', 'profile-image-preview', 'Profile photo', profileData.profile_image_path || '', { change: 'selectProfileImage(event)', edit: 'editProfileImage()', remove: 'removeProfileImage()' })}<small id="profile-image-state" class="helper" role="status" aria-live="polite">${esc(imageState)}</small></div></div></section><section class="profile-section"><h2>Account Preferences</h2><div class="form-grid"><div class="field"><label for="profile-pet-type">Preferred pet type</label><select id="profile-pet-type">${['', 'any', 'dog', 'cat', 'other'].map((value) => `<option value="${value}" ${profileData.preferred_pet_type === value ? 'selected' : ''}>${value ? petStatusLabel(value) : 'Not specified'}</option>`).join('')}</select></div><div class="field full"><label for="profile-preferences">Adoption preferences</label><textarea id="profile-preferences" maxlength="1000" placeholder="For example: temperament, home environment, or care preferences">${esc(profileData.adoption_preferences || '')}</textarea></div></div></section><section class="profile-section"><h2>Account Information</h2><div class="kv"><div><b>Username</b><br><span class="username-display">${esc(profileData.username)}</span></div><div><b>Full name</b><br>${esc(profileData.name)}</div><div><b>Account status</b><br>${petStatusLabel(profileData.account_status)}</div><div><b>Email verification</b><br>${profileData.email_verified ? 'Verified' : 'Not verified'}</div><div><b>Registered</b><br>${date(profileData.created_at)}</div><div><b>Email changes</b><br><span class="profile-email-summary">Secure verification required</span></div></div></section><section class="profile-section"><h2>Security and account actions</h2><p class="muted">Save your profile settings here. Your private activity history is available separately.</p><div class="form-actions"><button id="profile-save" class="btn" onclick="saveProfile()">Save changes</button><a class="btn outline" href="#/activity">My Activity</a><a class="btn outline" href="#/security">Security</a><button class="btn outline" onclick="go('/password')">Change password</button></div></section>`;
}

function emailChangeStepOne(proposedEmail = '') {
  let email = authenticatedProfile?.email || state.user?.email || '';
  return `<form class="email-change-form" onsubmit="startEmailChange(event)"><div class="email-change-copy"><h3>Verify your identity</h3><p class="muted">Enter your current password and the new email address you want to use.</p></div><div class="field"><label for="email-change-current-email">Current email</label><input id="email-change-current-email" type="email" class="input" value="${esc(email)}" disabled></div><div class="field"><label for="email-change-password">Current password</label>${passwordInput('email-change-password', 'Enter your current password', 'current-password')}</div><div class="field"><label for="email-change-new-email">New email address</label>${emailInput('email-change-new-email', { value: proposedEmail, placeholder: 'name@example.com' })}</div><div id="email-change-message" class="validation" role="alert" aria-live="polite"></div><div class="form-actions email-change-actions"><button type="button" class="btn outline" onclick="closeEmailChangeModal()">Cancel</button><button id="email-change-start" type="submit" class="btn">Send Verification Code</button></div></form>`;
}
function emailChangeStepTwo(pending) {
  let minutes = Math.max(1, Math.ceil(Number(pending?.expires_in || 600) / 60));
  return `<form class="email-change-form" onsubmit="verifyEmailChange(event)"><div class="email-change-copy"><h3>Check your new email</h3><p>We sent a 6-digit verification code to <b>${esc(pending?.destination || 'your new email address')}</b>.</p><p class="muted">The code expires in about ${minutes} minute${minutes === 1 ? '' : 's'}.</p></div><div class="field"><label for="email-change-code">Verification code</label><input id="email-change-code" class="input email-change-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="000000" required></div><div id="email-change-message" class="validation" role="alert" aria-live="polite"></div><div class="email-change-resend-row"><button id="email-change-resend" type="button" class="link-btn" onclick="resendEmailChangeCode()">Resend code</button></div><div class="form-actions email-change-actions"><button id="email-change-cancel" type="button" class="btn outline" onclick="cancelEmailChange()">Cancel request</button><button id="email-change-verify" type="submit" class="btn">Verify Email</button></div></form>`;
}
function renderEmailChangeStep(markup, pending = null) {
  let content = $('#email-change-content');
  if (!content) return;
  content.innerHTML = markup;
  if (pending) startEmailChangeResendTimer(pending.resend_after);
  setTimeout(() => content.querySelector('input:not([disabled])')?.focus(), 0);
}
function setEmailChangeMessage(message) {
  let target = $('#email-change-message');
  if (target) target.textContent = String(message || '');
}
async function emailChangeApi(method = 'GET', payload = null) {
  let options = { method, credentials: 'same-origin' };
  if (payload) {
    options.headers = { 'Content-Type': 'application/json' };
    options.body = JSON.stringify(payload);
  }
  let response = await fetch(
      new URL('api/email-change.php', window.location.href),
      options,
    ),
    result;
  try {
    result = await response.json();
  } catch (error) {
    result = { success: false, message: 'Unable to process the email change.' };
  }
  return { response, result };
}
function startEmailChangeResendTimer(seconds) {
  clearInterval(emailChangeResendTimer);
  let button = $('#email-change-resend'),
    remaining = Math.max(0, Number(seconds) || 0);
  emailChangeResendUntil = Date.now() + remaining * 1000;
  let update = () => {
    if (!button?.isConnected) return clearInterval(emailChangeResendTimer);
    remaining = Math.max(
      0,
      Math.ceil((emailChangeResendUntil - Date.now()) / 1000),
    );
    button.disabled =
      remaining > 0 || button.getAttribute('aria-busy') === 'true';
    if (button.getAttribute('aria-busy') !== 'true')
      button.textContent =
        remaining > 0 ? `Resend code in ${remaining}s` : 'Resend code';
    if (!remaining) clearInterval(emailChangeResendTimer);
  };
  update();
  if (remaining) emailChangeResendTimer = setInterval(update, 1000);
}
async function openEmailChangeModal(button, proposedEmail = '') {
  if ($('#email-change-modal')) return;
  emailChangeFocusReturn = button || document.activeElement;
  let host = $('.public-site-page') || document.body;
  host.insertAdjacentHTML(
    'beforeend',
    `<div id="email-change-modal" class="modal-overlay email-change-overlay" role="presentation" onclick="closeEmailChangeModalFromBackdrop(event)" onkeydown="if(event.key==='Escape')closeEmailChangeModal()"><section class="panel modal-panel email-change-modal" role="dialog" aria-modal="true" aria-labelledby="email-change-title"><header class="email-change-header"><div><span class="eyebrow">Account security</span><h2 id="email-change-title">Change Email</h2></div><button type="button" class="email-change-close" aria-label="Close Change Email" onclick="closeEmailChangeModal()">&times;</button></header><div id="email-change-content" aria-live="polite"><div class="loading-state">Checking for a pending request…</div></div></section></div>`,
  );
  try {
    let { response, result } = await emailChangeApi();
    if (!$('#email-change-modal')) return;
    if (!response.ok || !result.success)
      throw new Error(result.message || 'Unable to open Change Email.');
    renderEmailChangeStep(
      result.pending
        ? emailChangeStepTwo(result.pending)
        : emailChangeStepOne(proposedEmail),
      result.pending,
    );
  } catch (error) {
    if ($('#email-change-modal'))
      renderEmailChangeStep(emailChangeStepOne(proposedEmail));
    toast(error.message || 'Unable to open Change Email.');
  }
}
function closeEmailChangeModalFromBackdrop(event) {
  if (event.target === event.currentTarget) closeEmailChangeModal();
}
function closeEmailChangeModal() {
  clearInterval(emailChangeResendTimer);
  emailChangeResendTimer = null;
  emailChangeResendUntil = 0;
  $('#email-change-modal')?.remove();
  if (emailChangeFocusReturn?.isConnected) emailChangeFocusReturn.focus();
  emailChangeFocusReturn = null;
}
async function startEmailChange(event) {
  event.preventDefault();
  let current_password = $('#email-change-password')?.value || '',
    new_email = $('#email-change-new-email')?.value.trim() || '';
  setEmailChangeMessage('');
  if (!current_password || !new_email)
    return setEmailChangeMessage(
      'Enter your current password and a valid new email address.',
    );
  let button = $('#email-change-start');
  if (!setButtonBusy(button, true, 'Sending…')) return;
  try {
    let { response, result } = await emailChangeApi('POST', {
      action: 'start',
      current_password,
      new_email,
    });
    current_password = '';
    let password = $('#email-change-password');
    if (password) password.value = '';
    if (!response.ok || !result.success) {
      setEmailChangeMessage(
        result.message || 'Unable to send the verification code.',
      );
      if (result.code === 'current_password_incorrect') password?.focus();
      return;
    }
    renderEmailChangeStep(emailChangeStepTwo(result.pending), result.pending);
    toast(result.message);
  } catch (error) {
    let password = $('#email-change-password');
    if (password) password.value = '';
    setEmailChangeMessage(
      'Unable to send the verification code. Please try again.',
    );
  } finally {
    current_password = '';
    if (button?.isConnected) setButtonBusy(button, false);
  }
}
async function resendEmailChangeCode() {
  setEmailChangeMessage('');
  let button = $('#email-change-resend');
  if (!setButtonBusy(button, true, 'Sending…')) return;
  try {
    let { response, result } = await emailChangeApi('POST', {
      action: 'resend',
    });
    if (!response.ok || !result.success) {
      if (result.pending)
        startEmailChangeResendTimer(result.pending.resend_after);
      else if (result.retry_after)
        startEmailChangeResendTimer(result.retry_after);
      return setEmailChangeMessage(
        result.message || 'Unable to resend the verification code.',
      );
    }
    renderEmailChangeStep(emailChangeStepTwo(result.pending), result.pending);
    toast(result.message);
  } catch (error) {
    setEmailChangeMessage(
      'Unable to resend the verification code. Please try again.',
    );
  } finally {
    if (button?.isConnected) {
      setButtonBusy(button, false);
      let remaining = Math.max(
        0,
        Math.ceil((emailChangeResendUntil - Date.now()) / 1000),
      );
      button.disabled = remaining > 0;
      button.textContent =
        remaining > 0 ? `Resend code in ${remaining}s` : 'Resend code';
    }
  }
}
async function verifyEmailChange(event) {
  event.preventDefault();
  let verification_code = $('#email-change-code')?.value.trim() || '';
  setEmailChangeMessage('');
  if (!/^[0-9]{6}$/.test(verification_code))
    return setEmailChangeMessage('Enter the 6-digit verification code.');
  let button = $('#email-change-verify');
  if (!setButtonBusy(button, true, 'Verifying…')) return;
  try {
    let { response, result } = await emailChangeApi('POST', {
        action: 'verify',
        verification_code,
      }),
      codeInput = $('#email-change-code');
    if (codeInput) codeInput.value = '';
    if (!response.ok || !result.success) {
      setEmailChangeMessage(
        result.message || 'Unable to verify the email address.',
      );
      if (Number(result.attempts_remaining) === 0)
        setTimeout(() => renderEmailChangeStep(emailChangeStepOne()), 900);
      return;
    }
    if (result.csrf_token) state.csrfToken = result.csrf_token;
    if (authenticatedProfile) authenticatedProfile.email = result.email;
    if (typeof updateCurrentUserProfile === 'function')
      updateCurrentUserProfile({
        ...(authenticatedProfile || {}),
        email: result.email,
      });
    document.querySelectorAll('.profile-email-value').forEach((input) => {
      input.value = result.email;
    });
    document.querySelectorAll('.account-dropdown-head').forEach((head) => {
      let details = head.querySelectorAll('small');
      if (details.length)
        details[details.length - 1].textContent = result.email;
    });
    closeEmailChangeModal();
    toast(result.message);
  } catch (error) {
    setEmailChangeMessage(
      'Unable to verify the email address. Please try again.',
    );
  } finally {
    if (button?.isConnected) setButtonBusy(button, false);
  }
}
async function cancelEmailChange() {
  setEmailChangeMessage('');
  let button = $('#email-change-cancel');
  if (!setButtonBusy(button, true, 'Cancelling…')) return;
  try {
    let { response, result } = await emailChangeApi('POST', {
      action: 'cancel',
    });
    if (!response.ok || !result.success)
      return setEmailChangeMessage(
        result.message || 'Unable to cancel the request.',
      );
    closeEmailChangeModal();
    toast(result.message);
  } catch (error) {
    setEmailChangeMessage('Unable to cancel the request. Please try again.');
  } finally {
    if (button?.isConnected) setButtonBusy(button, false);
  }
}
window.addEventListener('hashchange', () => {
  if (currentHashRoute() !== '/profile') closeEmailChangeModal();
});

function setProfileImageState(message) {
  let target = $('#profile-image-state');
  if (target) target.textContent = message;
}
function selectProfileImage(event) {
  if (!openImageEditor(event, 'profile-image-preview')) return;
  profileImageRemovalRequested = false;
  setProfileImageState(
    authenticatedProfile?.profile_image_path
      ? 'The selected image will replace your current profile photo when you save.'
      : 'The selected image will become your profile photo when you save.',
  );
}
function profileImageEditApplied() {
  profileImageRemovalRequested = false;
  setProfileImageState(
    authenticatedProfile?.profile_image_path
      ? 'The edited image will replace your current profile photo when you save.'
      : 'The edited image will become your profile photo when you save.',
  );
}
async function editProfileImage() {
  let button = $('#profile-image-edit');
  setButtonBusy(button, true, 'Loading…');
  try {
    let opened;
    if (
      editedImageSelections.has('profile-image') ||
      $('#profile-image')?.files?.length
    )
      opened = reopenImageEditor('profile-image', 'profile-image-preview');
    else if (authenticatedProfile?.profile_image_path)
      opened = await openStoredImageEditor(
        'profile-image',
        'profile-image-preview',
        authenticatedProfile.profile_image_path,
        'profile-photo',
      );
    else return toast('Select an image first.');
    if (opened && activeImageEditor)
      activeImageEditor.onApply = profileImageEditApplied;
  } catch (error) {
    toast(
      error.message ||
        'The current profile photo could not be opened for editing.',
    );
  } finally {
    if (button?.isConnected) setButtonBusy(button, false);
  }
}
function removeProfileImage() {
  removeEditedImage('profile-image', 'profile-image-preview');
  profileImageRemovalRequested = Boolean(
    authenticatedProfile?.profile_image_path,
  );
  setProfileImageState(
    profileImageRemovalRequested
      ? 'Your current profile photo will be removed when you save.'
      : 'No profile photo is selected.',
  );
}

function setProfileUsernameMessage(message) {
  let target = $('#profile-username-message');
  if (target) target.textContent = String(message || '');
}

function profileActivityContent(profileData) {
  let history = profileData.history || {},
    donations = paginatedItems(
      history.donations || [],
      activityPages.donations,
      activityPageSize,
    ),
    strays = paginatedItems(
      history.stray_reports || [],
      activityPages.strays,
      activityPageSize,
    );
  activityPages = { donations: donations.page, strays: strays.page };
  let donationRange = donations.total
      ? `Showing ${donations.start + 1}–${donations.end} of ${donations.total}`
      : 'No donation history',
    strayRange = strays.total
      ? `Showing ${strays.start + 1}–${strays.end} of ${strays.total}`
      : 'No Stray Report history';
  return `<section id="activity-donations" class="panel activity-history-section"><div class="section-head"><div><h2>Donation History</h2><p class="muted">Donations associated with your account.</p><small class="muted pagination-summary" aria-live="polite">${donationRange}</small></div><div class="form-actions compact"><a class="btn outline sm" href="#/donations">Donation Board</a><a class="btn sm" href="#/donate">Make a Donation</a></div></div>${profileDonationHistory(donations.items)}${paginationMarkup(donations.page, donations.totalPages, "changeActivityPage.bind(null,'donations')", 'Donation history pages')}</section><section id="activity-strays" class="panel activity-history-section"><div class="section-head"><div><h2>Submitted Stray Reports</h2><p class="muted">Stray sightings submitted from your account.</p><small class="muted pagination-summary" aria-live="polite">${strayRange}</small></div><div class="form-actions compact"><a class="btn outline sm" href="#/strays">Browse Reports</a><a class="btn sm" href="#/report-stray">Report a Stray</a></div></div>${profileStrayHistory(strays.items)}${paginationMarkup(strays.page, strays.totalPages, "changeActivityPage.bind(null,'strays')", 'Stray Report history pages')}</section>`;
}

function changeActivityPage(kind, page) {
  if (!activityProfileData || !['donations', 'strays'].includes(kind)) return;
  let history = activityProfileData.history || {},
    items =
      kind === 'donations'
        ? history.donations || []
        : history.stray_reports || [],
    next = paginatedItems(items, page, activityPageSize);
  if (next.page === activityPages[kind]) return;
  activityPages[kind] = next.page;
  let panel = $('#activity-panel');
  if (panel) panel.innerHTML = profileActivityContent(activityProfileData);
  paginationScroll(
    kind === 'donations' ? '#activity-donations' : '#activity-strays',
  );
}

async function loadProfileActivity() {
  let panel = $('#activity-panel');
  if (!panel) return;
  setSkeleton(panel, skeletonList(4, 'Loading your activity'));
  try {
    let response = await fetch(
        new URL('api/profile.php', window.location.href),
        { credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    activityProfileData = result.profile;
    activityPages = { donations: 1, strays: 1 };
    clearLoading(panel);
    panel.innerHTML = profileActivityContent(result.profile);
  } catch (error) {
    clearLoading(panel);
    panel.innerHTML = `<div class="panel empty error-state"><h2>Unable to load your activity</h2><p>${esc(error.message || 'Please try again.')}</p><button class="btn" onclick="loadProfileActivity()">Retry</button></div>`;
  }
}

async function loadProfile() {
  let panel = $('#profile-panel');
  if (!panel) return;
  setSkeleton(panel, skeletonList(3, 'Loading your profile'));
  try {
    let response = await fetch(
        new URL('api/profile.php', window.location.href),
        { credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    authenticatedProfile = result.profile;
    profileImageRemovalRequested = false;
    clearLoading(panel);
    panel.innerHTML = profileForm(authenticatedProfile);
  } catch (error) {
    clearLoading(panel);
    panel.innerHTML = `<div class="empty error-state"><h2>Unable to load your profile</h2><p>${esc(error.message || 'Please try again.')}</p><button class="btn" onclick="loadProfile()">Retry</button></div>`;
  }
}

async function saveDatabaseProfile() {
  let username = $('#profile-username')?.value.trim() || '',
    name = $('#profile-name')?.value.trim() || '',
    phone = $('#profile-phone')?.value.trim() || '',
    location = $('#profile-location')?.value.trim() || '',
    preferred_pet_type = $('#profile-pet-type')?.value || '',
    adoption_preferences = $('#profile-preferences')?.value.trim() || '';
  const emailDraft =
    currentHashRoute() === '/account/personal' &&
    typeof accountEmailDraftChanged === 'function'
      ? accountEmailDraftChanged()
      : '';
  if (emailDraft) {
    const field = $('#profile-email');
    if (!field?.checkValidity() || emailDraft.length > 255) {
      const message = $('#profile-email-message');
      if (message)
        message.textContent =
          'Enter a valid email address of up to 255 characters.';
      field?.focus();
      return;
    }
  }
  setProfileUsernameMessage('');
  if (!usernamePattern.test(username))
    return setProfileUsernameMessage(
      'Username must be 3–20 characters using letters, numbers, and underscores only.',
    );
  if (!name || name.length > 150)
    return toast('Enter a valid full name of up to 150 characters.');
  if (phone.length > 30 || !/^[-+().\d\s]*$/.test(phone))
    return toast('Enter a valid phone number of up to 30 characters.');
  if (location.length > 150)
    return toast('Enter a valid location of up to 150 characters.');
  if (adoption_preferences.length > 1000)
    return toast('Adoption preferences must be 1,000 characters or fewer.');
  let button = $('#profile-save');
  setButtonBusy(button, true, 'Saving…');
  try {
    let form = new FormData();
    form.append('_method', 'PATCH');
    form.append(
      'payload',
      JSON.stringify({
        username,
        name,
        phone,
        location,
        preferred_pet_type,
        adoption_preferences,
        remove_profile_image: profileImageRemovalRequested,
      }),
    );
    appendEditedImage(form, 'profile-image');
    let response = await fetch(
        new URL('api/profile.php', window.location.href),
        { method: 'POST', credentials: 'same-origin', body: form },
      ),
      result = await response.json();
    if (!response.ok || !result.success) {
      if (/username/i.test(result.message || ''))
        return setProfileUsernameMessage(result.message);
      return toast(result.message || 'Unable to update your profile.');
    }
    clearEditedImageSelection('profile-image');
    profileImageRemovalRequested = false;
    authenticatedProfile = result.profile;
    if (
      currentHashRoute() === '/account/personal' &&
      typeof accountPersonalSaved === 'function'
    )
      accountPersonalSaved(authenticatedProfile);
    else if ($('#profile-panel'))
      $('#profile-panel').innerHTML = profileForm(authenticatedProfile);
    if (typeof updateCurrentUserProfile === 'function')
      updateCurrentUserProfile(authenticatedProfile);
    toast(result.message);
    if (emailDraft && currentHashRoute() === '/account/personal')
      await openEmailChangeModal($('#account-personal-edit'), emailDraft);
  } catch (error) {
    toast('Unable to update your profile. Please try again later.');
  } finally {
    if (button && document.body.contains(button)) setButtonBusy(button, false);
  }
}

render();
