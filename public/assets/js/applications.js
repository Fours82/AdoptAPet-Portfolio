let ownApplicationCache = [];
let adminApplicationCache = [];
let ownApplicationPage = 1,
  adminApplicationPage = 1;
let ownApplicationAppliedCriteria = null;
const applicationPageSize = 10,
  adminApplicationPageSize = 10;

function applicationStatusLabel(status) {
  return petStatusLabel(status);
}
function applicationBadge(status) {
  let css = [
    'pending',
    'approved',
    'rejected',
    'withdrawn',
    'cancelled',
  ].includes(status)
    ? status
    : '';
  return `<span class="badge ${css}">${applicationStatusLabel(status)}</span>`;
}

function applyPage(id) {
  if (state.role !== 'Registered User')
    return shell(
      '<div class="empty panel">Please <a href="#/login">log in</a> with a verified Registered User account to submit an Adoption Application.</div>',
    );
  setTimeout(() => loadApplicationForm(id), 0);
  return shell(
    `<div id="application-form" class="application-apply-ux" aria-busy="true"><div class="panel empty loading-state">${skeletonList(2, 'Loading Pet Listing')}</div></div>`,
  );
}

function applicationApplyPhoto(pet) {
  return pet.image_path
    ? `<img class="application-apply-image" src="${esc(pet.image_path)}" alt="${esc(`${pet.name}, pet selected for this application`)}" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><span class="application-apply-image application-apply-image-fallback" hidden role="img" aria-label="Photo unavailable">${uiIcon('paw')}</span>`
    : `<span class="application-apply-image application-apply-image-fallback" role="img" aria-label="Photo unavailable">${uiIcon('paw')}</span>`;
}
function setApplicationReasonValidation(message = '') {
  let input = $('#app-reason'),
    validation = $('#application-reason-validation');
  if (validation) validation.textContent = message;
  if (input) {
    if (message) input.setAttribute('aria-invalid', 'true');
    else input.removeAttribute('aria-invalid');
  }
}
function clearApplicationReasonValidation() {
  if ($('#app-reason')?.getAttribute('aria-invalid') === 'true')
    setApplicationReasonValidation('');
}

// Adoption application workflow
async function loadApplicationForm(id) {
  let target = $('#application-form');
  try {
    let response = await fetch(
        new URL(
          `api/pets.php?id=${encodeURIComponent(id)}`,
          window.location.href,
        ),
      ),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    let pet = result.pet;
    if (pet.adoption_status !== 'available') {
      clearLoading(target);
      target.className = 'panel empty';
      target.innerHTML = `<h1>Applications unavailable</h1><p>${esc(pet.name)} is not currently accepting Adoption Applications.</p><a class="btn outline" href="#/pet/${pet.id}">Back to Pet Details</a>`;
      return;
    }
    let description = [
      pet.breed,
      pet.species ? petStatusLabel(pet.species) : '',
    ]
      .filter(Boolean)
      .map(esc)
      .join(' · ');
    clearLoading(target);
    target.className = 'application-apply-ux';
    target.innerHTML = `<header class="standard-page-heading"><h1>Submit Adoption Application</h1><p>Applying to adopt ${esc(pet.name)}. Please tell us about the home and care you can provide.</p></header><div class="application-apply-layout"><aside class="application-apply-pet" aria-labelledby="application-pet-name"><div class="application-apply-photo">${applicationApplyPhoto(pet)}</div><div class="application-apply-pet-content"><div class="application-apply-pet-heading"><h2 id="application-pet-name">${esc(pet.name)}</h2><span class="badge ${esc(pet.adoption_status)}">${esc(petStatusLabel(pet.adoption_status))}</span></div>${description ? `<p class="application-apply-pet-kind">${description}</p>` : ''}<dl class="application-apply-facts">${pet.species ? `<div><dt>Species</dt><dd>${esc(petStatusLabel(pet.species))}</dd></div>` : ''}${pet.breed ? `<div><dt>Breed</dt><dd>${esc(pet.breed)}</dd></div>` : ''}${pet.age_label ? `<div><dt>Age</dt><dd>${esc(pet.age_label)}</dd></div>` : ''}${pet.sex ? `<div><dt>Sex</dt><dd>${esc(petStatusLabel(pet.sex))}</dd></div>` : ''}<div><dt>Status</dt><dd>${esc(petStatusLabel(pet.adoption_status))}</dd></div></dl><a class="application-apply-pet-link" href="#/pet/${pet.id}">View pet details <span aria-hidden="true">→</span></a></div></aside><section class="application-apply-form" aria-labelledby="application-form-heading"><header class="application-apply-form-heading"><span class="eyebrow">Adoption application</span><h2 id="application-form-heading">Tell us about your future home</h2><p>Share enough detail to help the shelter assess a safe, lasting match.</p></header><div class="field application-reason-field"><label for="app-reason">Why would you like to adopt ${esc(pet.name)}? <span class="required" aria-hidden="true">*</span><span class="sr-only"> required</span></label><textarea id="app-reason" rows="7" required minlength="15" maxlength="65535" aria-describedby="application-reason-help application-reason-validation" placeholder="Tell us about your home, experience and plans for care." oninput="clearApplicationReasonValidation()"></textarea><p id="application-reason-help" class="helper">Please provide at least 15 characters about the home and ongoing care you can offer.</p><p id="application-reason-validation" class="validation application-reason-validation" role="alert" aria-live="polite"></p></div><div class="application-apply-actions"><button type="button" class="btn outline" onclick="go('/pet/${pet.id}')">Cancel</button><button type="button" id="application-submit" class="btn" onclick="submitApplication(${pet.id})">Submit Application</button></div></section></div>`;
  } catch (error) {
    clearLoading(target);
    target.innerHTML = `<div class="empty">${esc(error.message || 'This Pet Listing is unavailable for an application.')}</div>`;
  }
}

