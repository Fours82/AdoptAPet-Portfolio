let adminUserSearchTimer = null;
let adminUserCache = [];
let adminUserPage = 1;
const adminUserPageSize = 10;

function databaseAdminUsersPage() {
  setTimeout(loadAdminUsers, 0);
  if (state.role === 'Super Administrator') setTimeout(loadPrivilegedUsers, 0);
  return shell(
    `<div class="admin-page-heading"><div class="page-title"><span class="eyebrow">Account administration</span><h1>User Management</h1><p>Search Registered User accounts and manage access status.</p></div></div><section class="panel admin-pet-filters" aria-labelledby="admin-user-filter-title"><div class="admin-filter-heading"><div><h2 id="admin-user-filter-title">Find Registered Users</h2><p class="muted">Search account details or focus on a specific access state.</p></div><button id="admin-user-clear" class="btn outline sm" type="button" onclick="clearAdminUserFilters()" hidden>Clear Filters</button></div><div class="admin-user-filter-grid"><div class="field"><label for="user-search">Username, full name or email</label><input id="user-search" class="input" maxlength="255" placeholder="Search Registered Users" oninput="scheduleAdminUserSearch()"></div><div class="field"><label for="user-status-filter">Account status</label><select id="user-status-filter" onchange="loadAdminUsers()"><option value="">All statuses</option><option value="active">Active</option><option value="suspended">Suspended</option><option value="deactivated">Deactivated</option></select></div></div></section><div class="admin-table-meta"><p id="admin-user-results" class="muted" aria-live="polite">Loading Registered User accounts…</p></div><section class="panel admin-user-table-panel"><div class="table-wrap"><table class="table admin-user-table"><thead><tr><th>User</th><th>Role</th><th>Joined</th><th>Verification</th><th>Status</th><th>Actions</th></tr></thead><tbody id="users-table" aria-busy="true">${skeletonRows(5, 6, 'Loading Registered User accounts')}</tbody></table></div><div id="admin-user-pagination"></div></section><section id="super-governance-panel" class="panel super-governance-panel" ${state.role === 'Super Administrator' ? '' : 'hidden'}><div class="section-head"><div><h2>Administrator roles</h2><p class="muted">Only Super Administrators can manage privileged accounts.</p></div></div><div class="table-wrap"><table class="table admin-user-table"><thead><tr><th>Account</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead><tbody id="privileged-users-table"><tr><td colspan="4">Loading Administrator accounts…</td></tr></tbody></table></div></section>`,
    true,
  );
}

function scheduleAdminUserSearch() {
  clearTimeout(adminUserSearchTimer);
  adminUserSearchTimer = setTimeout(loadAdminUsers, 250);
}

function adminUserStatusLabel(status) {
  return status.charAt(0).toUpperCase() + status.slice(1);
}

function adminUserActions(user) {
  if (user.account_status === 'active')
    return `<div class="admin-user-actions"><button class="btn admin-warning-action sm" type="button" onclick="setAdminUserStatus(${user.id},'suspended',this)">Suspend</button><button class="btn danger sm" type="button" onclick="setAdminUserStatus(${user.id},'deactivated',this)">Deactivate</button>${adminUserRoleActions(user)}</div>`;
  if (user.account_status === 'suspended')
    return `<div class="admin-user-actions"><button class="btn admin-positive-action sm" type="button" onclick="setAdminUserStatus(${user.id},'active',this)">Reactivate</button><button class="btn danger sm" type="button" onclick="setAdminUserStatus(${user.id},'deactivated',this)">Deactivate</button></div>`;
  if (user.email_verified)
    return `<div class="admin-user-actions"><button class="btn admin-positive-action sm" type="button" onclick="setAdminUserStatus(${user.id},'active',this)">Reactivate</button></div>`;
  return '<span class="muted">Awaiting verification</span>';
}

function adminUserRoleActions(user) {
  if (
    state.role !== 'Super Administrator' ||
    user.account_status !== 'active' ||
    !user.email_verified
  )
    return '';
  return `<button class="btn outline sm" type="button" onclick="sendGovernanceAction(${Number(user.id)},'promote_user',this)">Promote to Admin</button>`;
}

function privilegedRoleLabel(role) {
  switch (role) {
    case 'administrator':
      return 'Administrator';
    case 'super_administrator':
      return 'Super Administrator';
    default:
      return 'Unknown role';
  }
}
function privilegedUserActions(user, activeSuperCount) {
  if (Number(user.id) === Number(state.user?.id))
    return '<span class="muted">Current account</span>';
  let actions = [];
  if (user.role === 'administrator' && user.account_status === 'active') {
    actions.push(['demote_admin', 'Demote to Registered User']);
    actions.push(['deactivate_admin', 'Deactivate']);
    if (user.has_mfa)
      actions.push(['promote_admin', 'Promote to Super Administrator']);
  } else if (
    user.role === 'administrator' &&
    user.account_status === 'deactivated'
  )
    actions.push(['reactivate_admin', 'Reactivate']);
  else if (
    user.role === 'super_administrator' &&
    user.account_status === 'active'
  ) {
    if (activeSuperCount <= 1)
      return '<span class="muted">Last active Super Administrator</span>';
    actions.push(
      ['demote_super', 'Demote to Administrator'],
      ['deactivate_super', 'Deactivate'],
    );
  } else if (
    user.role === 'super_administrator' &&
    user.account_status === 'deactivated'
  )
    actions.push(['reactivate_super', 'Reactivate']);
  return actions.length
    ? `<div class="admin-user-actions">${actions.map(([action, label]) => `<button class="btn ${privilegedUserActionClass(action)} sm" type="button" onclick="sendGovernanceAction(${Number(user.id)},'${action}',this)">${label}</button>`).join('')}</div>`
    : '<span class="muted">No available action</span>';
}

