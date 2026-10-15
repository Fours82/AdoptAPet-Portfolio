async function adminMfaRequest(action, fields = {}) {
  const response = await fetch(
    new URL('api/admin-mfa.php', window.location.href),
    {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ action, ...fields }),
    },
  );
  return { response, result: await response.json() };
}

function adminMfaPage() {
  if (!state.pendingMfa) {
    setTimeout(
      () => go(isAdminUiRole(state.role) ? '/admin/security' : '/login'),
      0,
    );
    return shell('');
  }
  const setup = state.pendingMfa.mode === 'setup';
  if (setup) setTimeout(adminMfaLoadSetup, 0);
  return shell(`<div class="login-wrap"><div class="panel auth-card mfa-card">
    <header class="auth-heading"><h1>${setup ? 'Set up two-factor authentication' : 'Two-factor authentication'}</h1>
    <p>${setup ? 'Protect your Administrator account with an authenticator app.' : 'Enter the 6-digit code from your authenticator app.'}</p></header>
    ${setup ? `<ol class="mfa-steps"><li>Add a time-based account named AdoptAPet Administrator in your authenticator app. Scan this QR code:<div class="mfa-qr-wrap" aria-live="polite"><span id="mfa-qr-status">Loading QR code…</span><img class="mfa-qr-image" id="mfa-setup-qr" alt="QR code for AdoptAPet Administrator authenticator setup" hidden></div></li><li>Can't scan the code? Enter this setup key manually:<div class="mfa-setup-key" id="mfa-setup-key" aria-label="Authenticator setup key">Loading…</div><small>SHA-1 · 6 digits · 30 seconds</small></li><li>Enter the current code shown in the app.</li></ol>` : ''}
    <form onsubmit="adminMfaSubmit(event)">
      <div class="field"><label for="mfa-code">${setup ? 'Authenticator code' : state.mfaUseRecovery ? 'Recovery code' : 'Authenticator code'}</label>
      <input id="mfa-code" class="input code-input" inputmode="${state.mfaUseRecovery ? 'text' : 'numeric'}" autocomplete="one-time-code" maxlength="${state.mfaUseRecovery ? 19 : 6}" required placeholder="${state.mfaUseRecovery ? 'XXXX-XXXX-XXXX-XXXX' : '6-digit code'}"></div>
      <div id="auth-message" class="validation" role="alert" aria-live="polite"></div>
      <button id="mfa-submit" class="btn auth-primary" type="submit">${setup ? 'Verify and enable' : 'Verify'}</button>
    </form>
    <div class="mfa-secondary">${!setup ? `<button class="mfa-text-action" type="button" onclick="adminMfaSwitchMethod()">${state.mfaUseRecovery ? 'Use an authenticator code' : 'Use a recovery code'}</button>` : ''}
    <button class="mfa-text-action" type="button" onclick="adminMfaCancel()">Cancel</button></div>
  </div></div>`);
}

async function adminMfaLoadSetup() {
  if (state.pendingMfa?.mode !== 'setup' || !$('#mfa-setup-key')) return;
  const showQrUnavailable = () => {
    const status = $('#mfa-qr-status'),
      image = $('#mfa-setup-qr');
    if (image) {
      image.hidden = true;
      image.removeAttribute('src');
    }
    if (status)
      status.textContent = 'QR code unavailable. Use the setup key below.';
  };
  try {
    const { response, result } = await adminMfaRequest('setup');
    if (!$('#mfa-setup-key')) return;
    if (!response.ok || !result.success) {
      $('#mfa-setup-key').textContent = 'Unavailable';
      showQrUnavailable();
      setAuthMessage(result.message || 'Unable to start setup.');
      if (result.restart_login) adminMfaReturnToLogin();
      return;
    }
    $('#mfa-setup-key').textContent = result.setup_key
      .match(/.{1,4}/g)
      .join(' ');
    const status = $('#mfa-qr-status'),
      image = $('#mfa-setup-qr');
    if (
      status &&
      image &&
      typeof result.qr_image === 'string' &&
      result.qr_image.startsWith('data:image/svg+xml;base64,')
    ) {
      image.onerror = showQrUnavailable;
      image.src = result.qr_image;
      image.hidden = false;
      status.textContent = '';
    } else showQrUnavailable();
  } catch (error) {
    if ($('#mfa-setup-key')) {
      $('#mfa-setup-key').textContent = 'Unavailable';
      showQrUnavailable();
      setAuthMessage('Unable to start setup.');
    }
  }
}

function adminMfaSwitchMethod() {
  state.mfaUseRecovery = !state.mfaUseRecovery;
  render();
  $('#mfa-code')?.focus();
}
function adminMfaReturnToLogin() {
  state.pendingMfa = null;
  state.mfaUseRecovery = false;
  state.csrfToken = '';
  go('/login');
}

async function adminMfaSubmit(event) {
  event.preventDefault();
  const setup = state.pendingMfa?.mode === 'setup',
    code = $('#mfa-code')?.value.trim() || '',
    button = $('#mfa-submit');
  if (!code) return setAuthMessage('Enter your authentication code.');
  setAuthMessage('');
  setButtonBusy(button, true, 'Verifying…');
  try {
    const { response, result } = await adminMfaRequest(
      setup ? 'enroll' : 'verify',
      {
        code,
        method: setup ? 'totp' : state.mfaUseRecovery ? 'recovery' : 'totp',
      },
    );
    if (!response.ok || !result.success) {
      setAuthMessage(result.message || 'Unable to verify the code.');
      if (result.restart_login) setTimeout(adminMfaReturnToLogin, 1200);
      return;
    }
    state.pendingMfa = null;
    state.mfaUseRecovery = false;
    state.csrfToken = result.csrf_token || '';
    state.role = apiRoleToUi(result.role);
    if (!isAdminUiRole(state.role)) {
      state.role = 'Guest';
      state.user = null;
      go('/login');
      return;
    }
    if (setup) {
      state.mfaRecoveryCodes = result.recovery_codes;
      go('/admin/mfa/recovery');
    }
    try {
      const meResponse = await fetch(
          new URL('api/me.php', window.location.href),
          { credentials: 'same-origin' },
        ),
        me = await meResponse.json();
      if (
        meResponse.ok &&
        me.authenticated &&
        apiRoleToUi(me.user?.role) === state.role
      ) {
        state.user = me.user;
        state.csrfToken = me.csrf_token || state.csrfToken;
      } else if (!setup)
        toast(
          'Authentication completed. Refresh the page if the workspace does not load.',
          'warning',
        );
    } catch (error) {
      if (!setup)
        toast(
          'Authentication completed. Refresh the page if the workspace does not load.',
          'warning',
        );
    }
    if (!setup) {
      if (result.recovery_used)
        toast(
          'A recovery code was used. Save your remaining codes securely.',
          'warning',
        );
      go('/admin');
    }
  } catch (error) {
    setAuthMessage('Unable to verify the code. Please try again.');
  } finally {
    if (button?.isConnected) setButtonBusy(button, false);
  }
}

async function adminMfaCancel() {
  try {
    await adminMfaRequest('cancel');
  } catch (error) {}
  state.pendingMfa = null;
  state.csrfToken = '';
  state.mfaUseRecovery = false;
  go('/login');
}

function adminMfaRecoveryPage() {
  if (!isAdminUiRole(state.role) || !Array.isArray(state.mfaRecoveryCodes)) {
    setTimeout(
      () => go(isAdminUiRole(state.role) ? '/admin/security' : '/login'),
      0,
    );
    return shell('');
  }
  return shell(`<div class="login-wrap auth-wide"><div class="panel auth-card mfa-card">
    <h1>Save your recovery codes</h1><p>Save these recovery codes somewhere safe. Each code can be used once.</p>
    <textarea id="mfa-recovery-list" class="input mfa-recovery-list" rows="8" readonly aria-label="One-time recovery codes">${state.mfaRecoveryCodes.map(esc).join('\n')}</textarea>
    <div class="form-actions"><button class="btn outline" type="button" onclick="adminMfaCopyCodes()">Copy codes</button>
    <button class="btn" type="button" onclick="adminMfaFinishRecovery()">I've saved them</button></div>
    <p class="footer-note">These codes will not be shown again.</p>
  </div></div>`);
}
async function adminMfaCopyCodes() {
  const area = $('#mfa-recovery-list');
  if (!area) return;
  try {
    if (navigator.clipboard) await navigator.clipboard.writeText(area.value);
    else {
      area.select();
      if (!document.execCommand('copy')) throw new Error('Copy unavailable');
    }
    toast('Recovery codes copied.', 'success');
  } catch (error) {
    area.select();
    toast('Select and copy the codes manually.', 'info');
  }
}
function adminMfaFinishRecovery() {
  state.mfaRecoveryCodes = null;
  go('/admin');
}

function adminMfaSecurityPage() {
  setTimeout(adminMfaCheckStatus, 0);
  return shell(
    `<div class="page-title"><h1>Two-factor authentication</h1><p>Protecting your Administrator account.</p></div>
    <div class="panel mfa-management"><p id="mfa-security-status"><strong>Status:</strong> Enabled</p>
    <p>To set up a new authenticator, confirm your current password and an authenticator or unused recovery code. The old authenticator and remaining recovery codes will be invalidated.</p>
    <form onsubmit="adminMfaReset(event)"><div class="field"><label for="mfa-reset-password">Current password</label>
      <input class="input" id="mfa-reset-password" type="password" autocomplete="current-password" required></div>
      <div class="field"><label for="mfa-reset-method">Authentication method</label><select class="input" id="mfa-reset-method">
        <option value="totp">Authenticator code</option><option value="recovery">Recovery code</option></select></div>
      <div class="field"><label for="mfa-reset-code">Current code</label><input class="input" id="mfa-reset-code" autocomplete="one-time-code" required></div>
      <div id="mfa-management-message" class="validation" role="alert" aria-live="polite"></div>
      <button class="btn outline" id="mfa-reset-submit" type="submit">Set up new authenticator</button></form>
    </div>`,
    true,
  );
}
async function adminMfaCheckStatus() {
  try {
    let { response, result } = await adminMfaRequest('status');
    if ((!response.ok || !result.enabled) && $('#mfa-security-status'))
      $('#mfa-security-status').textContent =
        'Status unavailable. Please sign in again.';
  } catch (error) {
    if ($('#mfa-security-status'))
      $('#mfa-security-status').textContent = 'Status unavailable.';
  }
}
async function adminMfaReset(event) {
  event.preventDefault();
  const button = $('#mfa-reset-submit'),
    password = $('#mfa-reset-password').value,
    code = $('#mfa-reset-code').value.trim(),
    method = $('#mfa-reset-method').value,
    message = $('#mfa-management-message');
  message.textContent = '';
  setButtonBusy(button, true, 'Verifying…');
  try {
    let { response, result } = await adminMfaRequest('reset', {
      current_password: password,
      code,
      method,
    });
    if (!response.ok || !result.success) {
      message.textContent =
        result.message || 'Unable to verify your credentials.';
      return;
    }
    state.role = 'Guest';
    state.user = null;
    state.pendingMfa = { mode: 'setup' };
    state.csrfToken = result.csrf_token || '';
    go('/admin/mfa');
  } catch (error) {
    message.textContent = 'Unable to reset authenticator setup.';
  } finally {
    if (button?.isConnected) setButtonBusy(button, false);
  }
}