/** Submits the completed application through the authenticated API. */
async function submitApplication(id) {
  let reason = $('#app-reason').value.trim();
  if (reason.length < 15) {
    let message =
      'Please provide at least 15 characters for the Adoption Application.';
    setApplicationReasonValidation(message);
    $('#app-reason')?.focus();
    return toast(message);
  }
  setApplicationReasonValidation('');
  let button = $('#application-submit');
  setButtonBusy(button, true, 'Submitting…');
  try {
    let response = await fetch(
        new URL('api/adoption-applications.php', window.location.href),
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ pet_id: id, reason }),
        },
      ),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(
        result.message || 'Unable to submit the Adoption Application.',
      );
    toast(result.message);
    go('/applications');
  } catch (error) {
    toast('Unable to submit the Adoption Application. Please try again later.');
  } finally {
    if (button?.isConnected) setButtonBusy(button, false);
  }
}

function applications() {
  cancelLiveSearch('own-applications');
  ownApplicationAppliedCriteria = null;
  setTimeout(loadOwnApplications, 0);
  return shell(
    `<div class="applications-ux"><header class="applications-heading"><h1>My Adoption Applications</h1><p>Track the status of your submitted applications.</p></header><section class="panel compact-filter-panel applications-filters" aria-label="Application filters"><div class="application-filter-grid"><div class="field"><label for="application-search">Search by pet name</label><div class="field-with-icon">${uiIcon('search', 'input-icon')}<input id="application-search" class="input" maxlength="100" placeholder="Pet name" oninput="scheduleOwnApplicationSearch()" onkeydown="runOwnApplicationSearch(event)"></div></div><div class="field"><label for="application-status-filter">Status</label><select id="application-status-filter" onchange="filterOwnApplications()"><option value="">All statuses</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="cancelled">Cancelled</option><option value="withdrawn">Withdrawn</option></select></div><div class="field"><label for="application-sort">Sort</label><select id="application-sort" onchange="filterOwnApplications()"><option value="newest">Newest first</option><option value="oldest">Oldest first</option></select></div></div></section><p id="application-results-count" class="muted applications-count" aria-live="polite">Loading applications…</p><div id="own-applications-list" class="application-list-compact" aria-busy="true">${skeletonList(4, 'Loading applications')}</div><div id="own-applications-pagination"></div></div>`,
  );
}

