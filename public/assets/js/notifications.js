let notificationPollTimer = null;
let notificationCache = [];
let notificationPage = 1;
let notificationRequestVersion = 0;
const notificationPageSize = 10;

function updateNotificationHeader() {
  let button = $('#notification-nav-button');
  if (button)
    button.innerHTML = `Notifications${state.notificationUnread ? ` <b>${state.notificationUnread}</b>` : ''}`;
}

async function refreshNotificationCount() {
  if (state.role !== 'Registered User' && !isAdminUiRole(state.role)) {
    state.notificationUnread = 0;
    updateNotificationHeader();
    return;
  }
  try {
    let response = await fetch(
        new URL('api/notifications.php?summary=1', window.location.href),
        { credentials: 'same-origin' },
      ),
      result = await response.json();
    if (response.ok && result.success) {
      state.notificationUnread = Number(result.unread_count) || 0;
      updateNotificationHeader();
    }
  } catch (error) {}
}

function databaseNotificationsPage() {
  setTimeout(loadDatabaseNotifications, 0);
  let admin = isAdminUiRole(state.role),
    action =
      '<button id="notification-mark-all" type="button" class="btn outline sm" onclick="readAll()" disabled>Mark all as read</button>',
    heading = admin
      ? `<div class="admin-page-heading admin-notifications-heading"><div class="page-title"><span class="eyebrow">System notifications</span><h1>Notifications</h1><p>Updates requiring Administrator attention and account activity.</p></div>${action}</div>`
      : `<header class="notifications-heading"><div><h1>Notifications</h1><p>Updates relevant to your account and interactions.</p></div>${action}</header>`;
  return shell(
    `<div class="notifications-ux ${admin ? 'admin-notifications-ux' : ''}">${heading}<div class="notification-filter-tabs" role="group" aria-label="Filter Notifications"><button class="active" data-filter="all" onclick="setNotificationFilter(this,'all')" aria-pressed="true">All</button><button data-filter="unread" onclick="setNotificationFilter(this,'unread')" aria-pressed="false">Unread</button><button data-filter="read" onclick="setNotificationFilter(this,'read')" aria-pressed="false">Read</button></div><p id="notification-results-count" class="muted" aria-live="polite">Loading Notifications…</p><div id="notification-list" class="notification-list" aria-busy="true">${skeletonList(10, 'Loading Notifications')}</div><div id="notification-pagination"></div></div>`,
    admin,
  );
}

function safeNotificationLink(link) {
  return typeof link === 'string' && /^#\/[A-Za-z0-9/_-]+$/.test(link)
    ? link
    : null;
}

function databaseNotificationHtml(notification) {
  let link = safeNotificationLink(notification.link_url);
  let type = String(notification.notification_type || '').toLowerCase();
  let details = {
    stray_report_status: ['stray', 'Stray Report', 'paw'],
    admin_stray_report_submitted: ['stray', 'Stray Report', 'paw'],
    application_status: ['adoption', 'Adoption', 'file'],
    application_submitted: ['adoption', 'Adoption', 'file'],
    admin_application_submitted: ['adoption', 'Adoption', 'file'],
    community_like: ['community', 'Community', 'heart'],
    community_comment: ['community', 'Community', 'mail'],
    news_published: ['news', 'News', 'file'],
    chat_message: ['chat', 'Chat', 'mail'],
    chat_message_admin: ['chat', 'Chat', 'mail'],
  }[type] || ['general', 'General', 'bell'];
  let [category, label, icon] = details;
  return `<button type="button" class="notification notification-card notification-${category} ${notification.is_read ? '' : 'unread'}" onclick="openDatabaseNotification(${notification.id},${link ? `'${esc(link)}'` : 'null'})"><span class="notification-icon" aria-hidden="true">${uiIcon(icon)}</span><span class="notification-copy"><span class="notification-message">${esc(notification.message)}</span><span class="notification-meta"><span class="notification-type">${label}</span> · ${date(notification.created_at)}</span></span>${link ? '<span class="notification-arrow" aria-hidden="true">→</span>' : ''}</button>`;
}

