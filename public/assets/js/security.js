let securityActivityPage = 1;

function securitySectionContent() {
  setTimeout(() => securityLoad(1), 0);
  return `<div class="security-page">
      <section class="panel security-section" aria-labelledby="security-sessions-heading">
        <div class="security-section-head"><div><h2 id="security-sessions-heading">Active sessions</h2><p>Devices signed in to your account.</p></div><button type="button" id="security-sign-out-others" class="btn outline sm" onclick="securitySignOutOthers()" disabled>Sign out all other sessions</button></div>
        <div id="security-sessions" aria-live="polite"><p class="muted">Loading sessions…</p></div>
      </section>
      <section class="panel security-section" aria-labelledby="security-events-heading">
        <h2 id="security-events-heading">Recent security activity</h2>
        <div id="security-events" aria-live="polite"><p class="muted">Loading activity…</p></div>
        <div class="security-pagination" id="security-pagination"></div>
      </section>
    </div>`;
}
function securityPage() {
  return typeof accountSecurityPage === 'function'
    ? accountSecurityPage()
    : shell(
        `<div class="page-title"><h1>Security</h1></div>${securitySectionContent()}`,
        isAdminUiRole(state.role),
      );
}
function securityRouteVisible() {
  return (
    currentHashRoute() === '/security' ||
    currentHashRoute() === '/account/security'
  );
}

