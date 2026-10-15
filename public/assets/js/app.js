const state = {
  role: 'Guest',
  user: null,
  pendingMfa: null,
  pendingVerificationEmail:
    sessionStorage.getItem('pending-verification-email') || '',
  recoveryEmail: '',
  recoveryCaptchaReusable: false,
  recoveryCaptchaExpiresAt: 0,
  notificationUnread: 0,
  authReady: false,
  csrfToken: '',
};
function isAdminUiRole(role) {
  return role === 'Administrator' || role === 'Super Administrator';
}
function isAdminApiRole(role) {
  return role === 'administrator' || role === 'super_administrator';
}
/** Maps API role identifiers to the labels used throughout the interface. */
function apiRoleToUi(role) {
  switch (role) {
    case 'registered_user':
      return 'Registered User';
    case 'administrator':
      return 'Administrator';
    case 'super_administrator':
      return 'Super Administrator';
    default:
      return null;
  }
}
sessionStorage.removeItem('pending-reset-email');
const nativeFetch = window.fetch.bind(window);
let sessionExpiryFeedbackPending = false,
  recoveryExpiryTimer = null;
let lastToastMessage = '',
  lastToastAt = 0;
let renderedRoute = routePath(location.hash.slice(1) || '/'),
  routeTopResetPending = false;
/** Clears local account state after the API reports an expired or revoked session. */
async function handleAuthenticationResponse(response, url) {
  if (response.status !== 401 || url.origin !== window.location.origin) return;
  let result;
  try {
    result = await response.clone().json();
  } catch (error) {
    return;
  }
  let expired = ['session_expired', 'session_revoked'].includes(result?.code);
  if (!expired || sessionExpiryFeedbackPending) return;
  sessionExpiryFeedbackPending = true;
  state.role = 'Guest';
  state.user = null;
  state.pendingMfa = null;
  state.csrfToken = '';
  clearRecoveryUxState();
  state.notificationUnread = 0;
  toast(
    result.message || 'Your session has expired. Please sign in again.',
    'warning',
  );
  if (location.hash !== '#/login') go('/login');
  else if (state.authReady) render();
  setTimeout(() => {
    sessionExpiryFeedbackPending = false;
  }, 1200);
}
window.fetch = async (input, init = {}) => {
  const request = input instanceof Request ? input : null,
    url = new URL(request ? request.url : String(input), window.location.href),
    method = String(
      init.method || (request && request.method) || 'GET',
    ).toUpperCase();
  const chatReadMutation =
    method === 'GET' &&
    (url.pathname.endsWith('/api/chat.php') ||
      url.pathname.endsWith('/api/admin-chat.php')) &&
    url.searchParams.has('conversation_id');
  if (
    url.origin === window.location.origin &&
    (!['GET', 'HEAD', 'OPTIONS'].includes(method) || chatReadMutation) &&
    state.csrfToken
  ) {
    const headers = new Headers(
      init.headers || (request && request.headers) || undefined,
    );
    if (!headers.has('X-CSRF-Token'))
      headers.set('X-CSRF-Token', state.csrfToken);
    init = { ...init, headers };
  }
  const response = await nativeFetch(input, init);
  await handleAuthenticationResponse(response, url);
  return response;
};
const captchaWidgets = new Map();
const $ = (s) => document.querySelector(s);
const money = (n) =>
  new Intl.NumberFormat('en-MY', { style: 'currency', currency: 'MYR' }).format(
    n,
  );
const date = (d) =>
  new Date(d).toLocaleDateString('en-MY', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  });
const esc = (s) =>
  String(s || '').replace(
    /[&<>'"]/g,
    (c) =>
      ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#039;',
        '"': '&quot;',
      })[c],
  );