function applicationPhoto(application, className) {
  return application.pet_image_path
    ? `<img class="${className}" src="${esc(application.pet_image_path)}" alt="${esc(application.pet_name)}" onerror="this.hidden=true;this.nextElementSibling.hidden=false"><span class="application-photo-fallback ${className}" hidden aria-label="Photo unavailable">${uiIcon('paw')}</span>`
    : `<span class="application-photo-fallback ${className}" aria-label="Photo unavailable">${uiIcon('paw')}</span>`;
}
function applicationPetDescription(application) {
  return (
    [
      application.pet_breed,
      application.pet_species ? petStatusLabel(application.pet_species) : '',
    ]
      .filter(Boolean)
      .map(esc)
      .join(' · ') || 'Pet details unavailable'
  );
}
function applicationCard(application) {
  return `<a class="application-row" href="#/application/${application.id}" aria-label="View application #${application.id} for ${esc(application.pet_name)}"><span class="application-row-photo">${applicationPhoto(application, 'application-row-image')}</span><span class="application-row-pet"><strong>${esc(application.pet_name)}</strong><span>${applicationPetDescription(application)}</span><small>Application #${application.id}</small></span><span class="application-row-date"><small>Applied</small><b>${date(application.submitted_at)}</b></span><span class="application-row-state">${applicationBadge(application.application_status)}</span><span class="application-row-chevron" aria-hidden="true">›</span></a>`;
}
function filterOwnApplications(resetPage = true) {
  let query = $('#application-search')?.value.trim().toLowerCase() || '',
    status = $('#application-status-filter')?.value || '',
    sort = $('#application-sort')?.value || 'newest',
    criteria = JSON.stringify({ query, status, sort });
  if (resetPage && criteria === ownApplicationAppliedCriteria) return;
  let items = ownApplicationCache.filter(
    (application) =>
      (!query ||
        String(application.pet_name || '')
          .toLowerCase()
          .includes(query)) &&
      (!status || application.application_status === status),
  );
  items.sort((left, right) => {
    let a = new Date(String(left.submitted_at).replace(' ', 'T')).getTime(),
      b = new Date(String(right.submitted_at).replace(' ', 'T')).getTime();
    return sort === 'oldest' ? a - b : b - a;
  });
  if (resetPage) ownApplicationPage = 1;
  ownApplicationAppliedCriteria = criteria;
  let page = paginatedItems(items, ownApplicationPage, applicationPageSize),
    target = $('#own-applications-list'),
    count = $('#application-results-count'),
    controls = $('#own-applications-pagination');
  ownApplicationPage = page.page;
  if (count)
    count.textContent = page.total
      ? `Showing ${page.start + 1}–${page.end} of ${page.total} applications${query || status ? ` · ${ownApplicationCache.length} total` : ''}`
      : `0 applications${query || status ? ` · ${ownApplicationCache.length} total` : ''}`;
  if (target)
    target.innerHTML = page.items.length
      ? page.items.map(applicationCard).join('')
      : `<div class="panel empty"><h2>No matching applications</h2><p>Try another pet name or application status.</p></div>`;
  if (controls)
    controls.innerHTML = paginationMarkup(
      page.page,
      page.totalPages,
      'changeOwnApplicationPage',
      'My Application pages',
    );
}
function scheduleOwnApplicationSearch() {
  scheduleLiveSearch('own-applications', filterOwnApplications);
}
function runOwnApplicationSearch(event) {
  runLiveSearchNow(event, 'own-applications', filterOwnApplications);
}
function changeOwnApplicationPage(page) {
  let query = $('#application-search')?.value.trim().toLowerCase() || '',
    status = $('#application-status-filter')?.value || '',
    items = ownApplicationCache.filter(
      (application) =>
        (!query ||
          String(application.pet_name || '')
            .toLowerCase()
            .includes(query)) &&
        (!status || application.application_status === status),
    ),
    next = paginatedItems(items, page, applicationPageSize);
  if (next.page === ownApplicationPage) return;
  ownApplicationPage = next.page;
  filterOwnApplications(false);
  paginationScroll('#application-results-count');
}