function securityDateTime(value) {
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime())
    ? 'Time unavailable'
    : parsed.toLocaleString('en-MY', {
        dateStyle: 'medium',
        timeStyle: 'short',
      });
}
function securityLastActive(item) {
  if (item.is_current) return 'Just now';
  const minutes = Math.max(
    0,
    Math.floor((Date.now() - new Date(item.last_active_at).getTime()) / 60000),
  );
  return Number.isFinite(minutes) && minutes < 1
    ? 'Just now'
    : Number.isFinite(minutes) && minutes < 60
      ? `${minutes} minute${minutes === 1 ? '' : 's'} ago`
      : securityDateTime(item.last_active_at);
}
function securityEventLabel(type) {
  return (
    {
      login_success: 'New sign-in',
      logout: 'Signed out',
      password_changed: 'Password changed',
      password_reset: 'Password reset',
      email_changed: 'Email changed',
      mfa_enabled: 'Administrator authenticator enabled',
      mfa_reset: 'Administrator authenticator reset',
      mfa_reenrolled: 'Administrator authenticator re-enrolled',
      recovery_code_used: 'Recovery code used',
      session_revoked: 'Another session signed out',
      other_sessions_revoked: 'All other sessions signed out',
      account_access_changed: 'Account access changed',
      promoted_to_administrator: 'Promoted to Administrator',
      demoted_to_registered_user: 'Demoted to Registered User',
      promoted_to_super_administrator: 'Promoted to Super Administrator',
      demoted_to_administrator: 'Demoted to Administrator',
      administrator_account_deactivated: 'Administrator account deactivated',
      administrator_account_reactivated: 'Administrator account reactivated',
    }[type] || 'Security activity'
  );
}
function securitySessionMarkup(item) {
  const ip =
    isAdminUiRole(state.role) && item.ip ? ` · IP: ${esc(item.ip)}` : '';
  return `<article class="security-row security-session-row${item.is_current ? ' is-current' : ''}" data-session-id="${Number(item.id)}"><div><h3>${esc(item.device)}</h3><p>${item.is_current ? '<strong>This device</strong> · ' : ''}Last active: ${esc(securityLastActive(item))}</p><p class="security-row-detail">Signed in: ${esc(securityDateTime(item.created_at))}${ip}</p></div>${item.is_current ? '' : `<button type="button" class="btn outline sm" onclick="securitySignOutSession(${Number(item.id)},this)">Sign out</button>`}</article>`;
}
function securityRenderSessions(sessions) {
  const target = $('#security-sessions');
  if (!target) return;
  target.innerHTML = sessions.length
    ? sessions.map(securitySessionMarkup).join('')
    : '<p class="muted">No active sessions found.</p>';
  const other = sessions.some((item) => !item.is_current),
    allButton = $('#security-sign-out-others');
  if (allButton) allButton.disabled = !other;
}
function securityRenderEvents(result) {
  const target = $('#security-events'),
    pagination = $('#security-pagination');
  if (!target || !pagination) return;
  securityActivityPage = Number(result.page) || 1;
  target.innerHTML = result.events?.length
    ? result.events
        .map(
          (item) =>
            `<article class="security-row"><div><h3>${esc(securityEventLabel(item.type))}</h3><p>${esc(securityDateTime(item.occurred_at))}</p><p class="security-row-detail">${esc(item.device || 'Unknown device')}${isAdminUiRole(state.role) && item.ip ? ` · IP: ${esc(item.ip)}` : ''}</p></div></article>`,
        )
        .join('')
    : '<p class="muted">No security activity yet.</p>';
  const pages = Number(result.total_pages) || 1;
  pagination.innerHTML =
    pages > 1
      ? `<button type="button" class="btn outline sm" onclick="securityLoadActivity(${securityActivityPage - 1},true)" ${securityActivityPage <= 1 ? 'disabled' : ''}>Previous</button><span>Page ${securityActivityPage} of ${pages}</span><button type="button" class="btn outline sm" onclick="securityLoadActivity(${securityActivityPage + 1},true)" ${securityActivityPage >= pages ? 'disabled' : ''}>Next</button>`
      : '';
}
async function securityFetch(page) {
  const response = await fetch(
      new URL(
        `api/security.php?page=${encodeURIComponent(page)}`,
        window.location.href,
      ),
      { credentials: 'same-origin' },
    ),
    result = await response.json();
  if (!response.ok || !result.success)
    throw new Error(result.message || 'Unable to load security information.');
  return result;
}
async function securityLoad(page) {
  try {
    const result = await securityFetch(page);
    if (!securityRouteVisible()) return;
    securityRenderSessions(result.sessions || []);
    securityRenderEvents(result);
  } catch (error) {
    if (!securityRouteVisible()) return;
    const sessions = $('#security-sessions'),
      events = $('#security-events');
    if (sessions) sessions.textContent = 'Unable to load active sessions.';
    if (events) events.textContent = 'Unable to load security activity.';
    toast(error.message || 'Unable to load security information.', 'error');
  }
}
async function securityLoadActivity(page, scrollAfter = false) {
  try {
    const result = await securityFetch(page);
    if (securityRouteVisible()) {
      securityRenderEvents(result);
      if (scrollAfter) paginationScroll('#security-events-heading');
    }
  } catch (error) {
    toast(error.message || 'Unable to load security activity.', 'error');
  }
}
/** Sends session-revocation actions through the authenticated security API. */
async function securityPost(payload) {
  const response = await fetch(
      new URL('api/security.php', window.location.href),
      {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      },
    ),
    result = await response.json();
  if (!response.ok || !result.success)
    throw new Error(result.message || 'Unable to update sessions.');
  return result;
}
async function securitySignOutSession(id, button) {
  if (!Number.isSafeInteger(id) || id < 1) return;
  setButtonBusy(button, true, 'Signing out…');
  try {
    await securityPost({ action: 'revoke', session_id: id });
    const row = $(`.security-session-row[data-session-id="${id}"]`);
    row?.remove();
    if (!$('#security-sessions .security-session-row:not(.is-current)'))
      $('#security-sign-out-others').disabled = true;
    toast('The other session was signed out.', 'success');
    securityLoadActivity(1);
  } catch (error) {
    toast(error.message || 'Unable to sign out that session.', 'error');
  } finally {
    if (button?.isConnected) setButtonBusy(button, false);
  }
}
async function securitySignOutOthers() {
  const approved = await confirmAction({
    title: 'Sign out other sessions?',
    message:
      'Your current device will stay signed in. Other devices will need to log in again.',
    confirmLabel: 'Sign out others',
    tone: 'warning',
  });
  if (!approved) return;
  const button = $('#security-sign-out-others');
  setButtonBusy(button, true, 'Signing out…');
  try {
    await securityPost({ action: 'revoke_others' });
    document
      .querySelectorAll(
        '#security-sessions .security-session-row:not(.is-current)',
      )
      .forEach((row) => row.remove());
    toast('All other sessions were signed out.', 'success');
    securityLoadActivity(1);
  } catch (error) {
    toast(error.message || 'Unable to sign out other sessions.', 'error');
  } finally {
    if (button?.isConnected) {
      setButtonBusy(button, false);
      button.disabled = !$(
        '#security-sessions .security-session-row:not(.is-current)',
      );
    }
  }
}