function privilegedUserActionClass(action) {
  if (action.startsWith('deactivate')) return 'danger';
  if (action.startsWith('demote')) return 'admin-warning-action';
  if (action.startsWith('promote') || action.startsWith('reactivate'))
    return 'admin-positive-action';
  return 'outline';
}

// Privileged account governance
async function loadPrivilegedUsers() {
  const table = $('#privileged-users-table');
  if (!table || state.role !== 'Super Administrator') return;
  table.innerHTML =
    '<tr><td colspan="4">Loading Administrator accounts…</td></tr>';
  try {
    const response = await fetch(
        new URL('api/admin-role-governance.php', window.location.href),
        { credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success)
      throw new Error(
        result.message || 'Unable to load Administrator accounts.',
      );
    const users = result.users || [],
      activeSuperCount = users.filter(
        (user) =>
          user.role === 'super_administrator' &&
          user.account_status === 'active',
      ).length;
    table.innerHTML = users.length
      ? users
          .map(
            (user) =>
              `<tr><td data-label="Account"><span class="privileged-account-identity"><b>@${esc(user.username)}</b><span>${esc(user.name)}</span><small class="muted">${esc(user.email)}</small></span></td><td data-label="Role">${esc(privilegedRoleLabel(user.role))}</td><td data-label="Status">${esc(adminUserStatusLabel(user.account_status))}</td><td data-label="Actions">${privilegedUserActions(user, activeSuperCount)}</td></tr>`,
          )
          .join('')
      : '<tr><td colspan="4">No privileged accounts found.</td></tr>';
  } catch (error) {
    table.innerHTML = `<tr><td colspan="4" class="empty error-state">${esc(error.message || 'Unable to load Administrator accounts.')}</td></tr>`;
  }
}

/** Sends a fixed governance action; the API decides whether the transition is allowed. */
async function sendGovernanceAction(id, action, button = null) {
  if (state.role !== 'Super Administrator') return;
  const descriptions = {
    promote_user: [
      'Promote to Administrator',
      'All sessions will be signed out. The next login must complete Administrator MFA enrollment.',
    ],
    demote_admin: [
      'Demote to Registered User',
      'All sessions will be signed out. Administrator MFA and recovery codes will be removed. If the email is unverified, this account may need to verify it before using Registered User features.',
    ],
    deactivate_admin: [
      'Deactivate Administrator',
      'All sessions will be signed out. Login will be blocked until reactivation.',
    ],
    reactivate_admin: [
      'Reactivate Administrator',
      'The account must sign in again and complete Administrator MFA.',
    ],
    promote_admin: [
      'Promote to Super Administrator',
      'All sessions will be signed out. The account must sign in again with MFA.',
    ],
    demote_super: [
      'Demote to Administrator',
      'All sessions will be signed out. Administrator MFA remains required.',
    ],
    deactivate_super: [
      'Deactivate Super Administrator',
      'All sessions will be signed out. Login will be blocked until reactivation.',
    ],
    reactivate_super: [
      'Reactivate Super Administrator',
      'The account must sign in again and complete MFA.',
    ],
  };
  const [title, message] = descriptions[action] || [];
  if (!title) return;
  if (
    !(await confirmAction({
      title: `${title}?`,
      message,
      confirmLabel: title,
      tone:
        action.startsWith('deactivate') || action.startsWith('demote')
          ? 'danger'
          : 'positive',
    }))
  )
    return;
  setButtonBusy(button, true, 'Updating…');
  try {
    const response = await fetch(
        new URL('api/admin-role-governance.php', window.location.href),
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action, target_id: Number(id) }),
        },
      ),
      result = await response.json();
    if (!response.ok || !result.success) {
      toast(result.message || 'Unable to update account access.', 'error');
      return;
    }
    toast(result.message, 'success');
    loadAdminUsers();
    loadPrivilegedUsers();
  } catch (error) {
    toast('Unable to update account access. Please try again later.', 'error');
  } finally {
    if (button?.isConnected) setButtonBusy(button, false);
  }
}

