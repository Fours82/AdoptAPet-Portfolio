const accountSections = {
  overview: 'Overview',
  personal: 'Personal Information',
  security: 'Security',
  account: 'Account',
};

function accountRouteSection() {
  const route = currentHashRoute();
  if (route === '/security') return 'security';
  if (route === '/account') return 'overview';
  return route.startsWith('/account/') ? route.slice('/account/'.length) : '';
}
function accountRouteMatches(section) {
  return accountRouteSection() === section;
}
function accountShell(section, content) {
  const navigation = Object.entries(accountSections)
    .map(([key, label]) => {
      const href = key === 'overview' ? '#/account' : `#/account/${key}`;
      return `<a href="${href}"${key === section ? ' class="active" aria-current="page"' : ''}>${label}</a>`;
    })
    .join('');
  return shell(
    `<div class="account-center"><div class="account-heading">${isAdminUiRole(state.role) ? '<span class="eyebrow">Administrator account</span>' : ''}<h1>Account</h1><p>Manage your personal information and account security.</p></div><div class="account-layout"><nav class="account-navigation" aria-label="Account sections">${navigation}</nav><section class="account-main" aria-label="${esc(accountSections[section] || 'Account')}">${content}</section></div></div>`,
    isAdminUiRole(state.role),
  );
}
function accountPage() {
  const section = accountRouteSection();
  if (!Object.hasOwn(accountSections, section)) return systemStatePage(404);
  if (section === 'security') return accountSecurityPage();
  if (section === 'personal') {
    setTimeout(accountLoadPersonal, 0);
    return accountShell(
      section,
      '<h2>Personal Information</h2><div id="account-personal-panel" aria-busy="true"><p>Loading your information…</p></div>',
    );
  }
  setTimeout(() => accountLoadOverview(section), 0);
  return accountShell(
    section,
    `<h2>${esc(accountSections[section])}</h2><div id="account-overview-panel" aria-busy="true"><p>Loading account information…</p></div>`,
  );
}
function accountSecurityPage() {
  const privileged = isAdminUiRole(state.role);
  const passwordRoute = privileged ? '#/admin/password' : '#/password';
  const actions = `<div class="account-security-actions"><div><div><h3>Password</h3><p>Update your sign-in password.</p></div><a class="btn outline sm" href="${passwordRoute}">Change password</a></div>${privileged ? `<div><div><h3>Two-factor authentication</h3><p>Manage your Administrator authenticator and recovery setup.</p></div><a class="btn outline sm" href="#/admin/security">Manage MFA</a></div>` : `<div><div><h3>Email security</h3><p>Email changes require your password and verification at the new address.</p></div></div>`}</div>`;
  return accountShell(
    'security',
    '<h2>Security</h2><p>Review sign-ins and protect your account.</p>' +
      actions +
      securitySectionContent(),
  );
}
async function accountFetchOverview() {
  const response = await fetch(
    new URL('api/account-overview.php', window.location.href),
    { credentials: 'same-origin' },
  );
  const result = await response.json();
  if (!response.ok || !result.success)
    throw new Error(result.message || 'Unable to load account information.');
  return result.overview;
}
function accountIdentityMarkup(data) {
  return `<div class="account-identity"><div class="account-identity-avatar">${accountAvatarMarkup(data)}</div><div><h3>${esc(data.name || 'Name not available')}</h3><p>${data.username ? '@' + esc(data.username) : 'Username not available'}</p><div class="account-identity-meta"><span>${esc(apiRoleToUi(data.role) || 'Unknown role')}</span><span>${esc(petStatusLabel(data.account_status))}</span><span>Member since ${data.created_at ? date(data.created_at) : 'unavailable'}</span></div></div></div>`;
}
function accountOverviewMarkup(data) {
  const summary =
    data.role === 'registered_user' && data.summary
      ? `<div class="account-summary"><h3>Your activity</h3><div class="account-activity-list"><a href="#/applications"><span>Applications</span><strong>${Number(data.summary.applications) || 0}</strong><span aria-hidden="true">→</span></a><a href="#/activity"><span>Stray Reports</span><strong>${Number(data.summary.stray_reports) || 0}</strong><span aria-hidden="true">→</span></a><a href="#/activity"><span>Completed Donations</span><strong>${Number(data.summary.completed_donations) || 0}</strong><span aria-hidden="true">→</span></a></div></div>`
      : '';
  return accountIdentityMarkup(data) + summary;
}
function accountStatusMarkup(data) {
  return `<div class="account-status"><h3>Account details</h3><dl class="account-details"><div><dt>Username</dt><dd>${data.username ? '@' + esc(data.username) : 'Not available'}</dd></div><div><dt>Account status</dt><dd>${esc(petStatusLabel(data.account_status))}</dd></div><div><dt>Role</dt><dd>${esc(apiRoleToUi(data.role) || 'Unknown role')}</dd></div><div><dt>Member since</dt><dd>${data.created_at ? date(data.created_at) : 'Unavailable'}</dd></div></dl><div class="account-signout"><h3>Sign out</h3><p>Sign out of your current AdoptAPet session.</p><button type="button" class="btn outline" onclick="logout()">Sign out</button></div></div>`;
}
async function accountLoadOverview(section) {
  const panel = $('#account-overview-panel');
  if (!panel) return;
  try {
    const data = await accountFetchOverview();
    if (!accountRouteMatches(section) || !panel.isConnected) return;
    panel.removeAttribute('aria-busy');
    panel.innerHTML =
      section === 'account'
        ? accountStatusMarkup(data)
        : accountOverviewMarkup(data);
  } catch (error) {
    if (!panel.isConnected) return;
    panel.removeAttribute('aria-busy');
    panel.innerHTML = `<p role="alert">${esc(error.message || 'Unable to load account information.')}</p><button class="btn outline sm" onclick="accountLoadOverview('${section}')">Retry</button>`;
  }
}
function accountPersonalView(data) {
  const fields = [
    ['Username', data.username ? '@' + data.username : 'Not added'],
    ['Full name', data.name || 'Not added'],
    ['Phone number', data.phone || 'Not added'],
    ['Location', data.location || 'Not added'],
  ];
  return `<div class="account-section-head"><p>Review your details. Select Edit to make changes.</p><button id="account-personal-edit" type="button" class="btn outline sm" onclick="accountStartPersonalEdit()">Edit</button></div><div class="account-personal-avatar">${accountAvatarMarkup(data)}</div><dl class="account-details account-personal-details">${fields.map(([label, value]) => `<div><dt>${label}</dt><dd>${esc(value)}</dd></div>`).join('')}<div><dt>Email address</dt><dd><output class="profile-email-value account-email-readonly">${esc(data.email)}</output></dd></div><div><dt>Preferred pet type</dt><dd>${esc(data.preferred_pet_type ? petStatusLabel(data.preferred_pet_type) : 'Not specified')}</dd></div><div><dt>Adoption preferences</dt><dd>${esc(data.adoption_preferences || 'Not added')}</dd></div></dl><p class="muted account-note">Pet and adoption preferences are saved for your reference; they do not drive automated matching.</p>`;
}
function accountPersonalEditForm(data) {
  return `<div class="account-section-head"><p>Update your personal and contact information.</p></div><div class="form-grid"><div class="field full"><label for="profile-username">Username</label><input id="profile-username" class="input" minlength="3" maxlength="20" pattern="[A-Za-z0-9_]{3,20}" autocomplete="username" value="${esc(data.username)}"><p class="helper">3–20 characters. Letters, numbers and underscores only.</p><div id="profile-username-message" class="validation" role="alert" aria-live="polite"></div></div><div class="field"><label for="profile-name">Full name</label><input id="profile-name" class="input" maxlength="150" autocomplete="name" value="${esc(data.name)}"></div><div class="field profile-email-field"><label for="profile-email">Email address</label><input id="profile-email" type="email" class="input profile-email-value" maxlength="255" autocomplete="email" required aria-describedby="profile-email-helper profile-email-message" value="${esc(data.email)}" oninput="accountEmailDraftChanged()"><p id="profile-email-helper" class="helper">Other profile changes save normally. Email changes require your password and verification at the new address.</p><div id="profile-email-message" class="validation" role="alert" aria-live="polite"></div></div><div class="field"><label for="profile-phone">Phone number</label><input id="profile-phone" class="input" maxlength="30" value="${esc(data.phone || '')}"></div><div class="field"><label for="profile-location">Location</label><input id="profile-location" class="input" maxlength="150" value="${esc(data.location || '')}"></div><div class="field full profile-photo-field">${imageUploadControl('profile-image', 'profile-image-preview', 'Profile photo', data.profile_image_path || '', { change: 'selectProfileImage(event)', edit: 'editProfileImage()', remove: 'removeProfileImage()' })}<small id="profile-image-state" class="helper" role="status" aria-live="polite">Select, edit, or remove your photo. Changes apply when saved.</small></div><div class="field"><label for="profile-pet-type">Preferred pet type</label><select id="profile-pet-type">${['', 'any', 'dog', 'cat', 'other'].map((value) => `<option value="${value}" ${data.preferred_pet_type === value ? 'selected' : ''}>${value ? petStatusLabel(value) : 'Not specified'}</option>`).join('')}</select></div><div class="field full"><label for="profile-preferences">Adoption preferences</label><textarea id="profile-preferences" maxlength="1000">${esc(data.adoption_preferences || '')}</textarea><p class="helper">Saved for your reference; not used for automated matching.</p></div></div><div class="form-actions account-edit-actions"><button id="profile-save" type="button" class="btn" onclick="saveProfile()">Save changes</button><button type="button" class="btn outline" onclick="accountCancelPersonalEdit()">Cancel</button></div>`;
}
function accountStartPersonalEdit() {
  if (!authenticatedProfile) return;
  const panel = $('#account-personal-panel');
  if (!panel) return;
  panel.innerHTML = accountPersonalEditForm(authenticatedProfile);
  panel.querySelector('#profile-username')?.focus();
}
function accountEmailDraftChanged() {
  const field = $('#profile-email'),
    button = $('#profile-save');
  if (!field || !authenticatedProfile) return '';
  const draft = field.value.trim().toLowerCase(),
    current = (authenticatedProfile.email || '').trim().toLowerCase();
  if (button)
    button.textContent =
      draft !== current ? 'Save changes and verify email' : 'Save changes';
  const message = $('#profile-email-message');
  if (message) message.textContent = '';
  return draft !== current ? draft : '';
}
function accountCancelPersonalEdit() {
  const panel = $('#account-personal-panel');
  if (!panel || !authenticatedProfile) return;
  clearEditedImageSelection('profile-image');
  profileImageRemovalRequested = false;
  panel.innerHTML = accountPersonalView(authenticatedProfile);
  panel.querySelector('#account-personal-edit')?.focus();
}
function accountPersonalSaved(data) {
  const panel = $('#account-personal-panel');
  if (!panel) return;
  panel.innerHTML = accountPersonalView(data);
  panel.querySelector('#account-personal-edit')?.focus();
}
async function accountLoadPersonal() {
  const panel = $('#account-personal-panel');
  if (!panel) return;
  try {
    if (state.role === 'Registered User') {
      const response = await fetch(
          new URL('api/profile.php', window.location.href),
          { credentials: 'same-origin' },
        ),
        result = await response.json();
      if (!response.ok || !result.success)
        throw new Error(
          result.message || 'Unable to load personal information.',
        );
      if (!accountRouteMatches('personal') || !panel.isConnected) return;
      authenticatedProfile = result.profile;
      profileImageRemovalRequested = false;
      panel.innerHTML = accountPersonalView(authenticatedProfile);
    } else {
      const data = await accountFetchOverview();
      if (!accountRouteMatches('personal') || !panel.isConnected) return;
      panel.innerHTML = `${accountIdentityMarkup(data)}<dl class="account-details"><div><dt>Full name</dt><dd>${esc(data.name || 'Not available')}</dd></div><div><dt>Username</dt><dd>${data.username ? '@' + esc(data.username) : 'Not available'}</dd></div><div><dt>Email address</dt><dd>${esc(data.email)}</dd></div></dl><p class="muted account-note">Personal-information editing is not currently available for privileged accounts.</p>`;
    }
    panel.removeAttribute('aria-busy');
  } catch (error) {
    if (!panel.isConnected) return;
    panel.removeAttribute('aria-busy');
    panel.innerHTML = `<p role="alert">${esc(error.message || 'Unable to load personal information.')}</p><button class="btn outline sm" onclick="accountLoadPersonal()">Retry</button>`;
  }
}
render();