async function loadOwnApplications() {
  let body = $('#own-applications-list');
  setSkeleton(body, skeletonList(4, 'Loading applications'));
  try {
    let response = await fetch(
        new URL('api/adoption-applications.php', window.location.href),
        { credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    ownApplicationCache = result.applications;
    ownApplicationAppliedCriteria = null;
    clearLoading(body);
    if (ownApplicationCache.length) filterOwnApplications();
    else {
      $('#application-results-count').textContent = '0 applications';
      body.innerHTML =
        '<div class="panel empty"><h2>No applications yet</h2><p>Browse available pets when you are ready to begin your adoption journey.</p><a class="btn" href="#/pets">Browse Pets</a></div>';
    }
  } catch (error) {
    clearLoading(body);
    body.innerHTML = `<div class="panel empty error-state"><h2>Unable to load applications</h2><p>${esc(error.message || 'Please try again.')}</p><button class="btn" onclick="loadOwnApplications()">Retry</button></div>`;
  }
}

function applicationDetail(id) {
  if (state.role !== 'Registered User')
    return shell(
      '<div class="empty panel">Please <a href="#/login">log in</a> to view this application.</div>',
    );
  setTimeout(() => loadOwnApplicationDetail(id), 0);
  return shell(
    '<div id="application-detail" class="empty panel">Loading application…</div>',
  );
}

async function loadOwnApplicationDetail(id) {
  let target = $('#application-detail');
  try {
    let response = await fetch(
        new URL(
          `api/adoption-applications.php?id=${encodeURIComponent(id)}`,
          window.location.href,
        ),
        { credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    let application = result.application;
    let history = application.status_history || [],
      changes = history.filter((event) => event.old_status !== null);
    let feedback = String(
      application.admin_notes ||
        changes
          .filter(
            (event) =>
              event.new_status === 'rejected' ||
              event.new_status === 'approved',
          )
          .at(-1)?.notes ||
        '',
    ).trim();
    let reviewed = ['approved', 'rejected'].includes(
      application.application_status,
    );
    let updated =
      application.last_updated_at &&
      new Date(
        String(application.last_updated_at).replace(' ', 'T'),
      ).getTime() >
        new Date(
          String(application.submitted_at).replace(' ', 'T'),
        ).getTime() &&
      date(application.last_updated_at) !== date(application.submitted_at);
    let timeline = `<ol class="application-progress-list"><li><b>Submitted</b><time>${date(application.submitted_at)}</time><p>Your adoption application was received.</p></li>${changes.map((event) => `<li><b>${esc(applicationStatusLabel(event.new_status))}</b><time>${date(event.changed_at)}</time></li>`).join('')}</ol>`;
    let currentContext =
      application.application_status === 'pending'
        ? '<p class="application-progress-context">Your application is waiting for a review decision.</p>'
        : changes.length
          ? ''
          : '<p class="application-progress-context">No further status changes have been recorded.</p>';
    let location = application.pet_location
      ? `<span>${uiIcon('pin')} ${esc(application.pet_location)}</span>`
      : '';
    let actions =
      application.application_status === 'approved'
        ? `<a class="btn application-primary-action" href="api/documents.php?type=adoption_certificate&amp;id=${application.id}">${uiIcon('file')}Download Adoption Certificate</a><a class="btn outline" href="#/chat">${petDetailIcon('chat')}Chat with Shelter</a>`
        : application.application_status === 'pending'
          ? `<a class="btn outline" href="#/chat">${petDetailIcon('chat')}Chat with Shelter</a><button class="btn danger" type="button" onclick="cancelApplication(${application.id})">Cancel Application</button>`
          : application.application_status === 'rejected'
            ? `<a class="btn outline" href="#/chat">${petDetailIcon('chat')}Chat with Shelter</a>`
            : '';
    target.className = 'application-detail-ux';
    target.innerHTML = `<nav class="application-detail-back"><a href="#/applications">← Back to My Applications</a></nav><header class="application-detail-heading"><div><span class="application-reference">Application #${application.id}</span><h1>Adoption Application</h1></div>${applicationBadge(application.application_status)}</header><div class="application-detail-columns"><div class="application-detail-main"><section class="application-pet-summary" aria-label="Pet applied for"><div class="application-detail-photo">${applicationPhoto(application, 'application-detail-image')}</div><div><h2>${esc(application.pet_name)}</h2><p>${applicationPetDescription(application)}</p>${location}</div></section><section class="application-detail-section" aria-labelledby="application-reason-heading"><h2 id="application-reason-heading">${uiIcon('file')}Application reason</h2><p class="application-reason-text">${esc(application.reason)}</p></section><section class="application-detail-section application-dates" aria-label="Application dates"><div>${uiIcon('calendar')}<span>Submitted <strong>${date(application.submitted_at)}</strong></span></div>${updated ? `<div>${uiIcon('calendar')}<span>Last updated <strong>${date(application.last_updated_at)}</strong></span></div>` : ''}</section>${reviewed ? `<section class="application-detail-section application-feedback" aria-labelledby="application-feedback-heading"><h2 id="application-feedback-heading">${uiIcon('file')}Review feedback</h2><p class="application-reason-text">${feedback ? esc(feedback) : 'No review reason was provided.'}</p></section>` : ''}</div><aside class="application-progress" aria-labelledby="application-progress-heading"><h2 id="application-progress-heading">Application progress</h2>${timeline}${currentContext}</aside></div>${actions ? `<section class="application-detail-actions" aria-label="Application actions"><h2>Actions</h2><div>${actions}</div></section>` : ''}`;
  } catch (error) {
    target.innerHTML = `<div class="empty error-state"><h1>Application unavailable</h1><p>${esc(error.message || 'Application not found.')}</p><a class="btn" href="#/applications">Back to Applications</a></div>`;
  }
}

function databaseCertificatePage(id) {
  if (state.role !== 'Registered User')
    return shell(
      '<div class="empty panel">Please <a href="#/login">log in</a> to view this Adoption Certificate.</div>',
    );
  setTimeout(() => loadAdoptionCertificate(id), 0);
  return shell(
    '<div id="adoption-certificate" class="panel empty loading-state">Loading Adoption Certificate…</div>',
  );
}

async function loadAdoptionCertificate(id) {
  let target = $('#adoption-certificate');
  if (!target) return;
  try {
    let response = await fetch(
        new URL(
          `api/adoption-applications.php?id=${encodeURIComponent(id)}`,
          window.location.href,
        ),
        { credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    let application = result.application;
    if (application.application_status !== 'approved')
      throw new Error(
        'An Adoption Certificate is available only after approval.',
      );
    target.className = '';
    target.innerHTML = `<div class="no-print"><a class="btn sm" href="api/documents.php?type=adoption_certificate&amp;id=${application.id}">Download PDF Certificate</a> <a class="btn outline sm" href="#/application/${application.id}">Application Details</a></div><article class="receipt"><div class="receipt-header"><div><div class="brand"><span class="brand-mark">♥</span>AdoptAPet</div><small>Web-Based Pet Adoption System</small></div><div><b>ADOPTION CERTIFICATE</b><br><small>Application #${application.id}</small></div></div><h1 class="certificate-title">Certificate of Adoption</h1><p class="certificate-copy">This recognizes <b>${esc(application.applicant_name)}</b> as the approved adopter of <b>${esc(application.pet_name)}</b>.</p><p class="certificate-copy muted">Approved ${date(application.reviewed_at || application.last_updated_at)} · AdoptAPet Web-Based Pet Adoption System</p></article>`;
  } catch (error) {
    target.className = 'panel empty error-state';
    target.innerHTML = `<h1>Certificate unavailable</h1><p>${esc(error.message || 'The Adoption Certificate could not be loaded.')}</p><a class="btn" href="#/applications">Back to Applications</a>`;
  }
}

async function cancelApplication(id) {
  if (
    !(await confirmAction({
      title: 'Cancel this Adoption Application?',
      message: 'The pending application will be marked as cancelled.',
      confirmLabel: 'Cancel application',
      tone: 'danger',
    }))
  )
    return;
  try {
    let response = await fetch(
        new URL(
          `api/adoption-applications.php?id=${encodeURIComponent(id)}`,
          window.location.href,
        ),
        {
          method: 'PATCH',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'cancel' }),
        },
      ),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to cancel the application.');
    toast(result.message);
    await loadOwnApplicationDetail(id);
  } catch (error) {
    toast('Unable to cancel the application. Please try again later.');
  }
}

function adminApplications() {
  setTimeout(loadAdminApplications, 0);
  return shell(
    `<div class="admin-page-heading"><div class="page-title"><span class="eyebrow">Application workflow</span><h1>Adoption Applications</h1><p>Review submitted applications and keep adoption decisions moving.</p></div></div><section class="panel admin-pet-filters" aria-labelledby="admin-application-filter-title"><div class="admin-filter-heading"><div><h2 id="admin-application-filter-title">Find applications</h2><p class="muted">Search applicant or pet details, then narrow the review queue.</p></div><button id="admin-application-clear" class="btn outline sm" type="button" onclick="clearAdminApplicationFilters()" hidden>Clear Filters</button></div><div class="admin-application-filter-grid"><div class="field"><label for="admin-application-search">Applicant or pet</label><input id="admin-application-search" class="input" maxlength="255" placeholder="Search name, email, pet, or breed" oninput="filterAdminApplications()"></div><div class="field"><label for="admin-application-status">Application status</label><select id="admin-application-status" onchange="filterAdminApplications()"><option value="">All statuses</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="withdrawn">Withdrawn</option><option value="cancelled">Cancelled</option></select></div><div class="field"><label for="admin-application-sort">Submitted date</label><select id="admin-application-sort" onchange="filterAdminApplications()"><option value="newest">Newest first</option><option value="oldest">Oldest first</option></select></div></div></section><div class="admin-table-meta"><p id="admin-application-results" class="muted" aria-live="polite">Loading applications…</p></div><section class="panel admin-application-table-panel"><div class="table-wrap"><table class="table admin-application-table"><thead><tr><th>Applicant</th><th>Pet Listing</th><th>Submitted</th><th>Status</th><th>Review</th></tr></thead><tbody id="admin-applications-table" aria-busy="true">${skeletonRows(5, 5, 'Loading applications')}</tbody></table></div><div id="admin-applications-pagination"></div></section>`,
    true,
  );
}

async function loadAdminApplications() {
  let body = $('#admin-applications-table');
  if (!body) return;
  setSkeleton(body, skeletonRows(5, 5, 'Loading applications'));
  try {
    let response = await fetch(
        new URL('api/admin-adoption-applications.php', window.location.href),
        { credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    adminApplicationCache = result.applications;
    clearLoading(body);
    filterAdminApplications();
  } catch (error) {
    clearLoading(body);
    let count = $('#admin-application-results');
    if (count) count.textContent = 'Unable to load applications';
    body.innerHTML = `<tr><td colspan="5" class="empty">${esc(error.message || 'Unable to load applications.')}</td></tr>`;
  }
}

function adminApplicationTime(value) {
  let timestamp = new Date(String(value || '').replace(' ', 'T')).getTime();
  return Number.isFinite(timestamp) ? timestamp : 0;
}

function filterAdminApplications(resetPage = true) {
  let query = $('#admin-application-search')?.value.trim().toLowerCase() || '',
    status = $('#admin-application-status')?.value || '',
    sort = $('#admin-application-sort')?.value || 'newest';
  let applications = adminApplicationCache.filter((application) => {
    let searchable = [
      application.applicant_name,
      application.applicant_email,
      application.pet_name,
      application.pet_breed,
      application.pet_species,
    ]
      .join(' ')
      .toLowerCase();
    return (
      (!query || searchable.includes(query)) &&
      (!status || application.application_status === status)
    );
  });
  applications.sort((left, right) =>
    sort === 'oldest'
      ? adminApplicationTime(left.submitted_at) -
        adminApplicationTime(right.submitted_at)
      : adminApplicationTime(right.submitted_at) -
        adminApplicationTime(left.submitted_at),
  );
  if (resetPage) adminApplicationPage = 1;
  let page = paginatedItems(
    applications,
    adminApplicationPage,
    adminApplicationPageSize,
  );
  adminApplicationPage = page.page;
  let body = $('#admin-applications-table'),
    count = $('#admin-application-results'),
    clear = $('#admin-application-clear'),
    controls = $('#admin-applications-pagination');
  if (clear) clear.hidden = !(query || status || sort !== 'newest');
  if (count)
    count.textContent = page.total
      ? `Showing ${page.start + 1}–${page.end} of ${page.total} applications${query || status || sort !== 'newest' ? ` · ${adminApplicationCache.length} total` : ''}`
      : `0 applications${query || status || sort !== 'newest' ? ` · ${adminApplicationCache.length} total` : ''}`;
  if (!body) return;
  body.innerHTML = page.items.length
    ? page.items
        .map(
          (application) =>
            `<tr class="admin-application-record ${application.application_status === 'pending' ? 'application-pending-row' : ''}" role="button" tabindex="0" aria-label="${application.application_status === 'pending' ? 'Review' : 'View'} application ${application.id} for ${esc(application.pet_name)}" onclick="reviewApplication(${application.id})" onkeydown="activateAdminApplicationRow(event,${application.id})"><td><div class="admin-person-cell"><span class="admin-person-avatar" aria-hidden="true">${esc(
              String(application.applicant_name || '?')
                .trim()
                .charAt(0)
                .toUpperCase() || '?',
            )}</span><span><b>${esc(application.applicant_name)}</b><small>${esc(application.applicant_email)}</small></span></div></td><td><span class="admin-table-primary"><b>${esc(application.pet_name)}</b><small>${esc(application.pet_breed || application.pet_species)}</small></span></td><td><span class="admin-table-primary"><b>${date(application.submitted_at)}</b><small>Application #${application.id}</small></span></td><td>${applicationBadge(application.application_status)}</td><td><span class="admin-record-cue">${application.application_status === 'pending' ? 'Review application' : 'View details'} <span aria-hidden="true">→</span></span></td></tr>`,
        )
        .join('')
    : '<tr><td colspan="5" class="empty"><h2>No matching applications</h2><p>Try a different applicant, pet, status, or date order.</p></td></tr>';
  if (controls)
    controls.innerHTML = paginationMarkup(
      page.page,
      page.totalPages,
      'changeAdminApplicationPage',
      'Adoption Application management pages',
    );
}
function activateAdminApplicationRow(event, id) {
  if (event.key !== 'Enter' && event.key !== ' ') return;
  event.preventDefault();
  reviewApplication(id);
}
function changeAdminApplicationPage(page) {
  let query = $('#admin-application-search')?.value.trim().toLowerCase() || '',
    status = $('#admin-application-status')?.value || '',
    applications = adminApplicationCache.filter((application) => {
      let searchable = [
        application.applicant_name,
        application.applicant_email,
        application.pet_name,
        application.pet_breed,
        application.pet_species,
      ]
        .join(' ')
        .toLowerCase();
      return (
        (!query || searchable.includes(query)) &&
        (!status || application.application_status === status)
      );
    }),
    next = paginatedItems(applications, page, adminApplicationPageSize);
  if (next.page === adminApplicationPage) return;
  adminApplicationPage = next.page;
  filterAdminApplications(false);
  paginationScroll('#admin-application-results');
}

function clearAdminApplicationFilters() {
  let search = $('#admin-application-search'),
    status = $('#admin-application-status'),
    sort = $('#admin-application-sort');
  if (search) search.value = '';
  if (status) status.value = '';
  if (sort) sort.value = 'newest';
  filterAdminApplications();
}

function reviewApplication(id) {
  let application = adminApplicationCache.find(
    (item) => Number(item.id) === Number(id),
  );
  if (!application) return toast('Application not found.');
  beginAdminModal();
  $('#modal')?.remove();
  let pending = application.application_status === 'pending';
  document.body.insertAdjacentHTML(
    'beforeend',
    `<div id="modal" class="modal-overlay" role="presentation" onclick="if(event.target===this)closeModal()"><section class="panel modal-panel application-review-modal" data-admin-modal role="dialog" aria-modal="true" aria-labelledby="application-review-title"><header class="application-review-header"><div><span class="eyebrow">Application #${application.id}</span><h2 id="application-review-title">${pending ? 'Review Adoption Application' : 'Application Details'}</h2></div><div class="application-review-header-actions">${applicationBadge(application.application_status)}<button class="btn outline sm" data-admin-modal-close type="button" onclick="closeModal()" aria-label="Close application details">Close</button></div></header><div class="application-review-summary"><section><span class="application-review-label">Applicant</span><b>${esc(application.applicant_name)}</b><a href="mailto:${esc(application.applicant_email)}">${esc(application.applicant_email)}</a></section><section><span class="application-review-label">Pet Listing</span><b>${esc(application.pet_name)}</b><span>${esc(application.pet_breed || application.pet_species)}</span></section><section><span class="application-review-label">Submitted</span><b>${date(application.submitted_at)}</b><span>Current status: ${esc(applicationStatusLabel(application.application_status))}</span></section></div><section class="application-review-reason"><span class="application-review-label">Reason for adoption</span><p>${esc(application.reason)}</p></section>${pending ? `<div class="field application-review-notes"><label for="review-notes">Applicant-facing review note <span class="muted">(optional; visible to applicant)</span></label><textarea id="review-notes" rows="4" placeholder="Share useful decision context with the applicant; do not include private staff information"></textarea></div><footer class="application-review-footer"><button class="btn outline" type="button" onclick="closeModal()">Cancel</button><div>${application.pet_adoption_status === 'available' ? `<button class="btn" type="button" onclick="decideApplication(${application.id},'approved',this)">Approve Application</button>` : '<span class="notice warning">Pet is unavailable; approval is not possible.</span>'}<button class="btn danger" type="button" onclick="decideApplication(${application.id},'rejected',this)">Reject Application</button></div></footer>` : `<section class="application-review-notes-readonly"><span class="application-review-label">Applicant-facing review note</span><p>${application.admin_notes ? esc(application.admin_notes) : 'No administrator notes were recorded.'}</p></section><footer class="application-review-footer application-review-footer-view"><button class="btn" type="button" onclick="closeModal()">Done</button></footer>`}</section></div>`,
  );
  setTimeout(
    () =>
      pending
        ? $('#review-notes')?.focus()
        : $('.application-review-modal .btn.outline')?.focus(),
    0,
  );
}

async function decideApplication(id, status, button = null) {
  let notes = $('#review-notes')?.value.trim() || '';
  let approved = status === 'approved';
  if (
    !(await confirmAction({
      title: `${approved ? 'Approve' : 'Reject'} this application?`,
      message: approved
        ? 'This will approve the adoption application and update the pet status.'
        : 'This will reject the adoption application and notify the applicant.',
      confirmLabel: approved ? 'Approve' : 'Reject',
      tone: approved ? 'positive' : 'danger',
    }))
  )
    return;
  setButtonBusy(button, true, approved ? 'Approving…' : 'Rejecting…');
  try {
    let response = await fetch(
        new URL(
          `api/admin-adoption-applications.php?id=${encodeURIComponent(id)}`,
          window.location.href,
        ),
        {
          method: 'PATCH',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            application_status: status,
            admin_notes: notes,
          }),
        },
      ),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to review the application.');
    closeModal();
    toast(result.message);
    loadAdminApplications();
  } catch (error) {
    toast('Unable to review the application. Please try again later.');
  } finally {
    if (button?.isConnected) setButtonBusy(button, false);
  }
}

render();
