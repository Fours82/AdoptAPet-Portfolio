let adminAuditPageNumber = 1;
const adminAuditPageSize = 20;

function adminAuditPage() {
  setTimeout(() => adminAuditLoad(1), 0);
  return shell(
    `<div class="admin-page-heading"><div class="page-title"><span class="eyebrow">Administrator accountability</span><h1>Audit Log</h1><p>Meaningful changes made in the Administrator workspace, newest first.</p></div></div>
    <section class="panel admin-audit-panel" aria-labelledby="admin-audit-heading"><div class="admin-audit-toolbar"><div><h2 id="admin-audit-heading">Recorded actions</h2><p class="muted">This history is read-only.</p></div><div class="field"><label for="admin-audit-module">Module</label><select id="admin-audit-module" onchange="adminAuditLoad(1)"><option value="">All modules</option><option value="user">User Management</option><option value="pet">Pet Listings</option><option value="medical_record">Medical / Vaccination</option><option value="adoption_application">Adoption Applications</option><option value="stray_report">Stray Reports</option><option value="news_post">News</option><option value="community_post">Community</option><option value="chat_conversation">Live Chat</option></select></div></div>
      <div class="admin-table-meta"><p id="admin-audit-results" class="muted" aria-live="polite">Loading recorded actions…</p></div><div class="table-wrap"><table class="table admin-audit-table"><thead><tr><th>Time</th><th>Administrator</th><th>Action</th><th>Module</th><th>Target</th><th>Details</th></tr></thead><tbody id="admin-audit-rows"><tr><td colspan="6">Loading Audit Log…</td></tr></tbody></table></div><div id="admin-audit-pagination" class="admin-audit-pagination"></div></section>`,
    true,
  );
}

function adminAuditRow(entry) {
  const target = entry.target || entry.target_id || '—';
  const identifier =
    entry.target_id && entry.target_id !== target
      ? `<small>${esc(entry.target_id)}</small>`
      : '';
  return `<tr><td data-label="Time">${esc(entry.created_at)}</td><td data-label="Administrator">${esc(entry.actor)}</td><td data-label="Action">${esc(entry.action)}</td><td data-label="Module">${esc(entry.module)}</td><td data-label="Target" class="admin-audit-target">${esc(target)}${identifier}</td><td data-label="Details">${esc(entry.details)}</td></tr>`;
}

async function adminAuditLoad(page) {
  const rows = $('#admin-audit-rows'),
    results = $('#admin-audit-results'),
    pagination = $('#admin-audit-pagination');
  if (!rows || !pagination || currentHashRoute() !== '/admin/audit') return;
  rows.innerHTML = '<tr><td colspan="6">Loading Audit Log…</td></tr>';
  if (results) results.textContent = 'Loading recorded actions…';
  pagination.innerHTML = '';
  try {
    const url = new URL('api/admin-audit.php', window.location.href);
    url.searchParams.set('page', String(page));
    const entity = $('#admin-audit-module')?.value || '';
    if (entity) url.searchParams.set('entity', entity);
    const response = await fetch(url, { credentials: 'same-origin' }),
      result = await response.json();
    if (!response.ok || !result.success)
      throw new Error(result.message || 'Unable to load Audit Log.');
    if (currentHashRoute() !== '/admin/audit' || !rows.isConnected) return;
    adminAuditPageNumber = Number(result.page) || 1;
    rows.innerHTML = result.entries?.length
      ? result.entries.map(adminAuditRow).join('')
      : '<tr><td colspan="6" class="muted">No Audit Log entries match this module.</td></tr>';
    const total = Math.max(0, Number(result.total) || 0),
      start = total ? (adminAuditPageNumber - 1) * adminAuditPageSize + 1 : 0,
      end = total
        ? Math.min(adminAuditPageNumber * adminAuditPageSize, total)
        : 0;
    if (results)
      results.textContent = total
        ? `Showing ${start}–${end} of ${total} recorded actions`
        : '0 recorded actions';
    const pages = Number(result.total_pages) || 1;
    pagination.innerHTML = paginationMarkup(
      adminAuditPageNumber,
      pages,
      'changeAdminAuditPage',
      'Audit Log pages',
    );
  } catch (error) {
    if (currentHashRoute() !== '/admin/audit' || !rows.isConnected) return;
    if (results) results.textContent = 'Unable to load recorded actions';
    rows.innerHTML = `<tr><td colspan="6" class="empty error-state">${esc(error.message || 'Unable to load Audit Log.')}</td></tr>`;
  }
}

function changeAdminAuditPage(page) {
  const next = Math.max(1, Number(page) || 1);
  if (next === adminAuditPageNumber) return;
  return adminAuditLoad(next).then(() =>
    paginationScroll('#admin-audit-results'),
  );
}