function databaseUsersHtml(users) {
  return users.length
    ? users
        .map(
          (user) =>
            `<tr><td data-label="User"><div class="admin-person-cell admin-user-identity-cell"><span class="admin-person-avatar" aria-hidden="true">${esc(
              String(user.username || user.name || '?')
                .trim()
                .charAt(0)
                .toUpperCase() || '?',
            )}</span><span><b class="admin-user-username">${esc(user.username)}</b><small class="admin-user-full-name">${esc(user.name)}</small><small class="admin-user-email">${esc(user.email)}</small><small class="admin-user-location">${esc(user.location || 'Location not provided')}</small></span></div></td><td data-label="Role"><span class="badge">${esc(petStatusLabel(user.role))}</span></td><td data-label="Joined" class="admin-user-joined"><span class="admin-table-primary"><b>${date(user.created_at)}</b><small>User #${user.id}</small></span></td><td data-label="Verification"><span class="badge ${user.email_verified ? 'approved' : 'pending'}">${user.email_verified ? 'Verified' : 'Unverified'}</span></td><td data-label="Status"><span class="badge ${esc(user.account_status)}">${esc(adminUserStatusLabel(user.account_status))}</span></td><td data-label="Actions">${adminUserActions(user)}</td></tr>`,
        )
        .join('')
    : '<tr><td colspan="6" class="empty"><h2>No matching Registered Users</h2><p>Try a different username, full name, email, or account status.</p></td></tr>';
}

// Registered User search and status management
async function loadAdminUsers() {
  let table = $('#users-table'),
    controls = $('#admin-user-pagination');
  if (!table) return;
  let query = $('#user-search')?.value.trim() || '',
    status = $('#user-status-filter')?.value || '';
  setSkeleton(table, skeletonRows(5, 6, 'Loading Registered User accounts'));
  if (controls) controls.innerHTML = '';
  adminUserPage = 1;
  try {
    let url = new URL('api/admin-users.php', window.location.href);
    if (query) url.searchParams.set('q', query);
    if (status) url.searchParams.set('status', status);
    let response = await fetch(url, { credentials: 'same-origin' }),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    adminUserCache = result.users || [];
    clearLoading(table);
    renderAdminUsers();
    let count = $('#admin-user-results'),
      clear = $('#admin-user-clear');
    if (count && adminUserCache.length === 0)
      count.textContent = '0 matching Registered Users';
    if (clear) clear.hidden = !(query || status);
  } catch (error) {
    clearLoading(table);
    let count = $('#admin-user-results');
    if (count) count.textContent = 'Unable to load Registered User accounts';
    table.innerHTML = `<tr><td colspan="5" class="empty">${esc(error.message || 'Unable to load Registered User accounts.')}</td></tr>`;
  }
}

function renderAdminUsers() {
  let table = $('#users-table'),
    count = $('#admin-user-results'),
    controls = $('#admin-user-pagination');
  if (!table) return;
  let page = paginatedItems(adminUserCache, adminUserPage, adminUserPageSize);
  adminUserPage = page.page;
  if (count)
    count.textContent = page.total
      ? `Showing ${page.start + 1}–${page.end} of ${page.total} matching Registered Users`
      : '0 matching Registered Users';
  table.innerHTML = databaseUsersHtml(page.items);
  if (controls)
    controls.innerHTML = paginationMarkup(
      page.page,
      page.totalPages,
      'changeAdminUserPage',
      'User Management pages',
    );
}
function changeAdminUserPage(page) {
  let next = paginatedItems(adminUserCache, page, adminUserPageSize);
  if (next.page === adminUserPage) return;
  adminUserPage = next.page;
  renderAdminUsers();
  paginationScroll('#admin-user-results');
}

function clearAdminUserFilters() {
  clearTimeout(adminUserSearchTimer);
  let search = $('#user-search'),
    status = $('#user-status-filter');
  if (search) search.value = '';
  if (status) status.value = '';
  loadAdminUsers();
}

async function setAdminUserStatus(id, accountStatus, button = null) {
  let action =
    accountStatus === 'active'
      ? 'reactivate'
      : accountStatus === 'suspended'
        ? 'suspend'
        : 'deactivate';
  let user = adminUserCache.find((item) => Number(item.id) === Number(id)),
    identity = user ? `${user.name} (${user.email})` : `Registered User #${id}`,
    impact =
      accountStatus === 'active'
        ? 'They will regain access to their account.'
        : 'They will be unable to sign in until reactivated.';
  if (
    !(await confirmAction({
      title: `${action.charAt(0).toUpperCase() + action.slice(1)} ${identity}?`,
      message: impact,
      confirmLabel: action.charAt(0).toUpperCase() + action.slice(1),
      tone: accountStatus === 'active' ? 'positive' : 'danger',
    }))
  )
    return;
  setButtonBusy(button, true, 'Updating…');
  try {
    let url = new URL('api/admin-users.php', window.location.href);
    url.searchParams.set('id', id);
    let response = await fetch(url, {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ account_status: accountStatus }),
      }),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to update this account.');
    toast(result.message);
    loadAdminUsers();
  } catch (error) {
    toast('Unable to update this account. Please try again later.');
  } finally {
    if (button?.isConnected) setButtonBusy(button, false);
  }
}

render();
