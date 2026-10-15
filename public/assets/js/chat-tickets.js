let chatTicketPoll = null,
  chatTicketBusy = false,
  userTicketId = null,
  adminTicketId = null,
  userTickets = [],
  adminTickets = [],
  userTicketMessages = [],
  adminTicketMessages = [];

function ticketLabel(id) {
  return `CHAT-${String(Number(id) || 0).padStart(6, '0')}`;
}
function ticketTime(value) {
  if (!value) return '';
  return new Date(String(value).replace(' ', 'T')).toLocaleString('en-MY', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}
function ticketMessageText(message) {
  return `${message.is_current_user ? 'You' : isAdminApiRole(message.sender_role) ? 'Shelter Support' : message.sender_name} ${message.sender_name || ''} ${message.message || ''}`.toLowerCase();
}
function ticketStatus(status) {
  return `<span class="badge ${status === 'closed' ? 'ticket-closed' : 'ticket-open'}">${status === 'closed' ? 'Closed' : 'Open'}</span>`;
}

function databaseChatPage(admin = false, conversationId = null) {
  clearInterval(chatTicketPoll);
  chatTicketBusy = false;
  if (admin) {
    adminTicketId = Number(conversationId) || adminTicketId;
    setTimeout(loadAdminTickets, 0);
    chatTicketPoll = setInterval(() => {
      if (location.hash.startsWith('#/admin/chat')) loadAdminTickets(true);
    }, 5000);
    return shell(
      `<div class="admin-page-heading"><div class="page-title"><span class="eyebrow">Support workspace</span><h1>Live Chat Support</h1><p>Review active support tickets and retained conversation history.</p></div></div><div class="support-ticket-layout admin-chat-workspace"><section class="panel chat-ticket-list-panel" aria-label="Support ticket list"><div class="section-head"><div><span class="eyebrow">Ticket queue</span><h2>Conversations</h2><p class="muted">Open and closed support history</p></div></div><div class="chat-list-filters"><div class="field"><label for="admin-ticket-search">Search tickets</label><input id="admin-ticket-search" class="input" maxlength="100" placeholder="Ticket ID, user, or email" oninput="filterAdminTickets()"></div><div class="field"><label for="admin-ticket-status">Ticket status</label><select id="admin-ticket-status" onchange="filterAdminTickets()"><option value="all">All statuses</option><option value="open">Open</option><option value="closed">Closed</option></select></div></div><div class="chat-ticket-filter-meta"><p id="admin-ticket-results" class="muted" aria-live="polite">Loading support tickets…</p><button id="admin-ticket-clear" class="btn outline sm" type="button" onclick="clearAdminTicketFilters()" hidden>Clear Filters</button></div><div id="admin-ticket-list" class="chat-ticket-list"><div class="empty">Loading conversations…</div></div></section><section class="panel chat chat-ticket-detail"><div id="admin-ticket-heading" class="chat-ticket-heading"><div class="chat-ticket-heading-empty"><span aria-hidden="true">◌</span><p>Select a support ticket to review its conversation.</p></div></div>${historyToolbar(true)}<div class="messages" id="messages"><div class="empty">No conversation selected.</div></div>${composer(true)}</section></div><p class="footer-note">Closed tickets remain available as neutral, read-only support history.</p>`,
      true,
    );
  }
  cancelLiveSearch('user-tickets');
  userTicketId = Number(conversationId) || userTicketId;
  setTimeout(loadUserTickets, 0);
  chatTicketPoll = setInterval(() => {
    if (location.hash.startsWith('#/chat')) loadUserTickets(true);
  }, 5000);
  return shell(
    `<div class="live-chat-ux"><header class="section-head page-title live-chat-page-header"><div><h1>Live Chat Support</h1><p>Start a support ticket or return to an earlier conversation with the shelter team.</p></div><button type="button" class="btn live-chat-new-action" onclick="createUserTicket()">+ New Conversation</button></header><div class="support-ticket-layout user-chat-workspace"><section class="panel chat-ticket-list-panel" aria-label="Your support tickets"><div class="section-head"><div><h2>My Conversations</h2><p class="muted">Your open and closed tickets</p></div></div><div class="field"><label for="user-ticket-search">Search conversations</label><input id="user-ticket-search" class="input" maxlength="100" placeholder="Ticket or message" oninput="scheduleUserTicketSearch()" onkeydown="runUserTicketSearch(event)"></div><div id="user-ticket-list" class="chat-ticket-list"><div class="empty">Loading conversations…</div></div></section><section class="panel chat chat-ticket-detail"><div id="user-ticket-heading" class="chat-ticket-heading"><span class="muted">Select a conversation or create a new one.</span></div>${historyToolbar(false)}<div class="messages" id="messages"><div class="empty">No conversation selected.</div></div>${composer(false)}</section></div><p class="footer-note live-chat-ticket-note">Only one ticket can remain open at a time. Closing it preserves its history and lets you start another.</p><div id="new-ticket-modal" class="modal-overlay" hidden onclick="if(event.target===this)closeUserTicketModal()"><div class="panel modal-panel chat-ticket-composer" role="dialog" aria-modal="true" aria-labelledby="new-ticket-title"><div class="section-head"><div><h2 id="new-ticket-title">New Support Conversation</h2><p class="muted">Give the shelter team a short summary of what you need help with.</p></div><button type="button" class="link-btn" onclick="closeUserTicketModal()" aria-label="Close New Conversation form">Close</button></div><div class="field"><label for="new-ticket-subject">Conversation subject</label><input id="new-ticket-subject" class="input" maxlength="150" value="Shelter Support" onkeydown="if(event.key==='Enter')submitUserTicket()"></div><div class="form-actions"><button type="button" id="new-ticket-submit" class="btn" onclick="submitUserTicket()">Create Conversation</button><button type="button" class="btn outline" onclick="closeUserTicketModal()">Cancel</button></div></div></div></div>`,
  );
}

function historyToolbar(admin) {
  let prefix = admin ? 'admin-' : 'user-';
  return `<div class="chat-history-toolbar"><div class="field"><label for="${prefix}ticket-history-search">Search this conversation</label><input id="${prefix}ticket-history-search" class="input" maxlength="100" placeholder="Search message history" oninput="filterTicketHistory(${admin})" disabled></div><span id="${prefix}ticket-history-count" class="muted" aria-live="polite"></span></div>`;
}
function composer(admin) {
  if (!admin)
    return `<div class="chat-send user-chat-send"><div class="user-chat-composer-field"><label for="chat-text">Message</label><div class="user-chat-composer-row"><input id="chat-text" class="input" maxlength="2000" placeholder="Select or create an open ticket" onkeydown="if(event.key==='Enter')sendChat(false)" disabled><button id="chat-send-button" class="btn" onclick="sendChat(false)" disabled>Send</button></div><small id="chat-composer-status" class="muted">Select an open conversation to send a message.</small></div></div>`;
  return `<div class="chat-send admin-chat-send"><div class="admin-chat-reply-field"><label for="chat-text">Reply to ticket</label><div class="admin-chat-composer-row"><input id="chat-text" class="input" maxlength="2000" placeholder="Select an open support ticket" onkeydown="if(event.key==='Enter')sendChat(true)" disabled><button id="chat-send-button" class="btn" onclick="sendChat(true)" disabled>Send Reply</button></div><small id="chat-composer-status" class="muted">Select an open ticket to reply.</small></div></div>`;
}

function renderTicketMessages(messages, query = '') {
  let word = String(query).trim().toLowerCase(),
    items = messages.filter(
      (item) => !word || ticketMessageText(item).includes(word),
    );
  if (!items.length)
    return `<div class="empty">${word ? 'No messages match this search.' : 'No messages yet. Send a message to begin the conversation.'}</div>`;
  let day = '';
  return items
    .map((item) => {
      let currentDay = String(item.created_at || '').slice(0, 10),
        divider =
          currentDay !== day
            ? `<div class="chat-date-separator"><span>${date(String(item.created_at).replace(' ', 'T'))}</span></div>`
            : '';
      day = currentDay;
      return `${divider}<div class="message ${item.is_current_user ? 'me' : ''}" data-message-id="${Number(item.id) || 0}"><b>${esc(item.is_current_user ? 'You' : isAdminApiRole(item.sender_role) ? 'Shelter Support' : item.sender_name)}</b><span class="chat-message-copy">${esc(item.message)}</span><small>${ticketTime(item.created_at)}</small></div>`;
    })
    .join('');
}
function filterTicketHistory(admin, background = false) {
  let prefix = admin ? 'admin-' : 'user-',
    messages = admin ? adminTicketMessages : userTicketMessages,
    query = $(`#${prefix}ticket-history-search`)?.value || '',
    word = query.trim().toLowerCase(),
    count = messages.filter(
      (item) => !word || ticketMessageText(item).includes(word),
    ).length,
    target = $('#messages'),
    label = $(`#${prefix}ticket-history-count`),
    previousScrollTop = target?.scrollTop || 0,
    wasNearLatest =
      !target ||
      target.scrollHeight - target.scrollTop - target.clientHeight < 80;
  if (label) label.textContent = `${count} of ${messages.length} messages`;
  if (target) target.innerHTML = renderTicketMessages(messages, query);
  if (!word && target) {
    if (!background || wasNearLatest) target.scrollTop = target.scrollHeight;
    else target.scrollTop = previousScrollTop;
  }
}

function userTicketRows(items) {
  return items.length
    ? items.map((item) => ticketRow(item, false)).join('')
    : '<div class="empty">No support tickets yet. Start a new conversation when you need help.</div>';
}
function adminTicketRows(items) {
  return items.length
    ? items.map((item) => ticketRow(item, true)).join('')
    : '<div class="empty">No matching support tickets.</div>';
}
/** Renders the compact queue summary; account details stay in the conversation pane. */
function ticketRow(item, admin) {
  let active = item.id === (admin ? adminTicketId : userTicketId),
    secondary = item.subject || 'Shelter Support';
  if (!admin)
    return `<button type="button" class="chat-ticket-row ${active ? 'active' : ''}" onclick="selectUserTicket(${item.id})"><span class="chat-ticket-row-main"><b>${esc(item.ticket_id || ticketLabel(item.id))}</b><small>${esc(secondary)}</small><span>${esc(item.last_message || 'No messages yet')}</span></span><span class="chat-ticket-row-side">${ticketStatus(item.status)}${item.unread_count ? `<small class="chat-unread-count">${item.unread_count} new</small>` : ''}<small>${ticketTime(item.last_message_at || item.created_at)}</small></span></button>`;
  return `<button type="button" class="chat-ticket-row admin-chat-ticket-row ${active ? 'active' : ''}" aria-pressed="${active ? 'true' : 'false'}" onclick="selectAdminTicket(${item.id})"><span class="chat-ticket-row-main"><span class="chat-ticket-ticketline"><b>${esc(item.ticket_id || ticketLabel(item.id))}</b><span class="chat-ticket-ticketline-status">${ticketStatus(item.status)}${item.unread_count ? `<span class="chat-unread-pill">${item.unread_count} new</span>` : ''}</span></span><span class="chat-ticket-person"><span class="admin-person-avatar" aria-hidden="true">${esc(
    String(item.user_name || '?')
      .trim()
      .charAt(0)
      .toUpperCase() || '?',
  )}</span><strong>${esc(item.user_name || 'Registered User')}</strong></span><span class="chat-ticket-preview">${esc(item.last_message || 'No messages yet')}</span></span><span class="chat-ticket-row-side"><small>${ticketTime(item.last_message_at || item.created_at)}</small></span></button>`;
}
function filterUserTickets() {
  let word = ($('#user-ticket-search')?.value || '').trim().toLowerCase(),
    items = userTickets.filter(
      (item) =>
        !word ||
        `${item.ticket_id} ${item.subject || ''} ${item.last_message || ''} ${item.message_search || ''}`
          .toLowerCase()
          .includes(word),
    ),
    target = $('#user-ticket-list');
  if (target) target.innerHTML = userTicketRows(items);
}
function scheduleUserTicketSearch() {
  scheduleLiveSearch('user-tickets', filterUserTickets);
}
function runUserTicketSearch(event) {
  runLiveSearchNow(event, 'user-tickets', filterUserTickets);
}
function filterAdminTickets() {
  let word = ($('#admin-ticket-search')?.value || '').trim().toLowerCase(),
    status = $('#admin-ticket-status')?.value || 'all',
    items = adminTickets.filter(
      (item) =>
        (status === 'all' || item.status === status) &&
        (!word ||
          `${item.ticket_id} ${item.user_name || ''} ${item.user_email || ''} ${item.subject || ''} ${item.last_message || ''} ${item.message_search || ''}`
            .toLowerCase()
            .includes(word)),
    ),
    target = $('#admin-ticket-list'),
    count = $('#admin-ticket-results'),
    clear = $('#admin-ticket-clear');
  if (count)
    count.textContent = `Showing ${items.length} of ${adminTickets.length} support ticket${adminTickets.length === 1 ? '' : 's'}`;
  if (clear) clear.hidden = !(word || status !== 'all');
  if (target) target.innerHTML = adminTicketRows(items);
}
function clearAdminTicketFilters() {
  let search = $('#admin-ticket-search'),
    status = $('#admin-ticket-status');
  if (search) search.value = '';
  if (status) status.value = 'all';
  filterAdminTickets();
}

async function loadUserTickets(background = false) {
  if (chatTicketBusy) return;
  let list = $('#user-ticket-list');
  if (!list) return;
  chatTicketBusy = true;
  try {
    let response = await fetch(new URL('api/chat.php', window.location.href), {
        credentials: 'same-origin',
      }),
      result = await response.json();
    if (!response.ok || !result.success)
      throw new Error(result.message || 'Unable to load Live Chat Support.');
    userTickets = result.conversations || [];
    if (!userTickets.some((item) => item.id === userTicketId))
      userTicketId =
        userTickets.find((item) => item.status === 'open')?.id ||
        userTickets[0]?.id ||
        null;
    filterUserTickets();
    if (userTicketId) await loadUserTicket(userTicketId, background);
    else clearTicket(false);
  } catch (error) {
    if (!background)
      list.innerHTML = `<div class="empty">${esc(error.message || 'Unable to load Live Chat Support.')}</div>`;
  } finally {
    chatTicketBusy = false;
  }
}
// Administrator ticket queue and polling
async function loadAdminTickets(background = false) {
  if (chatTicketBusy) return;
  let list = $('#admin-ticket-list');
  if (!list) return;
  chatTicketBusy = true;
  try {
    let response = await fetch(
        new URL('api/admin-chat.php', window.location.href),
        { credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success)
      throw new Error(
        result.message || 'Unable to load Live Chat conversations.',
      );
    adminTickets = result.conversations || [];
    if (!adminTickets.some((item) => item.id === adminTicketId))
      adminTicketId = adminTickets[0]?.id || null;
    filterAdminTickets();
    if (adminTicketId) await loadAdminTicket(adminTicketId, background);
    else clearTicket(true);
  } catch (error) {
    if (!background)
      list.innerHTML = `<div class="empty">${esc(error.message || 'Unable to load Live Chat conversations.')}</div>`;
  } finally {
    chatTicketBusy = false;
  }
}

function clearTicket(admin) {
  let prefix = admin ? 'admin-' : 'user-',
    heading = $(`#${prefix}ticket-heading`),
    messages = $('#messages'),
    search = $(`#${prefix}ticket-history-search`),
    count = $(`#${prefix}ticket-history-count`),
    input = $('#chat-text'),
    button = $('#chat-send-button'),
    composerStatus = $('#chat-composer-status'),
    detail = $(
      admin
        ? '.admin-chat-workspace .chat-ticket-detail'
        : '.user-chat-workspace .chat-ticket-detail',
    );
  if (heading)
    heading.innerHTML = admin
      ? '<div class="chat-ticket-heading-empty"><span aria-hidden="true">◌</span><p>Select a support ticket to review its conversation.</p></div>'
      : '<span class="muted">Select a conversation or create a new one.</span>';
  if (messages)
    messages.innerHTML = '<div class="empty">No conversation selected.</div>';
  if (search) {
    search.value = '';
    search.disabled = true;
  }
  if (count) count.textContent = '';
  if (input) {
    input.value = '';
    input.disabled = true;
    input.placeholder = admin
      ? 'Select an open support ticket'
      : 'Select or create an open ticket';
  }
  if (button) button.disabled = true;
  if (composerStatus)
    composerStatus.textContent = admin
      ? 'Select an open ticket to reply.'
      : 'Select an open conversation to send a message.';
  if (detail) detail.classList.remove('ticket-read-only');
}
/** Updates the selected conversation without changing its queue position. */
function setTicketDetail(conversation, admin) {
  let prefix = admin ? 'admin-' : 'user-',
    heading = $(`#${prefix}ticket-heading`),
    search = $(`#${prefix}ticket-history-search`),
    input = $('#chat-text'),
    button = $('#chat-send-button'),
    composerStatus = $('#chat-composer-status'),
    detail = $(
      admin
        ? '.admin-chat-workspace .chat-ticket-detail'
        : '.user-chat-workspace .chat-ticket-detail',
    ),
    open = conversation.status === 'open',
    description = admin
      ? `${conversation.user_name} · ${conversation.user_email}`
      : conversation.subject || 'Shelter Support';
  if (heading) {
    if (admin) {
      let initial =
          String(conversation.user_name || '?')
            .trim()
            .charAt(0)
            .toUpperCase() || '?',
        assignment =
          conversation.assigned_admin_name ||
          conversation.assigned_admin_user_id
            ? 'Assigned administrator'
            : 'Unassigned — replying will assign this ticket';
      heading.innerHTML = `<div class="chat-ticket-heading-main"><div class="chat-ticket-heading-top"><b>${esc(conversation.ticket_id || ticketLabel(conversation.id))}</b>${ticketStatus(conversation.status)}</div><div class="chat-ticket-customer"><span class="admin-person-avatar" aria-hidden="true">${esc(initial)}</span><div><h2>${esc(conversation.user_name || 'Registered User')}</h2><a href="mailto:${esc(conversation.user_email || '')}">${esc(conversation.user_email || 'Email unavailable')}</a></div></div><div class="chat-ticket-heading-meta"><span>Opened ${ticketTime(conversation.created_at)}</span>${conversation.closed_at ? `<span>Closed ${ticketTime(conversation.closed_at)}</span>` : ''}<span class="chat-ticket-assignment">Assigned administrator: <b>${esc(conversation.assigned_admin_name || assignment)}</b></span></div>${open ? '' : '<div class="chat-closed-note"><b>Read-only history</b><span>This ticket is closed and cannot receive new replies.</span></div>'}</div>${open ? '<button type="button" class="btn admin-warning-action sm" onclick="closeAdminTicket()">Close Ticket</button>' : ''}`;
    } else
      heading.innerHTML = `<div><b>${esc(conversation.ticket_id)}</b> ${ticketStatus(conversation.status)}<p>${esc(description)} · Opened ${ticketTime(conversation.created_at)}${conversation.closed_at ? ` · Closed ${ticketTime(conversation.closed_at)}` : ''}</p></div>${open ? '<button type="button" class="btn outline sm" onclick="closeUserTicket()">Close Ticket</button>' : ''}`;
  }
  if (search) search.disabled = false;
  if (input) {
    input.disabled = !open;
    input.placeholder = open
      ? admin
        ? 'Type a reply to this ticket'
        : 'Type your message'
      : admin
        ? 'Closed ticket — replies disabled'
        : 'This ticket is closed';
  }
  if (button) button.disabled = !open;
  if (composerStatus)
    composerStatus.textContent = open
      ? admin
        ? 'Replies are added to the retained ticket history.'
        : 'Messages are added to this conversation history.'
      : admin
        ? 'Conversation history is read-only.'
        : 'This ticket is closed — read-only history.';
  if (detail) detail.classList.toggle('ticket-read-only', !open);
}
async function loadUserTicket(id, background = false) {
  let response = await fetch(
      new URL(
        `api/chat.php?conversation_id=${encodeURIComponent(id)}`,
        window.location.href,
      ),
      { credentials: 'same-origin' },
    ),
    result = await response.json();
  if (!response.ok || !result.success)
    throw new Error(result.message || 'Unable to load this conversation.');
  userTicketId = result.conversation.id;
  userTicketMessages = result.messages || [];
  setTicketDetail(result.conversation, false);
  filterTicketHistory(false, background);
  if (!background) filterUserTickets();
}
async function loadAdminTicket(id, background = false) {
  let response = await fetch(
      new URL(
        `api/admin-chat.php?conversation_id=${encodeURIComponent(id)}`,
        window.location.href,
      ),
      { credentials: 'same-origin' },
    ),
    result = await response.json();
  if (!response.ok || !result.success)
    throw new Error(result.message || 'Unable to load this conversation.');
  adminTicketId = result.conversation.id;
  adminTicketMessages = result.messages || [];
  let summary = adminTickets.find((item) => item.id === adminTicketId);
  result.conversation.assigned_admin_name =
    summary?.assigned_admin_name || null;
  setTicketDetail(result.conversation, true);
  filterTicketHistory(true, background);
  if (!background) filterAdminTickets();
}

function selectUserTicket(id) {
  let next = Number(id);
  if (!next) return;
  if (userTicketId === next)
    return loadUserTicket(next).catch((error) => toast(error.message));
  go(`/chat/${next}`);
}
function selectAdminTicket(id) {
  let next = Number(id);
  if (!next) return;
  if (adminTicketId === next)
    return loadAdminTicket(next).catch((error) => toast(error.message));
  go(`/admin/chat/${next}`);
}

function createUserTicket() {
  let modal = $('#new-ticket-modal'),
    subject = $('#new-ticket-subject');
  if (!modal) return;
  modal.hidden = false;
  if (subject) {
    subject.value = 'Shelter Support';
    subject.focus();
    let end = subject.value.length;
    subject.setSelectionRange(end, end);
  }
}
function closeUserTicketModal() {
  let modal = $('#new-ticket-modal');
  if (!modal || modal.hidden) return;
  modal.hidden = true;
  $('.live-chat-new-action')?.focus();
}
async function submitUserTicket() {
  let subject = $('#new-ticket-subject')?.value.trim() || '';
  if (!subject || subject.length > 150)
    return toast('Enter a conversation subject of 150 characters or fewer.');
  let button = $('#new-ticket-submit');
  setButtonBusy(button, true, 'Creating…');
  try {
    let response = await fetch(new URL('api/chat.php', window.location.href), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'create', subject }),
      }),
      result = await response.json();
    if (response.status === 409 && result.conversation) {
      closeUserTicketModal();
      toast(result.message);
      return go(`/chat/${result.conversation.id}`);
    }
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to create a support ticket.');
    closeUserTicketModal();
    toast(result.message);
    go(`/chat/${result.conversation.id}`);
  } catch (error) {
    toast('Unable to create a support ticket. Please try again later.');
  } finally {
    if (button && document.body.contains(button)) setButtonBusy(button, false);
  }
}
async function closeUserTicket() {
  if (
    !userTicketId ||
    !(await confirmAction({
      title: `Close ${ticketLabel(userTicketId)}?`,
      message: 'Its conversation history will remain available as read-only.',
      confirmLabel: 'Close ticket',
      tone: 'warning',
    }))
  )
    return;
  try {
    let response = await fetch(new URL('api/chat.php', window.location.href), {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'close',
          conversation_id: userTicketId,
        }),
      }),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to close this ticket.');
    toast(result.message);
    await loadUserTickets();
  } catch (error) {
    toast('Unable to close this ticket. Please try again later.');
  }
}
async function closeAdminTicket() {
  if (
    !adminTicketId ||
    !(await confirmAction({
      title: `Close ${ticketLabel(adminTicketId)}?`,
      message: 'Its conversation history will remain available as read-only.',
      confirmLabel: 'Close ticket',
      tone: 'warning',
    }))
  )
    return;
  try {
    let response = await fetch(
        new URL(
          `api/admin-chat.php?conversation_id=${encodeURIComponent(adminTicketId)}`,
          window.location.href,
        ),
        {
          method: 'PATCH',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'close' }),
        },
      ),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to close this ticket.');
    toast(result.message);
    await loadAdminTickets();
  } catch (error) {
    toast('Unable to close this ticket. Please try again later.');
  }
}

// Message submission
async function sendDatabaseChat(admin = false) {
  let input = $('#chat-text'),
    message = input?.value.trim() || '',
    id = admin ? adminTicketId : userTicketId;
  if (!message || message.length > 2000)
    return toast('Enter a Live Chat message of 2,000 characters or fewer.');
  if (!id) return toast('Select an open support ticket first.');
  let button = $('#chat-send-button');
  setButtonBusy(button, true, 'Sending…');
  try {
    let endpoint = admin
        ? `api/admin-chat.php?conversation_id=${encodeURIComponent(id)}`
        : 'api/chat.php',
      body = admin
        ? { message }
        : { action: 'message', conversation_id: id, message },
      response = await fetch(new URL(endpoint, window.location.href), {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      }),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to send this message.');
    input.value = '';
    toast(result.message);
    if (admin) await loadAdminTickets();
    else await loadUserTickets();
  } catch (error) {
    toast('Unable to send this message. Please try again later.');
  } finally {
    if (button && document.body.contains(button)) setButtonBusy(button, false);
  }
}

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') closeUserTicketModal();
});
render();
