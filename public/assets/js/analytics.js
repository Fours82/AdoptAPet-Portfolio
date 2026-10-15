function databaseAnalyticsPage() {
  setTimeout(loadAnalytics, 0);
  return shell(
    `<div class="admin-page-heading"><div class="page-title"><span class="eyebrow">Live reporting</span><h1>Analytics & Reporting</h1><p>Monitor adoption activity, donations, account growth, and system participation.</p></div></div><section class="panel admin-analytics-range-panel" aria-labelledby="analytics-range-title"><div><h2 id="analytics-range-title">Reporting period</h2><p class="muted">Charts update automatically when the selected period changes.</p></div><div class="field"><label for="analytics-months">Time range</label><select id="analytics-months" onchange="loadAnalytics()"><option value="3">Last 3 months</option><option value="6" selected>Last 6 months</option><option value="12">Last 12 months</option></select></div></section><div id="analytics-content" class="panel" aria-busy="true">${skeletonAnalytics()}</div>`,
    true,
  );
}

function analyticsBars(points, valueKey, formatter) {
  let maximum = Math.max(
    1,
    ...points.map((point) => Number(point[valueKey]) || 0),
  );
  return `<div class="admin-analytics-chart-scroll"><div class="bar-chart admin-analytics-bars" style="--analytics-points:${points.length}">${points
    .map((point) => {
      let value = Number(point[valueKey]) || 0,
        height = value ? Math.max(12, Math.round((value / maximum) * 100)) : 8;
      return `<div class="bar" style="height:${height}%"><b>${esc(formatter(value))}</b><span>${esc(point.label.replace(' ', '\u00a0'))}</span></div>`;
    })
    .join('')}</div></div>`;
}

function analyticsBreakdownRows(values) {
  return Object.entries(values)
    .map(
      ([status, total]) =>
        `<tr><td><span class="badge ${esc(status)}">${esc(petStatusLabel(status))}</span></td><td><b>${Number(total) || 0}</b></td></tr>`,
    )
    .join('');
}

function analyticsContent(result) {
  let summary = result.summary;
  return `<div class="admin-analytics-period-summary"><span class="badge active">${result.period.months}-month view</span><p>Reporting from <b>${date(result.period.from)}</b> to <b>${date(result.period.to)}</b></p></div><div class="stats admin-dashboard-stats admin-analytics-kpis"><div class="stat"><strong>${summary.total_applications}</strong><span>Total Applications</span><small>All submitted adoption applications</small></div><div class="stat"><strong>${summary.successful_adoptions}</strong><span>Successful Adoptions</span><small>${summary.application_approval_rate}% approval rate</small></div><div class="stat"><strong>${summary.pet_listings}</strong><span>Pet Listings</span><small>${summary.available_pets} currently available</small></div><div class="stat"><strong>${money(summary.donation_total)}</strong><span>Completed Donations</span><small>${summary.completed_donations} completed contributions</small></div></div><section class="admin-analytics-chart-grid"><article class="panel admin-analytics-card"><div class="admin-analytics-card-heading"><div><span class="eyebrow">Adoption demand</span><h2>Application Trend</h2><p>Applications submitted during each month in the selected period.</p></div></div>${analyticsBars(result.trends.applications, 'total', (value) => String(value))}</article><article class="panel admin-analytics-card"><div class="admin-analytics-card-heading"><div><span class="eyebrow">Fundraising</span><h2>Donation Trend</h2><p>Completed donation value recorded for each month.</p></div></div>${analyticsBars(result.trends.donations, 'total_amount', (value) => money(value))}</article><article class="panel admin-analytics-card"><div class="admin-analytics-card-heading"><div><span class="eyebrow">Account growth</span><h2>Registered User Growth</h2><p>New Registered User accounts created during the selected period.</p></div></div>${analyticsBars(result.trends.registered_users, 'registrations', (value) => String(value))}</article><article class="panel admin-analytics-card admin-analytics-activity-card"><div class="admin-analytics-card-heading"><div><span class="eyebrow">Current totals</span><h2>System Activity</h2><p>Live participation totals across key system areas.</p></div></div><dl class="admin-analytics-activity"><div><dt>Stray Reports</dt><dd>${summary.stray_reports}</dd></div><div><dt>Active Registered Users</dt><dd>${summary.active_registered_users}</dd></div><div><dt>Published Community Posts</dt><dd>${summary.community_posts}</dd></div><div><dt>Published News</dt><dd>${summary.published_news}</dd></div></dl></article></section><section class="admin-analytics-breakdown-grid"><article class="panel admin-analytics-breakdown"><div class="admin-analytics-card-heading"><div><span class="eyebrow">Application pipeline</span><h2>Application Status Breakdown</h2><p>Current totals across every application state.</p></div><strong>${summary.application_approval_rate}% approved</strong></div><div class="table-wrap"><table class="table"><thead><tr><th>Application Status</th><th>Total</th></tr></thead><tbody>${analyticsBreakdownRows(result.breakdowns.applications)}</tbody></table></div></article><article class="panel admin-analytics-breakdown"><div class="admin-analytics-card-heading"><div><span class="eyebrow">Listing availability</span><h2>Pet Status Breakdown</h2><p>Current distribution of Pet Listings by status.</p></div><strong>${summary.adopted_pets} adopted</strong></div><div class="table-wrap"><table class="table"><thead><tr><th>Pet Status</th><th>Total</th></tr></thead><tbody>${analyticsBreakdownRows(result.breakdowns.pets)}</tbody></table></div></article></section><p class="footer-note admin-analytics-footnote">Statistics were generated from the live database for the selected ${result.period.months}-month reporting period.</p>`;
}

async function loadAnalytics() {
  let target = $('#analytics-content');
  if (!target) return;
  target.className = 'panel';
  setSkeleton(target, skeletonAnalytics());
  try {
    let url = new URL('api/admin-analytics.php', window.location.href),
      months = $('#analytics-months')?.value || '6';
    url.searchParams.set('months', months);
    let response = await fetch(url, { credentials: 'same-origin' }),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    clearLoading(target);
    target.className = '';
    target.innerHTML = analyticsContent(result);
  } catch (error) {
    clearLoading(target);
    target.className = 'panel empty';
    target.innerHTML = esc(error.message || 'Unable to load analytics.');
  }
}

render();