function currentNotificationFilter() {
  return $('.notification-filter-tabs .active')?.dataset.filter || 'all';
}
function setNotificationFilter(button, filter) {
  document
    .querySelectorAll('.notification-filter-tabs button')
    .forEach((item) => {
      item.classList.toggle('active', item === button);
      item.setAttribute('aria-pressed', String(item === button));
    });
  renderDatabaseNotifications(filter);
}
// Account notifications and read state
function renderDatabaseNotifications(
  filter = currentNotificationFilter(),
  resetPage = true,
) {
  let items = notificationCache.filter(
      (item) =>
        filter === 'all' ||
        (filter === 'unread' ? !item.is_read : item.is_read),
    ),
    target = $('#notification-list'),
    count = $('#notification-results-count'),
    markAll = $('#notification-mark-all'),
    controls = $('#notification-pagination');
  if (resetPage) notificationPage = 1;
  let page = paginatedItems(items, notificationPage, notificationPageSize);
  notificationPage = page.page;
  let resultLabel = `${filter === 'all' ? '' : filter + ' '}notifications`;
  if (count)
    count.textContent = page.total
      ? `Showing ${page.start + 1}–${page.end} of ${page.total} ${resultLabel}${filter === 'all' ? '' : ` · ${notificationCache.length} total`}`
      : `0 ${resultLabel}`;
  if (markAll) markAll.disabled = !state.notificationUnread;
  if (target)
    target.innerHTML = page.items.length
      ? page.items.map(databaseNotificationHtml).join('')
      : `<div class="empty"><h2>${filter === 'all' ? 'No notifications yet' : filter === 'unread' ? 'No unread notifications' : 'No read notifications'}</h2></div>`;
  if (controls)
    controls.innerHTML = paginationMarkup(
      page.page,
      page.totalPages,
      'changeNotificationPage',
      'Notification pages',
    );
}
function changeNotificationPage(page) {
  let filter = currentNotificationFilter(),
    items = notificationCache.filter(
      (item) =>
        filter === 'all' ||
        (filter === 'unread' ? !item.is_read : item.is_read),
    ),
    next = paginatedItems(items, page, notificationPageSize);
  if (next.page === notificationPage) return;
  notificationPage = next.page;
  renderDatabaseNotifications(filter, false);
  paginationScroll('#notification-results-count');
}

async function loadDatabaseNotifications(background = false) {
  let target = $('#notification-list');
  if (!target) return;
  let requestVersion = ++notificationRequestVersion;
  if (!background)
    setSkeleton(target, skeletonList(10, 'Loading Notifications'));
  try {
    let response = await fetch(
        new URL('api/notifications.php', window.location.href),
        { credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    if (!target.isConnected || requestVersion !== notificationRequestVersion)
      return;
    let nextNotifications = result.notifications || [],
      nextUnread = Number(result.unread_count) || 0;
    if (
      background &&
      nextUnread === state.notificationUnread &&
      JSON.stringify(nextNotifications) === JSON.stringify(notificationCache)
    )
      return;
    state.notificationUnread = nextUnread;
    updateNotificationHeader();
    notificationCache = nextNotifications;
    if (!background) clearLoading(target);
    renderDatabaseNotifications(currentNotificationFilter(), !background);
  } catch (error) {
    if (
      background ||
      !target.isConnected ||
      requestVersion !== notificationRequestVersion
    )
      return;
    clearLoading(target);
    target.innerHTML = `<div class="empty error-state"><h2>Unable to load Notifications</h2><p>${esc(error.message || 'Please try again.')}</p><button class="btn" onclick="loadDatabaseNotifications()">Retry</button></div>`;
  }
}

async function openDatabaseNotification(id, link) {
  let notification = notificationCache.find((item) => item.id === Number(id));
  if (!notification) return;
  if (!notification.is_read) {
    try {
      let response = await fetch(
          new URL('api/notifications.php', window.location.href),
          {
            method: 'PATCH',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id }),
          },
        ),
        result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.message);
      notificationRequestVersion++;
      notification.is_read = true;
      state.notificationUnread = Number(result.unread_count) || 0;
      updateNotificationHeader();
      renderDatabaseNotifications();
    } catch (error) {
      toast(error.message || 'Unable to update the Notification.');
      return;
    }
  }
  if (link) {
    let communityType = notification.notification_type,
      match = ['community_comment', 'community_like'].includes(communityType)
        ? /^#\/community\/([1-9][0-9]*)$/.exec(link)
        : null;
    if (match)
      communityNotificationScroll = {
        postId: match[1],
        section: communityType === 'community_comment' ? 'comments' : 'post',
      };
    go(link);
  }
}

async function readDatabaseNotification(id) {
  try {
    let response = await fetch(
        new URL('api/notifications.php', window.location.href),
        {
          method: 'PATCH',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ id }),
        },
      ),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to update the Notification.');
    notificationRequestVersion++;
    toast(result.message);
    await loadDatabaseNotifications();
  } catch (error) {
    toast('Unable to update the Notification. Please try again later.');
  }
}

async function readAllDatabaseNotifications() {
  try {
    let response = await fetch(
        new URL('api/notifications.php', window.location.href),
        {
          method: 'PATCH',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ mark_all: true }),
        },
      ),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to update Notifications.');
    notificationRequestVersion++;
    notificationCache.forEach((notification) => (notification.is_read = true));
    state.notificationUnread = Number(result.unread_count) || 0;
    updateNotificationHeader();
    renderDatabaseNotifications();
    toast(result.message);
  } catch (error) {
    toast('Unable to update Notifications. Please try again later.');
  }
}

notificationPollTimer = setInterval(() => {
  if (state.role === 'Registered User' || isAdminUiRole(state.role)) {
    if (
      location.hash === '#/notifications' ||
      location.hash === '#/admin/notifications'
    )
      loadDatabaseNotifications(true);
    else refreshNotificationCount();
  }
}, 30000);

render();
