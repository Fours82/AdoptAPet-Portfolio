function databaseAdminDashboardPage() {
  setTimeout(loadAdminDashboard, 0);
  return shell(
    `<div id="admin-dashboard-content"><div class="page-title"><h1>Administrator Dashboard</h1><p>Live shelter and system activity is loading.</p></div><div class="panel empty loading-state">Loading dashboard…</div></div>`,
    true,
  );
}

async function loadAdminDashboard() {
  let target = $('#admin-dashboard-content');
  if (!target) return;
  try {
    let analyticsUrl = new URL('api/admin-analytics.php', window.location.href);
    analyticsUrl.searchParams.set('months', '6');
    let applicationsUrl = new URL(
      'api/admin-adoption-applications.php',
      window.location.href,
    );
    applicationsUrl.searchParams.set('status', 'pending');
    let [analyticsResponse, applicationsResponse] = await Promise.all([
        fetch(analyticsUrl, { credentials: 'same-origin' }),
        fetch(applicationsUrl, { credentials: 'same-origin' }),
      ]),
      analyticsResult = await analyticsResponse.json(),
      applicationsResult = await applicationsResponse.json();
    if (!analyticsResponse.ok || !analyticsResult.success)
      throw new Error(
        analyticsResult.message || 'Unable to load administrator statistics.',
      );
    if (!applicationsResponse.ok || !applicationsResult.success)
      throw new Error(
        applicationsResult.message || 'Unable to load pending applications.',
      );
    let summary = analyticsResult.summary,
      pending = applicationsResult.applications || [];
    let pendingPreview = pending.length
      ? pending
          .slice(0, 5)
          .map(
            (application) =>
              `<article class="admin-application-preview"><div class="admin-application-person"><span class="admin-application-avatar" aria-hidden="true">${esc((application.applicant_name || 'A').charAt(0).toUpperCase())}</span><div><b>${esc(application.applicant_name)}</b><small>${esc(application.applicant_email || '')}</small></div></div><div class="admin-application-pet"><small>Pet Listing</small><b>${esc(application.pet_name)}</b></div><div class="admin-application-date"><small>Submitted</small><span>${date(application.submitted_at)}</span></div><span class="badge pending">Pending</span><a class="btn outline sm" href="#/admin/applications">Review</a></article>`,
          )
          .join('')
      : '<div class="admin-dashboard-empty"><span aria-hidden="true">✓</span><div><b>No pending applications</b><p>All submitted Adoption Applications have been reviewed.</p></div></div>';
    target.innerHTML = `<div class="admin-dashboard-page"><div class="admin-page-heading admin-dashboard-heading"><div class="page-title"><span class="eyebrow">Administration overview</span><h1>Administrator Dashboard</h1><p>Monitor live AdoptAPet shelter and system activity.</p></div><a class="btn outline" href="#/admin/analytics">View Full Report</a></div><div class="stats admin-dashboard-stats"><div class="stat"><span>Available Pet Listings</span><strong>${summary.available_pets}</strong><small>Ready for applications</small></div><div class="stat"><span>Pending Applications</span><strong>${pending.length}</strong><small>Awaiting administrator review</small></div><div class="stat"><span>Successful Adoptions</span><strong>${summary.successful_adoptions}</strong><small>Approved applications</small></div><div class="stat"><span>Completed Donations</span><strong>${money(summary.donation_total)}</strong><small>Total funds received</small></div><div class="stat"><span>Stray Reports</span><strong>${summary.stray_reports}</strong><small>Reports submitted</small></div><div class="stat"><span>Active Registered Users</span><strong>${summary.active_registered_users}</strong><small>Active community accounts</small></div></div><div class="admin-dashboard-columns"><section class="panel admin-dashboard-panel admin-dashboard-applications"><div class="section-head"><div><span class="eyebrow">Review queue</span><h2>Pending Adoption Applications</h2><p class="muted">Applications currently waiting for an Administrator decision.</p></div><a class="link-btn" href="#/admin/applications">View all</a></div><div class="admin-application-preview-list">${pendingPreview}</div></section><aside class="admin-dashboard-side" aria-label="System summary and shortcuts"><section class="panel admin-dashboard-panel admin-dashboard-activity"><div class="section-head"><div><span class="eyebrow">Live totals</span><h2>System Activity</h2></div></div><dl class="admin-activity-grid"><div><dt>Total Pet Listings</dt><dd>${summary.pet_listings}</dd></div><div><dt>Community Posts</dt><dd>${summary.community_posts}</dd></div><div><dt>Published News</dt><dd>${summary.published_news}</dd></div><div><dt>Completed Donations</dt><dd>${summary.completed_donations}</dd></div></dl></section><section class="panel admin-dashboard-panel admin-dashboard-shortcut-panel"><div class="section-head"><div><span class="eyebrow">Common tasks</span><h2>Management Shortcuts</h2></div></div><div class="admin-dashboard-shortcut-grid"><a class="btn sm" href="#/admin/pets">Manage Pet Listings</a><a class="btn outline sm" href="#/admin/analytics">Open Analytics</a><a class="btn outline sm" href="#/admin/news">Publish News</a></div></section></aside></div></div>`;
  } catch (error) {
    target.innerHTML = `<div class="page-title"><h1>Administrator Dashboard</h1></div><div class="panel empty error-state"><h2>Dashboard unavailable</h2><p>${esc(error.message || 'Unable to load administrator dashboard.')}</p><button class="btn" onclick="loadAdminDashboard()">Retry</button></div>`;
  }
}