const usernamePattern = /^[A-Za-z0-9_]{3,20}$/;
function toast(msg, type = '') {
  const text = String(msg || '').trim();
  if (!text) return;
  const now = Date.now();
  if (text === lastToastMessage && now - lastToastAt < 1500) return;
  lastToastMessage = text;
  lastToastAt = now;
  const inferred =
      /unable|error|invalid|required|not found|failed|expired|security token|does not match|enter |please /i.test(
        text,
      )
        ? 'error'
        : /warning|attention|cannot|already /i.test(text)
          ? 'warning'
          : /success|submitted|sent|saved|updated|verified|created|published|recorded|reset|complete/i.test(
                text,
              )
            ? 'success'
            : 'info',
    tone = ['success', 'error', 'warning', 'info'].includes(type)
      ? type
      : inferred,
    titles = {
      success: 'Success',
      error: 'Something went wrong',
      warning: 'Please note',
      info: 'Information',
    },
    icons = {
      success: 'check',
      error: 'alert',
      warning: 'alert',
      info: 'bell',
    },
    region = $('#toast-region');
  if (!region) return;
  const item = document.createElement('article');
  item.className = `toast toast-${tone}`;
  item.setAttribute('role', tone === 'error' ? 'alert' : 'status');
  item.innerHTML = `<span class="toast-icon" aria-hidden="true">${uiIcon(icons[tone])}</span><span class="toast-copy"><strong>${titles[tone]}</strong><span>${esc(text)}</span></span><button type="button" class="toast-dismiss" aria-label="Dismiss ${titles[tone].toLowerCase()} notification">&times;</button>`;
  region.append(item);
  const dismiss = () => {
      if (!item.isConnected) return;
      item.classList.add('toast-leaving');
      setTimeout(() => item.remove(), 190);
    },
    timer = setTimeout(
      dismiss,
      tone === 'error' ? 6000 : tone === 'warning' ? 5000 : 4200,
    );
  item.querySelector('.toast-dismiss')?.addEventListener('click', () => {
    clearTimeout(timer);
    dismiss();
  });
}
// Hash routing and navigation
function routePath(value) {
  let fragment = String(value || '/').replace(/^#/, '') || '/',
    queryAt = fragment.indexOf('?');
  return queryAt === -1 ? fragment : fragment.slice(0, queryAt);
}
function currentHashRoute() {
  return routePath(location.hash.slice(1) || '/');
}
function markRouteTopReset(path) {
  if (routePath(path) !== currentHashRoute()) routeTopResetPending = true;
}
function passwordResetTokenFromHash() {
  let fragment = location.hash.slice(1),
    queryAt = fragment.indexOf('?');
  if (queryAt === -1 || fragment.slice(0, queryAt) !== '/reset-password')
    return '';
  return new URLSearchParams(fragment.slice(queryAt + 1)).get('token') || '';
}
function go(path) {
  closeAccountMenu();
  markRouteTopReset(path);
  location.hash = path;
}
function link(path, label) {
  let route = currentHashRoute(),
    active =
      route === path || (path === '/account' && route.startsWith('/account/'));
  return `<a href="#${path}" class="${active ? 'active' : ''}">${label}</a>`;
}
function toggleNavigation(button) {
  let nav = $('#primary-navigation');
  if (!nav) return;
  let open = nav.classList.toggle('open');
  button.setAttribute('aria-expanded', String(open));
  button.setAttribute(
    'aria-label',
    open ? 'Close navigation menu' : 'Open navigation menu',
  );
  button.textContent = open ? 'Close' : 'Menu';
}
function accountDisplayName(user = state.user) {
  return isAdminApiRole(user?.role)
    ? user?.name || 'Administrator'
    : user?.username || user?.name || 'Registered User';
}
function accountAvatarMarkup(user = state.user) {
  let name = accountDisplayName(user),
    path = user?.profile_image_path || '';
  return path
    ? `<img src="${esc(path)}" alt="${esc(name)} profile photo">`
    : `<span aria-hidden="true">${esc(name.charAt(0).toUpperCase() || 'U')}</span>`;
}
function registeredAccountControl() {
  return `<div class="account-menu"><button type="button" id="account-menu-button" class="account-avatar-button" aria-label="Open account menu" title="Account menu" aria-haspopup="menu" aria-expanded="false" onclick="toggleAccountMenu(event)">${accountAvatarMarkup()}</button><div id="account-dropdown" class="account-dropdown" role="menu" hidden><div class="account-dropdown-head"><b>${esc(state.user?.username || state.user?.name || 'My Account')}</b><small>${esc(state.user?.name || '')}</small><small>${esc(state.user?.email || '')}</small></div><a role="menuitem" href="#/account">Account</a><a role="menuitem" href="#/applications">My Applications</a><a role="menuitem" href="#/activity">My Activity</a><button type="button" role="menuitem" onclick="logout()">Log out</button></div></div>`;
}
function administratorAccountControl() {
  return `<div class="account-menu admin-account-menu"><button type="button" id="account-menu-button" class="account-avatar-button" aria-label="Open Administrator account menu" title="Administrator account" aria-haspopup="menu" aria-expanded="false" onclick="toggleAccountMenu(event)">${accountAvatarMarkup(state.user || { name: 'Administrator' })}</button><div id="account-dropdown" class="account-dropdown" role="menu" hidden><div class="account-dropdown-head"><b>${esc(state.user?.name || 'Administrator')}</b><small>${esc(state.user?.email || 'Administrator account')}</small></div><a role="menuitem" href="#/account">Account</a></div></div>`;
}
function toggleAccountMenu(event) {
  event?.stopPropagation();
  let button = $('#account-menu-button'),
    menu = $('#account-dropdown');
  if (!button || !menu) return;
  let opening = menu.hidden;
  menu.hidden = !opening;
  button.setAttribute('aria-expanded', String(opening));
  if (opening) menu.querySelector('[role="menuitem"]')?.focus();
}
function closeAccountMenu() {
  let button = $('#account-menu-button'),
    menu = $('#account-dropdown');
  if (menu) menu.hidden = true;
  if (button) button.setAttribute('aria-expanded', 'false');
}
function updateCurrentUserProfile(profile) {
  if (!profile) return;
  state.user = {
    ...(state.user || {}),
    username: profile.username || state.user?.username,
    name: profile.name || state.user?.name,
    email: profile.email || state.user?.email,
    profile_image_path: profile.profile_image_path || null,
  };
  let button = $('#account-menu-button');
  if (button) button.innerHTML = accountAvatarMarkup();
}
function publicBrandLogo(className = '') {
  return `<img class="public-brand-logo${className ? ` ${className}` : ''}" src="assets/images/adoptapet-logo.png" alt="AdoptAPet">`;
}
function uiIcon(name, className = '') {
  let paths = {
    user: '<path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/>',
    mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
    lock: '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
    eye: '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
    'eye-off':
      '<path d="m3 3 18 18"/><path d="M10.6 10.6a2 2 0 0 0 2.8 2.8M9.9 4.2A10.8 10.8 0 0 1 12 4c6.5 0 10 8 10 8a17 17 0 0 1-2.1 3.2M6.6 6.6C3.6 8.5 2 12 2 12s3.5 8 10 8a10 10 0 0 0 4.4-1"/>',
    paw: '<circle cx="12" cy="15" r="4"/><circle cx="5.5" cy="10" r="2"/><circle cx="9" cy="5.5" r="2"/><circle cx="15" cy="5.5" r="2"/><circle cx="18.5" cy="10" r="2"/>',
    pin: '<path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/>',
    camera:
      '<path d="M14.5 4 16 7h3a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2h3l1.5-3h5Z"/><circle cx="12" cy="13" r="3"/>',
    alert:
      '<path d="M10.3 3.8 2.2 18a2 2 0 0 0 1.7 3h16.2a2 2 0 0 0 1.7-3L13.7 3.8a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/>',
    heart:
      '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1.1L12 21l7.8-7.5 1.1-1.1a5.5 5.5 0 0 0-.1-7.8Z"/>',
    coin: '<circle cx="12" cy="12" r="9"/><path d="M16 8.5c-.8-.7-2-1-3.3-1-1.8 0-3.2.9-3.2 2.3 0 3.5 6.5 1.4 6.5 4.8 0 1.4-1.4 2.4-3.4 2.4-1.5 0-2.9-.5-3.8-1.4M12.5 5.5v13"/>',
    card: '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
    receipt:
      '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2V3Z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
    home: '<path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10M9 20v-6h6v6"/>',
    file: '<path d="M6 2h9l4 4v16H6V2Z"/><path d="M14 2v5h5M9 12h6M9 16h6"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
    phone:
      '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.9a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.2-1.2a2 2 0 0 1 2.1-.5c.9.3 1.9.6 2.9.7a2 2 0 0 1 1.7 2Z"/>',
    bell: '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
    calendar:
      '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
    check: '<path d="m5 12 4 4L19 6"/>',
    image:
      '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
  };
  return `<svg class="ui-icon${className ? ` ${className}` : ''}" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${paths[name] || paths.paw}</svg>`;
}
function pageHero(kind, title, description, action = '') {
  return `<section class="page-hero page-hero--${esc(kind)}"><div class="page-hero-content"><h1>${esc(title)}</h1><p>${esc(description)}</p></div>${action ? `<div class="page-hero-action">${action}</div>` : ''}</section>`;
}
function notificationNavButton(path, unread) {
  let active = currentHashRoute() === path;
  return `<button type="button" id="notification-nav-button" class="${active ? 'active' : ''}"${active ? ' aria-current="page"' : ''} onclick="go('${path}')">Notifications${unread ? ` <b>${unread}</b>` : ''}</button>`;
}
function header(adminWorkspace = false) {
  const unread = Number(state.notificationUnread) || 0,
    isAdmin = isAdminUiRole(state.role);
  if (isAdmin && adminWorkspace)
    return `<header class="topbar admin-topbar"><a class="brand admin-brand" href="#/admin" aria-label="AdoptAPet Administrator workspace">${publicBrandLogo('admin-brand-logo')}<small class="admin-brand-context">Admin Workspace</small></a><nav class="nav admin-workspace-actions" aria-label="Administrator account actions"><a class="admin-public-site-link" href="#/">View Public Site</a>${notificationNavButton('/admin/notifications', unread)}${administratorAccountControl()}<button type="button" class="nav-logout admin-logout" onclick="logout()">Log out</button></nav></header>`;
  let signedIn =
    state.role === 'Registered User'
      ? `${notificationNavButton('/notifications', unread)}${registeredAccountControl()}`
      : isAdmin
        ? `<a href="#/admin">Admin Dashboard</a>${notificationNavButton('/admin/notifications', unread)}${administratorAccountControl()}<button type="button" class="nav-logout admin-logout" onclick="logout()">Log out</button>`
        : '';
  return `<header class="topbar public-topbar"><div class="public-topbar-inner"><a class="brand public-brand" href="#/" aria-label="AdoptAPet home">${publicBrandLogo()}</a><nav id="primary-navigation" class="nav" aria-label="Primary navigation">${link('/pets', 'Pet Listings')}${link('/strays', 'Stray Reports')}${link('/news', 'News')}${state.role === 'Registered User' ? link('/community', 'Community') : ''}${link('/donations', 'Donation Board')}${state.role === 'Guest' ? `<a href="#/login">Log in</a><a class="btn sm" href="#/register">Sign up</a>` : signedIn}</nav><button type="button" class="mobile-menu btn outline sm" onclick="toggleNavigation(this)" aria-controls="primary-navigation" aria-expanded="false" aria-label="Open navigation menu">Menu</button></div></header>`;
}
function sidebar(admin = false) {
  let x = admin
    ? [
        ['/admin', 'Overview'],
        ['/admin/pets', 'Pet Listings'],
        ['/admin/strays', 'Stray Reports'],
        ['/admin/applications', 'Adoption Applications'],
        ['/admin/users', 'User Management'],
        ['/admin/analytics', 'Analytics & Reporting'],
        ['/admin/audit', 'Audit Log'],
        ['/admin/news', 'News Management'],
        ['/admin/community', 'Community Moderation'],
        ['/admin/chat', 'Live Chat Support'],
        ['/admin/notifications', 'Notifications'],
      ]
    : [
        ['/account', 'Account'],
        ['/applications', 'My Applications'],
        ['/strays', 'Stray Reports'],
        ['/report-stray', 'Report a Stray'],
        ['/community', 'Community'],
        ['/donate', 'Make Donation'],
        ['/donations', 'Donation Board'],
        ['/notifications', 'Notifications'],
        ['/chat', 'Live Chat Support'],
        ['/news', 'News'],
        ['/password', 'Change Password'],
      ];
  return `<aside class="sidebar ${admin ? 'admin-sidebar' : ''}" aria-label="${admin ? 'Administrator' : 'Registered User'} navigation"><div class="sidebar-label">${admin ? 'Administrator workspace' : 'My account'}</div>${x.map(([p, n]) => link(p, n)).join('')}</aside>`;
}
function footer() {
  let community =
      state.role === 'Registered User'
        ? '<a href="#/community">Community</a>'
        : '',
    supportAction =
      state.role === 'Registered User'
        ? '<a href="#/chat">Live Chat Support</a>'
        : isAdminUiRole(state.role)
          ? '<a href="#/admin">Admin Dashboard</a>'
          : '<a href="#/register">Create an account</a>';
  return `<footer class="site-footer"><div class="footer-intro"><a class="brand footer-brand public-brand" href="#/" aria-label="AdoptAPet home">${publicBrandLogo('footer-brand-logo')}</a><p>Helping rescued pets and caring adopters find one another in Malaysia.</p></div><div class="footer-links"><b>Quick links</b><nav aria-label="Footer navigation"><a href="#/pets">Pets</a><a href="#/strays">Stray Reports</a>${community}<a href="#/news">News</a><a href="#/donations">Donation Board</a></nav></div><div class="footer-support"><b>Support</b><nav aria-label="Footer support">${supportAction}</nav></div><small>© ${new Date().getFullYear()} AdoptAPet</small></footer>`;
}
function floatingChatShortcut() {
  let route = location.hash.slice(1) || '/';
  return state.role === 'Registered User' && !route.startsWith('/chat')
    ? '<a class="floating-chat-shortcut" href="#/chat" aria-label="Open Live Chat Support" title="Live Chat Support"><span aria-hidden="true">☏</span><span>Chat</span></a>'
    : '';
}
// Shared page layout
function shell(content, admin = false) {
  let route = currentHashRoute(),
    adminRoute =
      route === '/admin' ||
      route.startsWith('/admin/') ||
      route === '/account' ||
      route.startsWith('/account/'),
    adminLayout = admin && isAdminUiRole(state.role) && adminRoute;
  return `<div class="shell ${adminLayout ? 'admin-workspace-shell' : 'public-site-shell'}">${header(adminLayout)}<main class="page ${adminLayout ? 'admin-workspace-page' : 'public-site-page'}">${adminLayout ? `<div class="layout admin-workspace-layout">${sidebar(true)}<section>${content}</section></div>` : content}</main>${adminLayout ? '' : floatingChatShortcut()}${adminLayout ? '' : footer()}</div>`;
}
function home() {
  return typeof databaseHomePage === 'function'
    ? databaseHomePage()
    : shell(
        '<section class="panel empty loading-state">Loading AdoptAPet…</section>',
      );
}
function emailInput(
  id,
  {
    value = '',
    placeholder = 'name@example.com',
    readonly = false,
    required = true,
    oninput = '',
  } = {},
) {
  return `<div class="field-with-icon">${uiIcon('mail', 'input-icon')}<input id="${esc(id)}" name="email" type="email" class="input" autocomplete="email"${placeholder ? ` placeholder="${esc(placeholder)}"` : ''}${value ? ` value="${esc(value)}"` : ''}${readonly ? ' readonly' : ''}${required ? ' required' : ''}${oninput ? ` oninput="${esc(oninput)}"` : ''}></div>`;
}
function passwordInput(id, placeholder, autocomplete = '') {
  return `<div class="password-wrap field-with-icon">${uiIcon('lock', 'input-icon')}<input id="${id}" type="password" class="input" placeholder="${placeholder}"${autocomplete ? ` autocomplete="${esc(autocomplete)}"` : ''}><button type="button" class="password-toggle" onclick="togglePassword('${id}',this)" aria-label="Show password" title="Show password">${uiIcon('eye')}</button></div>`;
}
function togglePassword(id, button) {
  let input = $(`#${id}`),
    show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  button.innerHTML = uiIcon(show ? 'eye-off' : 'eye');
  button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  button.title = show ? 'Hide password' : 'Show password';
}
function setButtonBusy(button, busy, label = 'Working…') {
  if (!button) return false;
  if (busy) {
    if (button.getAttribute('aria-busy') === 'true') return false;
    if (!button.dataset.originalHtml)
      button.dataset.originalHtml = button.innerHTML;
    button.dataset.originalDisabled = String(button.disabled);
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.classList.add('button-loading');
    button.innerHTML = `<span class="button-spinner" aria-hidden="true"></span><span>${esc(label)}</span>`;
    return true;
  }
  button.innerHTML = button.dataset.originalHtml || button.innerHTML;
  button.disabled = button.dataset.originalDisabled === 'true';
  button.removeAttribute('aria-busy');
  button.classList.remove('button-loading');
  delete button.dataset.originalHtml;
  delete button.dataset.originalDisabled;
  return true;
}
function skeletonCards(count = 6, label = 'Loading content') {
  return `<div class="skeleton-status skeleton-card-grid" role="status" aria-label="${esc(label)}"><span class="sr-only">${esc(label)}</span>${Array.from({ length: count }, () => '<div class="skeleton-card" aria-hidden="true"><span class="skeleton-block skeleton-media"></span><span class="skeleton-card-body"><span class="skeleton-block skeleton-line wide"></span><span class="skeleton-block skeleton-line medium"></span><span class="skeleton-block skeleton-line short"></span></span></div>').join('')}</div>`;
}
function skeletonList(count = 4, label = 'Loading content') {
  return `<div class="skeleton-status skeleton-list" role="status" aria-label="${esc(label)}"><span class="sr-only">${esc(label)}</span>${Array.from({ length: count }, () => '<div class="skeleton-list-item" aria-hidden="true"><span class="skeleton-block skeleton-avatar"></span><span class="skeleton-list-copy"><span class="skeleton-block skeleton-line wide"></span><span class="skeleton-block skeleton-line medium"></span><span class="skeleton-block skeleton-line short"></span></span></div>').join('')}</div>`;
}
function skeletonRows(rows = 5, columns = 5, label = 'Loading table') {
  return `<tr class="skeleton-table-status"><td colspan="${Math.max(1, Number(columns) || 1)}"><div class="skeleton-status skeleton-table" role="status" aria-label="${esc(label)}"><span class="sr-only">${esc(label)}</span>${Array.from({ length: rows }, () => `<div class="skeleton-table-row" aria-hidden="true">${Array.from({ length: Math.max(1, Number(columns) || 1) }, (_, index) => `<span class="skeleton-block skeleton-line ${index === 0 ? 'wide' : index === columns - 1 ? 'short' : 'medium'}"></span>`).join('')}</div>`).join('')}</div></td></tr>`;
}
function skeletonAnalytics(label = 'Loading analytics') {
  return `<div class="skeleton-status skeleton-analytics" role="status" aria-label="${esc(label)}"><span class="sr-only">${esc(label)}</span><div class="skeleton-metrics" aria-hidden="true">${Array.from({ length: 4 }, () => '<span class="skeleton-block skeleton-metric"></span>').join('')}</div><div class="skeleton-chart" aria-hidden="true">${[42, 68, 54, 82, 63, 76].map((height) => `<span class="skeleton-block" style="height:${height}%"></span>`).join('')}</div></div>`;
}
function setSkeleton(target, markup) {
  if (!target) return;
  target.setAttribute('aria-busy', 'true');
  target.innerHTML = markup;
}
function clearLoading(target) {
  target?.removeAttribute('aria-busy');
}
const liveSearchTimers = new Map();
function cancelLiveSearch(key) {
  let timer = liveSearchTimers.get(key);
  if (timer) clearTimeout(timer);
  liveSearchTimers.delete(key);
}
function scheduleLiveSearch(key, callback, delay = 300) {
  cancelLiveSearch(key);
  liveSearchTimers.set(
    key,
    setTimeout(() => {
      liveSearchTimers.delete(key);
      callback();
    }, delay),
  );
}
function runLiveSearchNow(event, key, callback) {
  if (event && event.type !== 'submit' && event.key !== 'Enter') return;
  event?.preventDefault();
  cancelLiveSearch(key);
  callback();
}
function paginationPageNumbers(currentPage, totalPages, windowSize = 5) {
  let current = Math.max(1, Number(currentPage) || 1),
    total = Math.max(1, Number(totalPages) || 1),
    size = Math.max(3, Number(windowSize) || 5),
    start = Math.max(1, current - Math.floor(size / 2)),
    end = Math.min(total, start + size - 1);
  start = Math.max(1, end - size + 1);
  let pages = [];
  for (let page = start; page <= end; page++) pages.push(page);
  return pages;
}
function paginationMarkup(
  currentPage,
  totalPages,
  onChange,
  label = 'Result pages',
) {
  let current = Math.max(1, Number(currentPage) || 1),
    total = Math.max(1, Number(totalPages) || 1);
  if (total <= 1) return '';
  let pages = paginationPageNumbers(current, total),
    first = pages[0],
    last = pages[pages.length - 1],
    pageButton = (page) =>
      `<button type="button" class="btn outline sm pagination-page" onclick="${onChange}(${page})" ${page === current ? 'aria-current="page"' : ''} aria-label="Page ${page}${page === current ? ', current page' : ''}">${page}</button>`,
    numbers = `${first > 1 ? pageButton(1) : ''}${first > 2 ? '<span class="pagination-ellipsis" aria-hidden="true">…</span>' : ''}${pages.map(pageButton).join('')}${last < total - 1 ? '<span class="pagination-ellipsis" aria-hidden="true">…</span>' : ''}${last < total ? pageButton(total) : ''}`;
  return `<nav class="catalog-pagination" aria-label="${esc(label)}"><button type="button" class="btn outline sm pagination-previous" onclick="${onChange}(${current - 1})" ${current === 1 ? 'disabled' : ''}>Previous</button><span class="pagination-pages">${numbers}</span><button type="button" class="btn outline sm pagination-next" onclick="${onChange}(${current + 1})" ${current === total ? 'disabled' : ''}>Next</button></nav>`;
}
function paginatedItems(items, currentPage, pageSize) {
  let list = Array.isArray(items) ? items : [],
    size = Math.max(1, Number(pageSize) || 10),
    totalPages = Math.max(1, Math.ceil(list.length / size)),
    page = Math.min(Math.max(1, Number(currentPage) || 1), totalPages),
    start = list.length ? (page - 1) * size : 0,
    end = Math.min(start + size, list.length);
  return {
    items: list.slice(start, end),
    page,
    pageSize: size,
    totalPages,
    start,
    end,
    total: list.length,
  };
}
function paginationScroll(selector) {
  let target = $(selector);
  if (!target) return;
  let header = $('.topbar'),
    offset = (header?.getBoundingClientRect().height || 0) + 12,
    top = target.getBoundingClientRect().top + window.scrollY - offset;
  window.scrollTo({ top: Math.max(0, top), behavior: 'instant' });
}
function completeRouteNavigation(event) {
  let eventRoute = event?.newURL
    ? routePath(new URL(event.newURL).hash.slice(1) || '/')
    : currentHashRoute();
  if (currentHashRoute() !== eventRoute) return;
  let shouldReset = routeTopResetPending && renderedRoute !== eventRoute;
  routeTopResetPending = false;
  renderedRoute = eventRoute;
  if (shouldReset) window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
}
let activeConfirmation = null;
let adminModalFocusReturn = null;
function beginAdminModal() {
  adminModalFocusReturn =
    document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;
}
function finishAdminModal() {
  let trigger = adminModalFocusReturn;
  adminModalFocusReturn = null;
  requestAnimationFrame(() => {
    if (trigger?.isConnected) trigger.focus();
  });
}
function handleAdminModalKeydown(event) {
  if ($('.confirmation-overlay') || $('#image-editor-modal')) return;
  let dialogs = Array.from(
      document.querySelectorAll(
        'body > .modal-overlay:not([hidden]) [data-admin-modal]',
      ),
    ),
    dialog = dialogs.at(-1);
  if (!dialog) return;
  if (event.key === 'Escape') {
    event.preventDefault();
    dialog.querySelector('[data-admin-modal-close]')?.click();
    return;
  }
  if (event.key !== 'Tab') return;
  let focusable = Array.from(
    dialog.querySelectorAll(
      'button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])',
    ),
  ).filter((item) => item.offsetParent !== null);
  if (!focusable.length) return;
  let first = focusable[0],
    last = focusable.at(-1);
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}
function confirmAction({
  title = 'Confirm this action?',
  message = '',
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  tone = 'warning',
} = {}) {
  if (activeConfirmation) return Promise.resolve(false);
  const safeTone = ['danger', 'positive', 'warning'].includes(tone)
      ? tone
      : 'warning',
    trigger =
      document.activeElement instanceof HTMLElement
        ? document.activeElement
        : null,
    overlay = document.createElement('div');
  overlay.className = 'confirmation-overlay';
  overlay.innerHTML = `<section class="confirmation-dialog confirmation-${safeTone}" role="${safeTone === 'danger' ? 'alertdialog' : 'dialog'}" aria-modal="true" aria-labelledby="confirmation-title" aria-describedby="confirmation-message"><span class="confirmation-icon" aria-hidden="true">${uiIcon(safeTone === 'positive' ? 'check' : 'alert')}</span><div class="confirmation-copy"><h2 id="confirmation-title">${esc(title)}</h2><p id="confirmation-message">${esc(message)}</p></div><div class="confirmation-actions"><button type="button" class="btn outline confirmation-cancel">${esc(cancelLabel)}</button><button type="button" class="btn confirmation-confirm ${safeTone === 'danger' ? 'danger' : ''}">${esc(confirmLabel)}</button></div></section>`;
  document.body.append(overlay);
  const dialog = overlay.querySelector('.confirmation-dialog'),
    cancel = overlay.querySelector('.confirmation-cancel'),
    confirm = overlay.querySelector('.confirmation-confirm');
  return new Promise((resolve) => {
    let settled = false;
    const finish = (result) => {
        if (settled) return;
        settled = true;
        document.removeEventListener('keydown', onKeydown);
        overlay.classList.add('confirmation-leaving');
        activeConfirmation = null;
        setTimeout(() => {
          overlay.remove();
          if (trigger?.isConnected) trigger.focus();
        }, 190);
        resolve(result);
      },
      onKeydown = (event) => {
        if (event.key === 'Escape') {
          event.preventDefault();
          finish(false);
          return;
        }
        if (event.key !== 'Tab') return;
        const focusable = Array.from(
          dialog.querySelectorAll(
            'button:not([disabled]),[href],input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])',
          ),
        );
        if (!focusable.length) return;
        const first = focusable[0],
          last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      };
    activeConfirmation = { finish };
    cancel.addEventListener('click', () => finish(false));
    confirm.addEventListener(
      'click',
      () => {
        confirm.disabled = true;
        finish(true);
      },
      { once: true },
    );
    overlay.addEventListener('click', (event) => {
      if (event.target === overlay) finish(false);
    });
    document.addEventListener('keydown', onKeydown);
    requestAnimationFrame(() => cancel.focus());
  });
}
// Authentication and recovery
function hcaptchaEnabled() {
  return Boolean(
    window.ADOPTAPET_SECURITY?.hcaptchaEnabled &&
      window.ADOPTAPET_SECURITY?.hcaptchaSiteKey,
  );
}
function hcaptchaMarkup(id, hidden = false) {
  if (!hcaptchaEnabled()) return '';
  return `<div id="${id}-wrap" class="captcha-field" ${hidden ? 'hidden' : ''}><div id="${id}" data-hcaptcha-container></div></div>`;
}
function mountHcaptcha(id) {
  if (!hcaptchaEnabled() || !window.hcaptcha) return;
  let target = document.getElementById(id);
  if (!target || captchaWidgets.has(id)) return;
  captchaWidgets.set(
    id,
    window.hcaptcha.render(target, {
      sitekey: window.ADOPTAPET_SECURITY.hcaptchaSiteKey,
    }),
  );
}
function hcaptchaToken(id) {
  if (!hcaptchaEnabled()) return '';
  let widget = captchaWidgets.get(id);
  return widget === undefined || !window.hcaptcha
    ? ''
    : window.hcaptcha.getResponse(widget);
}
function resetHcaptcha(id) {
  let widget = captchaWidgets.get(id);
  if (widget !== undefined && window.hcaptcha) window.hcaptcha.reset(widget);
}
function showLoginCaptcha() {
  let wrap = $('#login-captcha-wrap');
  if (!wrap) return;
  wrap.hidden = false;
  mountHcaptcha('login-captcha');
}
window.onHcaptchaReady = () =>
  document
    .querySelectorAll('[data-hcaptcha-container]')
    .forEach((node) => mountHcaptcha(node.id));
function authMessage() {
  return '<div id="auth-message" class="validation" role="alert" aria-live="polite"></div>';
}
function setAuthMessage(message) {
  let target = $('#auth-message');
  if (target) target.textContent = String(message || '');
}
function login() {
  return shell(
    `<div class="login-wrap"><div class="panel auth-card"><a class="auth-back" href="#/">← Back</a><header class="auth-heading"><h1>Log in</h1><p>Sign in to continue to AdoptAPet.</p></header><form onsubmit="doLogin(event)" novalidate><div class="field"><label for="login-email">Email address <span class="required">*</span></label>${emailInput('login-email')}</div><div class="field auth-field-gap"><div class="auth-label-row"><label for="login-password">Password <span class="required">*</span></label><a href="#/forgot-password">Forgot password?</a></div>${passwordInput('login-password', 'Your password', 'current-password')}</div>${hcaptchaMarkup('login-captcha', true)}${authMessage()}<button id="login-submit" class="btn auth-primary" type="submit">${uiIcon('lock')} Log in</button><p class="auth-secondary">New to AdoptAPet? <a href="#/register">Create account</a></p></form></div></div>`,
  );
}
async function doLogin(event) {
  event?.preventDefault();
  let e = $('#login-email').value.trim(),
    p = $('#login-password').value,
    button = $('#login-submit'),
    captchaWrap = $('#login-captcha-wrap'),
    captchaVisible = Boolean(captchaWrap && !captchaWrap.hidden),
    token = captchaVisible ? hcaptchaToken('login-captcha') : '';
  if (!e || !p) return setAuthMessage('Enter your email address and password.');
  if (captchaVisible && !token)
    return setAuthMessage('Complete the security check before signing in.');
  setAuthMessage('');
  setButtonBusy(button, true, 'Signing in…');
  try {
    let r = await fetch(new URL('api/login.php', window.location.href), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ email: e, password: p, hcaptcha_token: token }),
      }),
      result = await r.json();
    if (!r.ok || !result.success) {
      if (result.captcha_required) showLoginCaptcha();
      resetHcaptcha('login-captcha');
      return setAuthMessage(
        result.message || 'Unable to sign in. Please try again later.',
      );
    }
    state.csrfToken = result.csrf_token || '';
    if (result.mfa_required) {
      state.role = 'Guest';
      state.user = null;
      state.pendingMfa = { mode: result.mfa_mode };
      go('/admin/mfa');
      return;
    }
    state.pendingMfa = null;
    state.role = apiRoleToUi(result.user.role);
    if (!state.role) throw new Error('Unsupported account role.');
    state.user = result.user;
    try {
      let meResponse = await fetch(
          new URL('api/me.php', window.location.href),
          { credentials: 'same-origin' },
        ),
        meResult = await meResponse.json();
      if (meResponse.ok && meResult.success && meResult.authenticated) {
        state.user = meResult.user;
        state.csrfToken = meResult.csrf_token || state.csrfToken;
      }
    } catch (error) {}
    if (typeof refreshNotificationCount === 'function')
      await refreshNotificationCount();
    go(isAdminUiRole(state.role) ? '/admin' : '/pets');
  } catch (error) {
    resetHcaptcha('login-captcha');
    setAuthMessage('Unable to sign in. Please try again later.');
  } finally {
    setButtonBusy(button, false);
  }
}
async function logout() {
  try {
    let r = await fetch(new URL('api/logout.php', window.location.href), {
        method: 'POST',
        credentials: 'same-origin',
      }),
      result = await r.json();
    if (!r.ok || !result.success)
      return toast(
        result.message || 'Unable to log out. Please try again later.',
      );
    state.role = 'Guest';
    state.user = null;
    state.pendingMfa = null;
    state.csrfToken = '';
    clearRecoveryUxState();
    state.notificationUnread = 0;
    go('/');
  } catch (error) {
    toast('Unable to log out. Please try again later.');
  }
}
function register() {
  setTimeout(() => mountHcaptcha('register-captcha'), 0);
  return shell(
    `<div class="login-wrap auth-wide"><div class="panel auth-card"><a class="auth-back" href="#/login">← Back</a><header class="auth-heading"><h1>Create account</h1><p>Join AdoptAPet to apply, report strays, and support animal welfare.</p></header><form onsubmit="doRegister(event)" novalidate><div class="form-grid"><div class="field full"><label for="reg-username">Username <span class="required">*</span></label><input id="reg-username" class="input" minlength="3" maxlength="20" pattern="[A-Za-z0-9_]{3,20}" autocomplete="username" aria-describedby="reg-username-helper" required placeholder="Ali"><p id="reg-username-helper" class="helper">3–20 characters. Letters, numbers and underscores only.</p></div><div class="field"><label for="reg-name">Full name <span class="required">*</span></label><input id="reg-name" class="input" maxlength="150" autocomplete="name" required placeholder="Your full name"></div><div class="field"><label for="reg-email">Email address <span class="required">*</span></label>${emailInput('reg-email')}</div><div class="field full"><label for="reg-pass">Password <span class="required">*</span></label>${passwordInput('reg-pass', '8+ characters, uppercase, lowercase, number and symbol', 'new-password')}<p class="helper">Use uppercase, lowercase, a number, and a special character.</p></div><div class="field full"><label for="reg-confirm-pass">Confirm password <span class="required">*</span></label>${passwordInput('reg-confirm-pass', 'Repeat your password', 'new-password')}</div></div>${hcaptchaMarkup('register-captcha')}${authMessage()}<button id="register-submit" class="btn auth-primary" type="submit">Create account</button><p class="auth-secondary">Already have an account? <a href="#/login">Log in</a></p></form></div></div>`,
  );
}
async function doRegister(event) {
  event?.preventDefault();
  let u = $('#reg-username').value.trim(),
    n = $('#reg-name').value.trim(),
    e = $('#reg-email').value.trim(),
    p = $('#reg-pass').value,
    c = $('#reg-confirm-pass').value,
    strong = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,}$/.test(p),
    token = hcaptchaToken('register-captcha'),
    button = $('#register-submit');
  if (!usernamePattern.test(u))
    return setAuthMessage(
      'Username must be 3–20 characters using letters, numbers, and underscores only.',
    );
  if (!n) return setAuthMessage('Full name is required.');
  if (!/^\S+@\S+\.\S+$/.test(e))
    return setAuthMessage('Enter a valid email address.');
  if (p !== c) return setAuthMessage('Password confirmation does not match.');
  if (!strong)
    return setAuthMessage(
      'Password needs 8+ characters, uppercase, lowercase, a number, and a special character.',
    );
  if (hcaptchaEnabled() && !token)
    return setAuthMessage(
      'Complete the security check before creating an account.',
    );
  setAuthMessage('');
  setButtonBusy(button, true, 'Creating account…');
  try {
    let r = await fetch(new URL('api/register.php', window.location.href), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          username: u,
          name: n,
          email: e,
          password: p,
          hcaptcha_token: token,
        }),
      }),
      result = await r.json();
    if (result.verification_required) {
      state.pendingVerificationEmail = e;
      sessionStorage.setItem('pending-verification-email', e);
      go('/verify-email');
      return toast(result.message);
    }
    if (!r.ok || !result.success) {
      resetHcaptcha('register-captcha');
      return setAuthMessage(
        result.message ||
          'Unable to create the account. Please try again later.',
      );
    }
    state.pendingVerificationEmail = e;
    sessionStorage.setItem('pending-verification-email', e);
    toast(
      'Registration successful. Check your email for your verification code.',
    );
    setTimeout(() => go('/verify-email'), 700);
  } catch (error) {
    resetHcaptcha('register-captcha');
    setAuthMessage('Unable to create the account. Please try again later.');
  } finally {
    setButtonBusy(button, false);
  }
}
function clearRecoveryUxState() {
  if (recoveryExpiryTimer) clearTimeout(recoveryExpiryTimer);
  recoveryExpiryTimer = null;
  state.recoveryEmail = '';
  state.recoveryCaptchaReusable = false;
  state.recoveryCaptchaExpiresAt = 0;
}
function setRecoveryUxState(email, expiresIn = 300) {
  clearRecoveryUxState();
  let seconds = Math.max(0, Math.min(300, Number(expiresIn) || 0)),
    normalized = String(email || '')
      .trim()
      .toLowerCase();
  if (!normalized || seconds <= 0) return;
  state.recoveryEmail = normalized;
  state.recoveryCaptchaReusable = true;
  state.recoveryCaptchaExpiresAt = Date.now() + seconds * 1000;
  recoveryExpiryTimer = setTimeout(() => {
    clearRecoveryUxState();
    if (currentHashRoute() === '/forgot-password') showForgotPasswordCaptcha();
  }, seconds * 1000);
}
function recoveryCaptchaReusableForEmail(email) {
  return (
    state.recoveryCaptchaReusable &&
    Date.now() < state.recoveryCaptchaExpiresAt &&
    String(email || '')
      .trim()
      .toLowerCase() === state.recoveryEmail
  );
}
function updateForgotPasswordCaptcha(input) {
  let reusable = recoveryCaptchaReusableForEmail(input?.value),
    wrap = $('#forgot-captcha-wrap');
  if (!wrap) return;
  wrap.hidden = reusable;
  if (!reusable) mountHcaptcha('forgot-captcha');
}
function showForgotPasswordCaptcha() {
  clearRecoveryUxState();
  let wrap = $('#forgot-captcha-wrap');
  if (wrap) wrap.hidden = false;
  mountHcaptcha('forgot-captcha');
}
function forgotPassword() {
  let rememberedEmail = state.recoveryCaptchaReusable
      ? state.recoveryEmail
      : '',
    reuseCaptcha = recoveryCaptchaReusableForEmail(rememberedEmail);
  if (!reuseCaptcha) setTimeout(() => mountHcaptcha('forgot-captcha'), 0);
  return shell(
    `<div class="login-wrap"><div class="panel auth-card"><a class="auth-back" href="#/login">← Back</a><header class="auth-heading"><h1>Forgot password</h1><p>Enter your email to request a secure password reset link.</p></header><form onsubmit="requestPasswordReset(event)" novalidate><div class="field"><label for="reset-request-email">Email address</label>${emailInput('reset-request-email', { value: rememberedEmail, oninput: 'updateForgotPasswordCaptcha(this)' })}</div>${hcaptchaMarkup('forgot-captcha', reuseCaptcha)}<div id="auth-message" class="validation" role="alert" aria-live="polite"></div><button id="forgot-submit" class="btn auth-primary" type="submit">Send reset link</button><p class="auth-secondary"><a href="#/login">Return to login</a></p></form></div></div>`,
  );
}
async function requestPasswordReset(event) {
  event?.preventDefault();
  let e = $('#reset-request-email')?.value.trim() || '',
    normalizedEmail = e.toLowerCase(),
    reuseCaptcha = recoveryCaptchaReusableForEmail(normalizedEmail),
    token = reuseCaptcha ? '' : hcaptchaToken('forgot-captcha'),
    button = $('#forgot-submit');
  if (!/^\S+@\S+\.\S+$/.test(e))
    return setAuthMessage('Enter a valid email address.');
  if (hcaptchaEnabled() && !reuseCaptcha && !token)
    return setAuthMessage(
      'Complete the security check before requesting a password reset.',
    );
  setAuthMessage('');
  setButtonBusy(button, true, 'Sending…');
  try {
    let r = await fetch(
        new URL('api/request-password-reset.php', window.location.href),
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({ email: e, hcaptcha_token: token }),
        },
      ),
      result = await r.json();
    if (!r.ok || !result.success) {
      if (result.captcha_required) showForgotPasswordCaptcha();
      resetHcaptcha('forgot-captcha');
      return setAuthMessage(
        result.message || 'Unable to process the password-reset request.',
      );
    }
    if (!reuseCaptcha) setRecoveryUxState(normalizedEmail, 300);
    toast(result.message, 'success');
    go('/reset-link-sent');
  } catch (error) {
    resetHcaptcha('forgot-captcha');
    setAuthMessage(
      'Unable to process the password-reset request. Please try again later.',
    );
  } finally {
    if (button?.isConnected) setButtonBusy(button, false);
  }
}
function passwordResetRequested() {
  return shell(
    '<div class="login-wrap"><div class="panel empty success-state"><h1>Check your email</h1><p>If an account exists for that email, a password reset link has been sent.</p><p class="footer-note">The link expires in 15 minutes. You can close this page after checking your inbox.</p><div class="form-actions"><a class="btn" href="#/login">Return to Login</a><a class="btn outline" href="#/forgot-password">Request another link</a></div></div></div>',
  );
}
function passwordResetUnavailableMarkup(
  message = 'This password reset link is invalid or has expired.',
) {
  return `<div class="empty error-state"><h1>Reset link unavailable</h1><p>${esc(message)}</p><a class="btn" href="#/forgot-password">Request a new reset link</a></div>`;
}
function passwordResetFormMarkup() {
  return `<a class="auth-back" href="#/login">← Back</a><header class="auth-heading"><h1>Reset password</h1><p>Choose a new password for your AdoptAPet account.</p></header><form onsubmit="submitPasswordReset(event)" novalidate><div class="field"><label for="reset-password">New password</label>${passwordInput('reset-password', '8+ characters, uppercase, lowercase, number and symbol', 'new-password')}</div><div class="field auth-field-gap"><label for="reset-confirm-password">Confirm new password</label>${passwordInput('reset-confirm-password', 'Repeat your new password', 'new-password')}</div>${authMessage()}<button id="reset-password-submit" class="btn auth-primary" type="submit">Reset password</button><p class="footer-note">This reset link expires after 15 minutes and can only be used once.</p></form>`;
}
function resetPassword() {
  let token = passwordResetTokenFromHash();
  if (!/^[A-Za-z0-9_-]{43}$/.test(token))
    return shell(
      `<div class="login-wrap"><div class="panel auth-card">${passwordResetUnavailableMarkup()}</div></div>`,
    );
  setTimeout(() => validatePasswordResetLink(token), 0);
  return shell(
    '<div class="login-wrap"><div id="reset-password-content" class="panel auth-card"><div class="empty loading-state" role="status">Checking your password reset link…</div></div></div>',
  );
}
async function validatePasswordResetLink(token) {
  let target = $('#reset-password-content');
  if (!target || passwordResetTokenFromHash() !== token) return;
  try {
    let response = await fetch(
        new URL('api/validate-password-reset.php', window.location.href),
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ token }),
        },
      ),
      result = await response.json();
    if (!target.isConnected || passwordResetTokenFromHash() !== token) return;
    target.innerHTML =
      response.ok && result.success
        ? passwordResetFormMarkup()
        : passwordResetUnavailableMarkup(result.message);
  } catch (error) {
    if (target.isConnected && passwordResetTokenFromHash() === token)
      target.innerHTML = passwordResetUnavailableMarkup(
        'Unable to check this password reset link right now. Please try again.',
      );
  }
}
async function submitPasswordReset(event) {
  event?.preventDefault();
  let token = passwordResetTokenFromHash(),
    p = $('#reset-password')?.value || '',
    confirm = $('#reset-confirm-password')?.value || '',
    strong = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,}$/.test(p),
    button = $('#reset-password-submit');
  if (!/^[A-Za-z0-9_-]{43}$/.test(token))
    return ($('#reset-password-content').innerHTML =
      passwordResetUnavailableMarkup());
  if (p !== confirm)
    return setAuthMessage('Password confirmation does not match.');
  if (!strong)
    return setAuthMessage(
      'Your new password must meet all password-strength requirements.',
    );
  setAuthMessage('');
  setButtonBusy(button, true, 'Resetting…');
  try {
    let r = await fetch(
        new URL('api/reset-password.php', window.location.href),
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            token,
            password: p,
            confirm_password: confirm,
          }),
        },
      ),
      result = await r.json();
    if (!r.ok || !result.success) {
      if (r.status === 400)
        return ($('#reset-password-content').innerHTML =
          passwordResetUnavailableMarkup(result.message));
      return setAuthMessage(result.message || 'Unable to reset your password.');
    }
    state.role = 'Guest';
    state.user = null;
    state.csrfToken = '';
    clearRecoveryUxState();
    state.notificationUnread = 0;
    history.replaceState(
      null,
      '',
      `${location.pathname}${location.search}#/login`,
    );
    render();
    toast(result.message, 'success');
  } catch (error) {
    setAuthMessage('Unable to reset your password. Please try again later.');
    toast('Unable to reset your password. Please try again later.', 'error');
  } finally {
    if (button?.isConnected) setButtonBusy(button, false);
  }
}
function verifyEmail() {
  let locked = Boolean(state.pendingVerificationEmail);
  return shell(
    `<div class="login-wrap"><div class="panel auth-card"><h1>Verify Email</h1><p class="muted">Enter the 6-digit code sent to ${locked ? `<b>${esc(state.pendingVerificationEmail)}</b>` : 'your registered email address'}.</p><div class="field"><label for="verify-email">Email being verified</label>${emailInput('verify-email', { value: state.pendingVerificationEmail, readonly: locked, required: false })}</div><div class="field" style="margin-top:12px"><label>6-digit verification code</label><input id="verify-code" class="input code-input" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456"></div>${authMessage()}<div class="form-actions"><button id="verify-email-submit" class="btn" onclick="submitVerification()">Verify email</button><button id="verify-email-resend" class="btn outline" onclick="resendVerification()">Resend code</button></div><p class="footer-note">Verification codes expire after 10 minutes. Invalid and expired codes are never accepted.</p></div></div>`,
  );
}
async function submitVerification() {
  let e = $('#verify-email').value.trim(),
    c = $('#verify-code').value.trim(),
    button = $('#verify-email-submit');
  if (!e || !/^\d{6}$/.test(c))
    return setAuthMessage(
      'Enter your email address and 6-digit verification code.',
    );
  setAuthMessage('');
  setButtonBusy(button, true, 'Verifying…');
  try {
    let r = await fetch(new URL('api/verify-email.php', window.location.href), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: e, code: c }),
      }),
      result = await r.json();
    if (!r.ok || !result.success)
      return setAuthMessage(result.message || 'Unable to verify your email.');
    state.pendingVerificationEmail = '';
    sessionStorage.removeItem('pending-verification-email');
    $('#app').innerHTML = shell(
      '<div class="login-wrap"><div class="panel empty success-state"><h1>Email verified</h1><p>Your email address is verified and your account is ready.</p><a class="btn" href="#/login">Continue to Login</a></div></div>',
    );
    toast(result.message);
  } catch (error) {
    setAuthMessage('Unable to verify your email. Please try again later.');
    toast('Unable to verify your email. Please try again later.', 'error');
  } finally {
    if (button?.isConnected) setButtonBusy(button, false);
  }
}
async function resendVerification() {
  let e = $('#verify-email').value.trim(),
    button = $('#verify-email-resend');
  if (!e || !/^\S+@\S+\.\S+$/.test(e))
    return setAuthMessage('Enter a valid email address first.');
  setButtonBusy(button, true, 'Sending…');
  try {
    let r = await fetch(
        new URL('api/resend-verification.php', window.location.href),
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ email: e }),
        },
      ),
      result = await r.json();
    toast(result.message || 'Unable to resend the verification code.');
  } catch (error) {
    toast('Unable to resend the verification code. Please try again later.');
  } finally {
    if (button?.isConnected) setButtonBusy(button, false);
  }
}
function profile() {
  return typeof databaseProfilePage === 'function'
    ? databaseProfilePage()
    : shell('<div class="empty panel">Loading your profile…</div>');
}
function activity() {
  return typeof databaseActivityPage === 'function'
    ? databaseActivityPage()
    : shell('<div class="empty panel">Loading your activity…</div>');
}
function saveProfile() {
  return typeof saveDatabaseProfile === 'function'
    ? saveDatabaseProfile()
    : toast('Unable to update your profile. Please try again later.');
}
function password() {
  return shell(
    `<div class="change-password-page"><a class="change-password-back" href="#/account/security">← Back to Security</a><div class="change-password-heading"><h1>Change Password</h1><p>Update your account password securely.</p></div><section class="panel change-password-shell"><div class="change-password-form"><div class="field"><label for="current-pass">Current password <span class="required">*</span></label>${passwordInput('current-pass', 'Current password', 'current-password')}</div><div class="field auth-field-gap"><label for="new-pass">New password <span class="required">*</span></label>${passwordInput('new-pass', '8+ characters, uppercase, lowercase, number and symbol', 'new-password')}<p class="helper">Use 8+ characters with uppercase, lowercase, a number, and a special character.</p></div><div class="field auth-field-gap"><label for="confirm-new-pass">Confirm new password <span class="required">*</span></label>${passwordInput('confirm-new-pass', 'Repeat your new password', 'new-password')}</div><div id="change-password-message" class="validation" role="alert" aria-live="polite"></div><div class="form-actions"><button id="change-password-submit" class="btn" onclick="changePassword()">Update password</button></div></div></section></div>`,
    isAdminUiRole(state.role),
  );
}
function setChangePasswordMessage(message) {
  let target = $('#change-password-message');
  if (target) target.textContent = String(message || '');
}
async function changePassword() {
  let current = $('#current-pass')?.value || '',
    next = $('#new-pass')?.value || '',
    confirm = $('#confirm-new-pass')?.value || '',
    strong = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,}$/.test(
      next,
    ),
    button = $('#change-password-submit');
  if (!current || !next || !confirm)
    return setChangePasswordMessage('Complete all password fields.');
  if (next !== confirm)
    return setChangePasswordMessage('Password confirmation does not match.');
  if (!strong)
    return setChangePasswordMessage(
      'Password must have at least 8 characters, including uppercase, lowercase, number, and special character.',
    );
  setChangePasswordMessage('');
  setButtonBusy(button, true, 'Updating…');
  try {
    let response = await fetch(
        new URL('api/change-password.php', window.location.href),
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify({
            current_password: current,
            new_password: next,
            confirm_password: confirm,
          }),
        },
      ),
      result = await response.json();
    if (!response.ok || !result.success)
      return setChangePasswordMessage(
        result.message || 'Unable to update your password.',
      );
    if (result.csrf_token) state.csrfToken = result.csrf_token;
    $('#current-pass').value = '';
    $('#new-pass').value = '';
    $('#confirm-new-pass').value = '';
    toast(result.message, 'success');
    setTimeout(
      () => go(isAdminUiRole(state.role) ? '/admin' : '/profile'),
      700,
    );
  } catch (error) {
    setChangePasswordMessage(
      'Unable to update your password. Please try again later.',
    );
    toast('Unable to update your password. Please try again later.', 'error');
  } finally {
    if (button?.isConnected) setButtonBusy(button, false);
  }
}
function community() {
  return typeof databaseCommunityPage === 'function'
    ? databaseCommunityPage()
    : shell('<div class="panel empty">Unable to load Community Posts.</div>');
}
function createPost() {
  return typeof createDatabaseCommunityPost === 'function'
    ? createDatabaseCommunityPost()
    : toast('Unable to publish the Community Post.');
}
function likePost(id, liked = false) {
  return typeof toggleDatabaseCommunityLike === 'function'
    ? toggleDatabaseCommunityLike(id, liked)
    : toast('Unable to update the Community Post like.');
}
function commentPost(id) {
  return typeof createDatabaseCommunityComment === 'function'
    ? createDatabaseCommunityComment(id)
    : toast('Unable to post the comment.');
}
function notifications() {
  return typeof databaseNotificationsPage === 'function'
    ? databaseNotificationsPage()
    : shell('<div class="panel empty">Unable to load Notifications.</div>');
}
function readNotification(id) {
  return typeof readDatabaseNotification === 'function'
    ? readDatabaseNotification(id)
    : toast('Unable to update the Notification.');
}
function readAll() {
  return typeof readAllDatabaseNotifications === 'function'
    ? readAllDatabaseNotifications()
    : toast('Unable to update Notifications.');
}
function donations() {
  return typeof databaseDonationsPage === 'function'
    ? databaseDonationsPage()
    : shell(
        '<div class="panel empty">Unable to load the Donation Board.</div>',
      );
}
function donate() {
  return typeof databaseDonatePage === 'function'
    ? databaseDonatePage()
    : shell('<div class="panel empty">Unable to load the Donation form.</div>');
}
function makeDonation() {
  return typeof makeDatabaseDonation === 'function'
    ? makeDatabaseDonation()
    : toast('Unable to process the Donation.');
}
function receipt(id) {
  return typeof databaseReceiptPage === 'function'
    ? databaseReceiptPage(id)
    : shell(
        '<div class="panel empty">Unable to load the Donation confirmation.</div>',
      );
}
function certificate(id) {
  return typeof databaseCertificatePage === 'function'
    ? databaseCertificatePage(id)
    : shell(
        '<div class="panel empty">Unable to load the Adoption Certificate.</div>',
      );
}
function news() {
  return typeof databaseNewsPage === 'function'
    ? databaseNewsPage()
    : shell(
        '<div class="panel empty">Unable to load News announcements.</div>',
      );
}
function chat(admin = false, conversationId = null) {
  return typeof databaseChatPage === 'function'
    ? databaseChatPage(admin, conversationId)
    : shell(
        '<div class="panel empty">Unable to load Live Chat Support.</div>',
        admin,
      );
}
function sendChat(admin) {
  return typeof sendDatabaseChat === 'function'
    ? sendDatabaseChat(admin)
    : toast('Unable to send the Live Chat message.');
}
function adminDashboard() {
  return typeof databaseAdminDashboardPage === 'function'
    ? databaseAdminDashboardPage()
    : shell(
        '<div class="panel empty error-state">Unable to load administrator dashboard.</div>',
        true,
      );
}
function adminUsers() {
  return typeof databaseAdminUsersPage === 'function'
    ? databaseAdminUsersPage()
    : shell(
        `<div class="page-title"><h1>User Management</h1></div><div class="panel"><div class="empty">Unable to load User Management.</div></div>`,
        true,
      );
}
function adminStrays() {
  return typeof databaseAdminStrayReportsPage === 'function'
    ? databaseAdminStrayReportsPage()
    : shell(
        '<div class="panel empty">Unable to load Stray Report management.</div>',
        true,
      );
}
function analytics() {
  return typeof databaseAnalyticsPage === 'function'
    ? databaseAnalyticsPage()
    : shell(
        `<div class="page-title"><h1>Analytics & Reporting</h1></div><div class="panel empty">Unable to load analytics.</div>`,
        true,
      );
}
function adminNews() {
  return typeof databaseAdminNewsPage === 'function'
    ? databaseAdminNewsPage()
    : shell(
        '<div class="panel empty">Unable to load News management.</div>',
        true,
      );
}
function saveNews() {
  return typeof saveDatabaseNews === 'function'
    ? saveDatabaseNews()
    : toast('Unable to save the News announcement.');
}
function editNews(id) {
  return typeof editDatabaseNews === 'function'
    ? editDatabaseNews(id)
    : toast('Unable to edit the News announcement.');
}
function deleteNews(id) {
  return typeof archiveDatabaseNews === 'function'
    ? archiveDatabaseNews(id)
    : toast('Unable to remove the News announcement.');
}
function adminCommunity() {
  return typeof databaseAdminCommunityPage === 'function'
    ? databaseAdminCommunityPage()
    : shell(
        '<div class="panel empty">Unable to load Community moderation.</div>',
        true,
      );
}
function removePost(id) {
  return typeof removeDatabaseCommunityPost === 'function'
    ? removeDatabaseCommunityPost(id)
    : toast('Unable to remove the Community Post.');
}
function systemStatePage(code) {
  let states = {
      403: ['Access denied', 'You do not have permission to view this page.'],
      404: [
        'Page not found',
        'The page you requested does not exist or may have moved.',
      ],
      500: [
        'Something went wrong',
        'The system could not complete this request. Please try again later.',
      ],
    },
    item = states[code] || states[500];
  return shell(
    `<div class="system-state panel"><div class="system-code">${code}</div><h1>${item[0]}</h1><p>${item[1]}</p><div class="form-actions"><a class="btn" href="#/">Go Home</a><button class="btn outline" onclick="history.back()">Go Back</button></div></div>`,
  );
}
/** Keeps Administrator routes behind the completed sign-in and MFA flow. */
function requireAdmin() {
  if (!isAdminUiRole(state.role)) {
    go(
      state.pendingMfa
        ? '/admin/mfa'
        : state.role === 'Guest'
          ? '/login'
          : '/403',
    );
    return false;
  }
  return true;
}
function requireUser() {
  if (state.role !== 'Registered User') {
    go(state.role === 'Guest' ? '/login' : '/403');
    return false;
  }
  return true;
}
async function restoreAuthentication() {
  try {
    let r = await fetch(new URL('api/me.php', window.location.href), {
        credentials: 'same-origin',
      }),
      result = await r.json();
    setRecoveryUxState(
      result.password_recovery?.email || '',
      result.password_recovery?.expires_in || 0,
    );
    if (
      result.success &&
      result.authenticated &&
      apiRoleToUi(result.user?.role)
    ) {
      state.pendingMfa = null;
      state.role = apiRoleToUi(result.user.role);
      state.user = result.user;
      state.csrfToken = result.csrf_token || '';
      if (typeof refreshNotificationCount === 'function')
        await refreshNotificationCount();
    } else {
      state.role = 'Guest';
      state.user = null;
      state.pendingMfa = result.pending_mfa || null;
      state.csrfToken = state.pendingMfa ? result.csrf_token || '' : '';
    }
  } catch (error) {
    state.role = 'Guest';
    state.pendingMfa = null;
    state.csrfToken = '';
    clearRecoveryUxState();
  }
  state.authReady = true;
  render();
}
/** Selects the current route after access checks and renders its page. */
function render() {
  if (!state.authReady) return;
  closeAccountMenu();
  captchaWidgets.clear();
  let r = currentHashRoute();
  if (r !== '/admin/mfa/recovery' && state.mfaRecoveryCodes)
    state.mfaRecoveryCodes = null;
  if (r === '/dashboard') {
    go(isAdminUiRole(state.role) ? '/admin' : '/pets');
    return;
  }
  if (r === '/profile') {
    if (!requireUser()) return;
    go('/account/personal');
    return;
  }
  if (r === '/account/notifications') {
    if (!isAdminUiRole(state.role) && state.role !== 'Registered User') {
      go(state.pendingMfa ? '/admin/mfa' : '/login');
      return;
    }
    go(isAdminUiRole(state.role) ? '/admin/notifications' : '/notifications');
    return;
  }
  let view;
  if (r === '/account' || r.startsWith('/account/')) {
    if (!isAdminUiRole(state.role) && state.role !== 'Registered User') {
      go(state.pendingMfa ? '/admin/mfa' : '/login');
      return;
    }
    view = accountPage();
  } else if (r === '/security') {
    if (!isAdminUiRole(state.role) && state.role !== 'Registered User') {
      go(state.pendingMfa ? '/admin/mfa' : '/login');
      return;
    }
    view = securityPage();
  } else if (r === '/admin/mfa') view = adminMfaPage();
  else if (r === '/admin/mfa/recovery') view = adminMfaRecoveryPage();
  else if (r.startsWith('/application/'))
    view = applicationDetail(r.split('/')[2]);
  else if (r.startsWith('/pet/')) view = petDetail(r.split('/')[2]);
  else if (r.startsWith('/stray/')) view = strayReportDetail(r.split('/')[2]);
  else if (r.startsWith('/community/'))
    view = requireUser() && communityPostDetail(r.split('/')[2]);
  else if (r.startsWith('/news/'))
    view =
      typeof databaseNewsDetailPage === 'function'
        ? databaseNewsDetailPage(r.split('/')[2])
        : systemStatePage(404);
  else if (r.startsWith('/apply/')) view = applyPage(r.split('/')[2]);
  else if (r.startsWith('/receipt/')) view = receipt(r.split('/')[2]);
  else if (r.startsWith('/certificate/')) view = certificate(r.split('/')[2]);
  else if (r.startsWith('/admin/chat/')) {
    if (!requireAdmin()) return;
    view = chat(true, r.split('/')[3]);
  } else if (r.startsWith('/admin')) {
    if (!requireAdmin()) return;
    let adminRoutes = {
      '/admin': adminDashboard,
      '/admin/pets': adminPets,
      '/admin/strays': adminStrays,
      '/admin/applications': adminApplications,
      '/admin/users': adminUsers,
      '/admin/analytics': analytics,
      '/admin/audit': adminAuditPage,
      '/admin/news': adminNews,
      '/admin/community': adminCommunity,
      '/admin/chat': () => chat(true),
      '/admin/notifications': notifications,
      '/admin/password': password,
      '/admin/security': adminMfaSecurityPage,
    };
    view = adminRoutes[r] ? adminRoutes[r]() : systemStatePage(404);
  } else if (r.startsWith('/chat/'))
    view = requireUser() && chat(false, r.split('/')[2]);
  else {
    let routes = {
      '/': home,
      '/pets': petsPage,
      '/strays': strayReportsPage,
      '/report-stray': () => requireUser() && strayReportFormPage(),
      '/news': news,
      '/donations': donations,
      '/login': login,
      '/register': register,
      '/verify-email': verifyEmail,
      '/forgot-password': forgotPassword,
      '/reset-link-sent': passwordResetRequested,
      '/reset-password': resetPassword,
      '/403': () => systemStatePage(403),
      '/404': () => systemStatePage(404),
      '/500': () => systemStatePage(500),
      '/applications': () => requireUser() && applications(),
      '/profile': () => requireUser() && profile(),
      '/activity': () => requireUser() && activity(),
      '/password': () => requireUser() && password(),
      '/community': () => requireUser() && community(),
      '/notifications': () =>
        isAdminUiRole(state.role)
          ? notifications()
          : requireUser() && notifications(),
      '/donate': () => requireUser() && donate(),
      '/chat': () => requireUser() && chat(false),
    };
    view = routes[r] ? routes[r]() : systemStatePage(404);
  }
  $('#app').innerHTML = view || '';
  let m = $('#messages');
  if (m) m.scrollTop = m.scrollHeight;
}
document.addEventListener('click', (event) => {
  let routeLink =
    !event.defaultPrevented &&
    event.button === 0 &&
    !event.metaKey &&
    !event.ctrlKey &&
    !event.shiftKey &&
    !event.altKey
      ? event.target.closest('a[href^="#/"]')
      : null;
  if (routeLink) markRouteTopReset(routeLink.getAttribute('href'));
  if (!event.target.closest('.account-menu')) closeAccountMenu();
});
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') closeAccountMenu();
  handleAdminModalKeydown(event);
});
window.addEventListener('hashchange', (event) => {
  render();
  completeRouteNavigation(event);
});
if (document.readyState === 'loading')
  document.addEventListener('DOMContentLoaded', restoreAuthentication, {
    once: true,
  });
else setTimeout(restoreAuthentication, 0);
