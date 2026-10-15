const strayAnimalLabel = (value) => petStatusLabel(value || 'other');
const STRAY_STATUS_LABELS = {
  submitted: 'Reported',
  under_review: 'Acknowledged',
  assigned: 'Rescue in Progress',
  resolved: 'Resolved',
  closed: 'Unable to Locate',
};
const STRAY_CONDITION_LABELS = {
  healthy: 'Healthy',
  injured: 'Injured',
  sick: 'Sick',
  trapped: 'Trapped',
  aggressive: 'Aggressive',
  mother_with_litter: 'Mother with litter',
  other: 'Other',
};
const strayStatusLabel = (value) =>
  STRAY_STATUS_LABELS[value] || petStatusLabel(value || 'submitted');
const strayUrgencyLabel = (value) =>
  ['high', 'critical'].includes(value) ? 'Urgent' : 'Normal';
const strayConditionLabel = (value) =>
  STRAY_CONDITION_LABELS[value] || 'Not specified';
const strayReference = (id) => `STR-${String(id).padStart(6, '0')}`;
const STRAY_MAP_DEFAULT = [3.139, 101.6869];
let strayPickerMap = null,
  strayPickerMarker = null,
  strayDetailMap = null,
  adminStrayDetailMap = null,
  strayReportSubmitting = false,
  strayReportFormStep = 1,
  adminStraySearchTimer = null,
  strayGeolocationPending = false,
  strayGeolocationDenied = false,
  adminStrayCurrentVersion = null;
let publicStrayReports = [],
  publicStrayPage = 1,
  adminStrayReports = [],
  adminStrayPage = 1;
let publicStrayAbortController = null,
  publicStrayRequestId = 0,
  publicStrayRequestedCriteria = '',
  publicStrayAppliedCriteria = '',
  publicStrayHasLoaded = false;
const publicStrayPageSize = 6,
  adminStrayPageSize = 10;

function strayReportPlaceholder(report) {
  let symbol =
    report.animal_type === 'dog'
      ? '🐕'
      : report.animal_type === 'cat'
        ? '🐈'
        : '🐾';
  return report.image_path
    ? `<img class="stray-card-thumb" src="${esc(report.image_path)}" alt="Reported ${esc(report.animal_type)}">`
    : `<div class="stray-card-thumb stray-image-placeholder" aria-hidden="true">${symbol}</div>`;
}

function publicStrayReportCard(report) {
  let description = String(report.description || ''),
    summary =
      description.length > 120 ? `${description.slice(0, 117)}…` : description,
    title = `${strayAnimalLabel(report.animal_type)} sighting`;
  return `<a class="stray-report-card" href="#/stray/${report.id}" aria-label="View ${esc(title)} at ${esc(report.location_text)}"><span class="stray-card-media">${strayReportPlaceholder(report)}</span><span class="stray-card-content"><span class="stray-card-heading"><strong>${esc(title)}</strong><span class="badge stray-urgency-${esc(report.urgency)}">${strayUrgencyLabel(report.urgency)}</span></span><span class="stray-card-reference">${esc(report.reference || strayReference(report.id))} · ${esc(report.location_text)}</span><time datetime="${esc(report.submitted_at || '')}">${date(report.submitted_at)}</time><span class="stray-card-summary">${esc(summary)}</span><span class="stray-card-footer"><span class="badge ${esc(report.report_status)}">${strayStatusLabel(report.report_status)}</span><span class="stray-card-cue" aria-hidden="true">View details →</span></span></span></a>`;
}

function strayReportsPage() {
  cancelLiveSearch('public-strays');
  publicStrayAbortController?.abort();
  publicStrayRequestedCriteria = '';
  publicStrayAppliedCriteria = '';
  publicStrayHasLoaded = false;
  setTimeout(loadStrayReports, 0);
  let action =
    state.role === 'Registered User'
      ? '<a class="btn stray-report-action" href="#/report-stray">+ Report a Stray</a>'
      : state.role === 'Guest'
        ? '<a class="btn stray-report-action" href="#/login">Log in to Report</a>'
        : '';
  return shell(
    `<div class="stray-catalogue-ux"><header class="stray-page-heading"><h1>Stray Reports</h1><p>Browse location-organized stray sightings reported by the AdoptAPet community.</p></header><section class="panel stray-discovery listing-search-ui" aria-label="Find Stray Reports"><form class="stray-search-row" onsubmit="runPublicStraySearch(event)"><div class="field"><label for="stray-search">Keyword</label><div class="field-with-icon">${uiIcon('search', 'input-icon')}<input id="stray-search" class="input" maxlength="100" placeholder="Breed, colour, condition, or description" oninput="schedulePublicStraySearch()"></div></div><div class="field"><label for="stray-location">Location</label><div class="field-with-icon">${uiIcon('pin', 'input-icon')}<input id="stray-location" class="input" maxlength="100" placeholder="Area, landmark, or city" oninput="schedulePublicStraySearch()"></div></div>${action}</form><button id="stray-filter-toggle" type="button" class="btn outline listing-filter-toggle stray-filter-toggle" aria-expanded="false" aria-controls="stray-advanced-filters" onclick="toggleStrayFilters()">Filters <span id="stray-filter-count" class="listing-filter-count stray-filter-count" hidden></span></button><div id="stray-advanced-filters" class="stray-advanced-filters" hidden><div class="stray-filter-grid"><div class="field"><label for="stray-animal">Species</label><select id="stray-animal" onchange="applyPublicStrayFilters()"><option value="">All species</option><option value="dog">Dog</option><option value="cat">Cat</option><option value="other">Other</option></select></div><div class="field"><label for="stray-urgency">Urgency</label><select id="stray-urgency" onchange="applyPublicStrayFilters()"><option value="">All urgency levels</option><option value="normal">Normal</option><option value="urgent">Urgent</option></select></div><div class="field"><label for="stray-status">Report status</label><select id="stray-status" onchange="applyPublicStrayFilters()"><option value="">All statuses</option><option value="submitted">Reported</option><option value="under_review">Acknowledged</option><option value="assigned">Rescue in Progress</option><option value="resolved">Resolved</option><option value="closed">Unable to Locate</option></select></div><button type="button" class="btn outline sm stray-filter-clear listing-clear-action" onclick="clearStrayFilters()">Clear filters</button></div></div></section><div class="stray-results-meta"><p id="stray-count" aria-live="polite">Loading Stray Reports…</p></div><div id="stray-results" class="stray-report-grid" aria-busy="true">${skeletonCards(6, 'Loading Stray Reports')}</div><div id="stray-pagination"></div></div>`,
  );
}

function renderStrayReports() {
  let target = $('#stray-results'),
    count = $('#stray-count'),
    controls = $('#stray-pagination');
  if (!target) return;
  let page = paginatedItems(
    publicStrayReports,
    publicStrayPage,
    publicStrayPageSize,
  );
  publicStrayPage = page.page;
  if (count)
    count.textContent = page.total
      ? `Showing ${page.start + 1}–${page.end} of ${page.total} Stray Reports`
      : '0 Stray Reports found';
  target.innerHTML = page.items.length
    ? page.items.map(publicStrayReportCard).join('')
    : '<div class="panel empty"><h2>No matching Stray Reports</h2><p>Try another location or clear the current filters.</p><button class="btn outline" onclick="clearStrayFilters()">Clear Filters</button></div>';
  if (controls)
    controls.innerHTML = paginationMarkup(
      page.page,
      page.totalPages,
      'changeStrayPage',
      'Stray Report pages',
    );
}
function changeStrayPage(page) {
  let next = paginatedItems(publicStrayReports, page, publicStrayPageSize);
  if (next.page === publicStrayPage) return;
  publicStrayPage = next.page;
  renderStrayReports();
  paginationScroll('#stray-count');
}

// Public stray reports and location filters
async function loadStrayReports() {
  let target = $('#stray-results'),
    count = $('#stray-count');
  if (!target) return;
  let fields = {
      q: $('#stray-search')?.value.trim() || '',
      location: $('#stray-location')?.value.trim() || '',
      animal_type: $('#stray-animal')?.value || '',
      urgency: $('#stray-urgency')?.value || '',
      status: $('#stray-status')?.value || '',
    },
    criteria = JSON.stringify(fields);
  if (
    criteria === publicStrayAppliedCriteria ||
    criteria === publicStrayRequestedCriteria
  )
    return;
  publicStrayRequestedCriteria = criteria;
  publicStrayAbortController?.abort();
  publicStrayAbortController = new AbortController();
  let requestId = ++publicStrayRequestId;
  if (publicStrayHasLoaded) target.setAttribute('aria-busy', 'true');
  else setSkeleton(target, skeletonCards(6, 'Loading Stray Reports'));
  try {
    let url = new URL('api/stray-reports.php', window.location.href);
    Object.entries(fields).forEach(([key, value]) => {
      if (value) url.searchParams.set(key, value);
    });
    let response = await fetch(url, {
        signal: publicStrayAbortController.signal,
      }),
      result = await response.json();
    if (requestId !== publicStrayRequestId) return;
    if (!response.ok || !result.success) throw new Error(result.message);
    publicStrayReports = result.reports || [];
    publicStrayPage = 1;
    publicStrayAppliedCriteria = criteria;
    publicStrayHasLoaded = true;
    clearLoading(target);
    renderStrayReports();
  } catch (error) {
    if (error.name === 'AbortError' || requestId !== publicStrayRequestId)
      return;
    clearLoading(target);
    if (!publicStrayHasLoaded) {
      if (count) count.textContent = 'Stray Reports could not be loaded.';
      target.innerHTML = `<div class="panel empty error-state"><h2>Unable to load Stray Reports</h2><p>${esc(error.message || 'Please try again later.')}</p><button class="btn" onclick="loadStrayReports()">Retry</button></div>`;
    } else if (count)
      count.textContent =
        'Stray Reports could not be updated. Please try again.';
  } finally {
    if (requestId === publicStrayRequestId) publicStrayRequestedCriteria = '';
  }
}

function schedulePublicStraySearch() {
  scheduleLiveSearch('public-strays', loadStrayReports);
}
function runPublicStraySearch(event) {
  runLiveSearchNow(event, 'public-strays', loadStrayReports);
}
function publicStrayActiveFilterCount() {
  return ['stray-animal', 'stray-urgency', 'stray-status'].filter((id) =>
    Boolean($(`#${id}`)?.value),
  ).length;
}
function updatePublicStrayFilterCount() {
  let count = publicStrayActiveFilterCount(),
    badge = $('#stray-filter-count');
  if (badge) {
    badge.textContent = count ? `(${count})` : '';
    badge.hidden = count === 0;
  }
}
function toggleStrayFilters(force) {
  let panel = $('#stray-advanced-filters'),
    button = $('#stray-filter-toggle');
  if (!panel || !button) return;
  let open = typeof force === 'boolean' ? force : panel.hidden;
  panel.hidden = !open;
  button.setAttribute('aria-expanded', String(open));
  button.classList.toggle('is-open', open);
}
function applyPublicStrayFilters() {
  updatePublicStrayFilterCount();
  runPublicStraySearch();
}
function clearStrayFilters() {
  cancelLiveSearch('public-strays');
  [
    'stray-search',
    'stray-location',
    'stray-animal',
    'stray-urgency',
    'stray-status',
  ].forEach((id) => {
    let field = $(`#${id}`);
    if (field) field.value = '';
  });
  updatePublicStrayFilterCount();
  loadStrayReports();
}

function strayReportDetail(id) {
  setTimeout(() => loadStrayReportDetail(id), 0);
  return shell(
    '<div id="stray-detail" class="panel empty loading-state">Loading Stray Report…</div>',
  );
}

function selectStrayGalleryImage(button) {
  let main = $('#stray-main-image');
  if (!main || !button) return;
  main.src = button.dataset.image || main.src;
  main.alt = button.dataset.alt || main.alt;
  document.querySelectorAll('.stray-gallery-thumbnail').forEach((item) => {
    let active = item === button;
    item.classList.toggle('active', active);
    item.setAttribute('aria-current', active ? 'true' : 'false');
  });
}
function strayDetailGallery(report, images) {
  if (!images.length)
    return `<div class="stray-detail-placeholder" aria-hidden="true">${report.animal_type === 'dog' ? '🐕' : report.animal_type === 'cat' ? '🐈' : '🐾'}</div>`;
  let first = images[0],
    alt = first.original_filename || 'Stray sighting image',
    thumbnails =
      images.length > 1
        ? `<div class="stray-gallery-thumbnails" aria-label="More images from this stray sighting">${images.map((image, index) => `<button type="button" class="stray-gallery-thumbnail ${index === 0 ? 'active' : ''}" aria-label="Show sighting image ${index + 1}" aria-current="${index === 0 ? 'true' : 'false'}" data-image="${esc(image.file_path)}" data-alt="${esc(image.original_filename || `Stray sighting image ${index + 1}`)}" onclick="selectStrayGalleryImage(this)"><img src="${esc(image.file_path)}" alt=""></button>`).join('')}</div>`
        : '';
  return `<div class="stray-gallery"><img id="stray-main-image" class="stray-detail-main-image" src="${esc(first.file_path)}" alt="${esc(alt)}">${thumbnails}</div>`;
}

function strayDetailIcon(name) {
  let paths = {
    palette:
      '<path d="M12 3a9 9 0 1 0 0 18h1.5a2 2 0 0 0 1.5-3.3 1.7 1.7 0 0 1 1.3-2.7H18a3 3 0 0 0 3-3A9 9 0 0 0 12 3Z"/><circle cx="7" cy="11" r=".7"/><circle cx="10" cy="7" r=".7"/><circle cx="15" cy="7" r=".7"/><circle cx="18" cy="11" r=".7"/>',
    size: '<path d="M4 20 20 4M4 14v6h6M14 4h6v6"/>',
    female: '<circle cx="12" cy="9" r="6"/><path d="M12 15v7M9 19h6"/>',
    male: '<circle cx="10" cy="14" r="6"/><path d="m14.5 9.5 6-6M16 3.5h4.5V8"/>',
  };
  return paths[name]
    ? `<svg class="ui-icon stray-detail-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${paths[name]}</svg>`
    : uiIcon(name, 'stray-detail-icon');
}
function strayDetailKnown(value) {
  let text = String(value ?? '').trim();
  return (
    text !== '' &&
    !['unknown', 'not recorded', 'not specified', 'n/a', 'none'].includes(
      text.toLowerCase(),
    )
  );
}
function strayDetailFact(label, value, icon) {
  return `<div class="stray-detail-fact">${strayDetailIcon(icon)}<dt>${esc(label)}</dt><dd>${esc(value)}</dd></div>`;
}
function strayDetailObservations(description, notes) {
  let extra = String(notes ?? '').trim(),
    main = String(description ?? '').trim(),
    normalize = (value) => value.replace(/\s+/g, ' ').toLowerCase();
  return extra && !normalize(main).includes(normalize(extra))
    ? `<p class="stray-detail-observations">${esc(extra)}</p>`
    : '';
}

async function loadStrayReportDetail(id) {
  let target = $('#stray-detail');
  if (!target) return;
  try {
    let response = await fetch(
        new URL(
          `api/stray-reports.php?id=${encodeURIComponent(id)}`,
          window.location.href,
        ),
      ),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    let report = result.report,
      images = report.images || [],
      latitude = Number(report.latitude),
      longitude = Number(report.longitude),
      hasCoordinates =
        report.latitude !== null &&
        report.longitude !== null &&
        validStrayCoordinates(latitude, longitude);
    let gallery = images.length
      ? strayDetailGallery(report, images)
      : `<div class="stray-detail-placeholder" role="img" aria-label="No sighting image available">${strayDetailIcon('image')}<span>No sighting image available</span></div>`;
    let primaryFacts = strayDetailFact(
      'Animal type',
      strayAnimalLabel(report.animal_type),
      'paw',
    );
    if (strayDetailKnown(report.location_text))
      primaryFacts += strayDetailFact('Location', report.location_text, 'pin');
    if (strayDetailKnown(report.condition_category))
      primaryFacts += strayDetailFact(
        'Condition',
        strayConditionLabel(report.condition_category),
        'heart',
      );
    let optionalFacts = [
      ['Breed', report.breed, 'paw'],
      ['Colour', report.colour, 'palette'],
      [
        'Size',
        strayDetailKnown(report.size) ? petStatusLabel(report.size) : '',
        'size',
      ],
      [
        'Sex',
        strayDetailKnown(report.sex) ? petStatusLabel(report.sex) : '',
        report.sex === 'female'
          ? 'female'
          : report.sex === 'male'
            ? 'male'
            : 'user',
      ],
    ]
      .filter(([, value]) => strayDetailKnown(value))
      .map(([label, value, icon]) => strayDetailFact(label, value, icon))
      .join('');
    let updated =
      report.updated_at && date(report.updated_at) !== date(report.submitted_at)
        ? ` · Updated ${date(report.updated_at)}`
        : '';
    let urgencyBadge = ['low', 'medium', 'high', 'critical'].includes(
      report.urgency,
    )
      ? `<span class="badge stray-urgency-${esc(report.urgency)}">${strayUrgencyLabel(report.urgency)}</span>`
      : '';
    target.className = 'stray-ux-detail';
    target.innerHTML = `<nav class="stray-detail-breadcrumb" aria-label="Breadcrumb"><a href="#/strays">← Back to Stray Reports</a></nav>
      <div class="stray-detail-hero"><div class="stray-detail-gallery-area">${gallery}</div>
        <section class="stray-detail-summary" aria-label="Stray Report information">
          <div class="stray-detail-badges"><span class="badge ${esc(report.report_status)}">${strayStatusLabel(report.report_status)}</span>${urgencyBadge}</div>
          <span class="stray-detail-reference">${esc(report.reference || strayReference(report.id))}</span>
          <h1>${esc(strayAnimalLabel(report.animal_type))} sighting</h1>
          <p class="stray-detail-dates">Submitted ${date(report.submitted_at)}${updated}</p>
          <div class="stray-detail-facts ${optionalFacts ? 'has-optionals' : ''}"><dl class="stray-detail-fact-group">${primaryFacts}</dl>${optionalFacts ? `<dl class="stray-detail-fact-group">${optionalFacts}</dl>` : ''}</div>
        </section></div>
      <div class="stray-detail-content">
        <section class="stray-detail-section" aria-labelledby="stray-description-heading"><h2 id="stray-description-heading">${strayDetailIcon('file')}Description</h2><p class="stray-detail-description">${esc(report.description)}</p>${strayDetailObservations(report.description, report.condition_notes)}</section>
        ${state.role === 'Registered User' && report.admin_notes?.trim() ? `<section class="stray-detail-section" aria-labelledby="stray-update-heading"><h2 id="stray-update-heading">Rescue update</h2><p>${esc(report.admin_notes)}</p></section>` : ''}
        <section class="stray-detail-section" aria-labelledby="stray-location-heading"><h2 id="stray-location-heading">${strayDetailIcon('pin')}Reported location</h2><p>${esc(report.location_text || 'Location not provided')}</p>${hasCoordinates ? '<div id="stray-detail-map" class="stray-map" role="img" aria-label="Map showing the reported stray sighting location"></div>' : ''}</section>
      </div>
      <div class="form-actions stray-detail-actions"><a class="btn outline" href="#/strays">Back to Stray Reports</a>${state.role === 'Registered User' ? '<a class="btn" href="#/report-stray">Report Another Stray</a>' : ''}</div>`;
    if (hasCoordinates)
      setTimeout(() => initStrayDetailMap(latitude, longitude), 0);
  } catch (error) {
    target.className = 'panel empty error-state';
    target.innerHTML = `<h1>Stray Report unavailable</h1><p>${esc(error.message || 'This report could not be found.')}</p><div class="form-actions"><a class="btn" href="#/strays">Browse Stray Reports</a><button class="btn outline" onclick="location.reload()">Retry</button></div>`;
  }
}

function strayReportFormPage() {
  clearEditedImageBatch('stray-images');
  strayReportSubmitting = false;
  strayReportFormStep = 1;
  strayGeolocationPending = false;
  strayGeolocationDenied = false;
  setTimeout(ensureStrayFinalCancel, 0);
  return shell(
    `<div class="stray-form-ux"><header class="stray-page-heading"><h1>Report a Stray Sighting</h1><p>Share clear animal and location details so rescuers can assess the sighting.</p></header><nav class="stray-form-progress" aria-label="Stray Report progress"><ol><li class="is-current" data-stray-progress="1" aria-current="step"><span>1</span><b>Animal details</b></li><li data-stray-progress="2"><span>2</span><b>Location</b></li><li data-stray-progress="3"><span>3</span><b>Sighting information</b></li></ol></nav><form class="panel form-shell stray-report-form" onsubmit="submitStrayReport(event)" novalidate><div class="form-shell-content"><section class="form-step" data-stray-step="1"><header class="form-section-header"><span class="eyebrow">Step 1 of 3</span><h2>Animal details</h2><p>Provide your best estimate if you are unsure.</p></header><fieldset class="form-section stray-form-section"><legend>Basic information</legend><div class="form-grid"><div class="field"><label for="report-animal">Species <span class="required">*</span></label><select id="report-animal" required><option value="">Select species</option><option value="dog">Dog</option><option value="cat">Cat</option><option value="other">Other</option></select></div><div class="field"><label for="report-breed">Breed <span class="muted">(optional)</span></label><input id="report-breed" class="input" maxlength="100" placeholder="Unknown or breed if known"></div><div class="field"><label for="report-colour">Colour <span class="required">*</span></label><input id="report-colour" class="input" maxlength="100" required placeholder="e.g. Brown and white"></div><div class="field"><label for="report-size">Size <span class="required">*</span></label><select id="report-size" required><option value="">Select size</option><option value="small">Small</option><option value="medium">Medium</option><option value="large">Large</option><option value="unknown">Unknown</option></select></div><div class="field"><label for="report-sex">Sex <span class="required">*</span></label><select id="report-sex" required><option value="male">Male</option><option value="female">Female</option><option value="unknown" selected>Unknown</option></select></div><div class="field"><label for="report-condition-category">Condition <span class="required">*</span></label><select id="report-condition-category" required><option value="">Select condition</option><option value="healthy">Healthy</option><option value="injured">Injured</option><option value="sick">Sick</option><option value="trapped">Trapped</option><option value="aggressive">Aggressive</option><option value="mother_with_litter">Mother with litter</option><option value="other">Other</option></select></div><div class="field full"><label for="report-urgency">Urgency <span class="required">*</span></label><select id="report-urgency" required><option value="medium">Normal</option><option value="high">Urgent</option></select></div></div></fieldset><div class="stray-step-actions"><a class="btn outline" href="#/strays">Cancel</a><button class="btn" type="button" onclick="nextStrayReportStep()">Continue to Location →</button></div></section><section class="form-step" data-stray-step="2" hidden><header class="form-section-header"><span class="eyebrow">Step 2 of 3</span><h2>Location</h2><p>Add an address or landmark; the map point remains optional.</p></header><fieldset class="form-section stray-form-section"><legend>Location selection</legend><div class="form-grid"><div class="field full"><label for="report-location">Address or landmark <span class="required">*</span></label><div class="field-with-icon">${uiIcon('pin', 'input-icon')}<input id="report-location" class="input" maxlength="255" required placeholder="Street, neighbourhood, landmark, city"></div><small class="helper">A text location is always accepted, even if location access or the map is unavailable.</small></div><div class="field full stray-map-picker"><div class="stray-map-heading"><div><label>Map location <span class="muted">(optional)</span></label><p class="helper">Click the map to place the pin, drag it to adjust the point, or use your current location.</p></div><button id="use-current-location" type="button" class="btn outline sm" onclick="useCurrentStrayLocation()">Use My Current Location</button></div><div id="stray-picker-map" class="stray-map" role="application" aria-label="Choose an optional stray sighting location on the map"></div><p id="stray-map-status" class="helper" role="status" aria-live="polite">No map point selected. The text location can still be submitted.</p><input id="report-latitude" type="hidden"><input id="report-longitude" type="hidden"></div></div></fieldset><div class="stray-step-actions"><button class="btn outline" type="button" onclick="previousStrayReportStep()">← Back</button><button class="btn" type="button" onclick="nextStrayReportStep()">Continue →</button></div></section><section class="form-step" data-stray-step="3" hidden><header class="form-section-header"><span class="eyebrow">Step 3 of 3</span><h2>Sighting information</h2><p>Add useful observations and up to three clear images.</p></header><fieldset class="form-section stray-form-section"><legend>Description and images</legend><div class="form-grid"><div class="field full stray-counted-field"><label for="report-description">Description <span class="required">*</span></label><textarea id="report-description" maxlength="5000" minlength="10" required rows="5" placeholder="Describe the animal, behaviour, surroundings, and when it was seen." oninput="updateStrayCharacterCount('report-description','report-description-count')"></textarea><small id="report-description-count" class="stray-character-count">0 / 5000</small></div><div class="field full stray-counted-field"><label for="report-condition">Additional observations <span class="muted">(optional)</span></label><textarea id="report-condition" maxlength="3000" rows="3" placeholder="Add injuries, behaviour, nearby risks, or other useful details." oninput="updateStrayCharacterCount('report-condition','report-condition-count')"></textarea><small id="report-condition-count" class="stray-character-count">0 / 3000</small></div><div class="field full">${imageBatchUploadControl('stray-images', 'stray-image-queue', 'Stray sighting images', 3)}</div></div></fieldset><aside class="stray-safety-reminder" aria-label="Safety reminder">${uiIcon('alert')}<span><b>Safety reminder</b><br>Do not approach an aggressive or injured animal. Contact local emergency or rescue services when immediate danger is present.</span></aside><div id="stray-form-validation" class="validation" role="alert" aria-live="polite"></div><div class="stray-step-actions"><button class="btn outline" type="button" onclick="previousStrayReportStep()">← Back</button><button id="stray-submit" class="btn" type="submit">Submit Stray Report</button></div></section></div></form></div>`,
  );
}

function strayStepFieldIds(step) {
  return step === 1
    ? [
        'report-animal',
        'report-colour',
        'report-size',
        'report-sex',
        'report-condition-category',
        'report-urgency',
      ]
    : step === 2
      ? ['report-location']
      : ['report-description'];
}
function validateStrayReportStep(step) {
  let validation = $('#stray-form-validation');
  if (validation) validation.textContent = '';
  for (let id of strayStepFieldIds(step)) {
    let field = $(`#${id}`);
    if (!field) continue;
    if (
      !field.checkValidity() ||
      (id === 'report-description' && field.value.trim().length < 10)
    ) {
      let message =
        step === 1
          ? 'Complete the required animal details.'
          : step === 2
            ? 'Enter an address or landmark before continuing.'
            : 'Add a description of at least 10 characters.';
      if (validation) validation.textContent = message;
      field.focus();
      field.reportValidity?.();
      return false;
    }
  }
  return true;
}
function setStrayReportStep(step) {
  let next = Math.max(1, Math.min(3, Number(step) || 1));
  strayReportFormStep = next;
  document.querySelectorAll('[data-stray-step]').forEach((section) => {
    section.hidden = Number(section.dataset.strayStep) !== next;
  });
  document.querySelectorAll('[data-stray-progress]').forEach((item) => {
    let itemStep = Number(item.dataset.strayProgress);
    item.classList.toggle('is-current', itemStep === next);
    item.classList.toggle('is-complete', itemStep < next);
    if (itemStep === next) item.setAttribute('aria-current', 'step');
    else item.removeAttribute('aria-current');
  });
  if (next === 2) setTimeout(initStrayPickerMap, 0);
  let form = $('.stray-report-form');
  form?.focus?.({ preventScroll: true });
}
function nextStrayReportStep() {
  if (!validateStrayReportStep(strayReportFormStep)) return;
  setStrayReportStep(strayReportFormStep + 1);
}
function previousStrayReportStep() {
  setStrayReportStep(strayReportFormStep - 1);
}
function ensureStrayFinalCancel() {
  let submit = $('#stray-submit'),
    actions = submit?.closest('.stray-step-actions');
  if (!submit || !actions || actions.querySelector('.stray-final-cancel'))
    return;
  let cancel = document.createElement('a');
  cancel.className = 'btn outline stray-final-cancel';
  cancel.href = '#/strays';
  cancel.textContent = 'Cancel';
  actions.insertBefore(cancel, submit);
}
function updateStrayCharacterCount(inputId, countId) {
  let input = $(`#${inputId}`),
    count = $(`#${countId}`);
  if (input && count)
    count.textContent = `${input.value.length} / ${input.maxLength}`;
  let description = $('#report-description'),
    validation = $('#stray-form-validation');
  if (validation && description?.value.trim().length >= 10)
    validation.textContent = '';
}

function strayMapTileLayer() {
  return L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors',
  });
}
function validStrayCoordinates(latitude, longitude) {
  return (
    Number.isFinite(latitude) &&
    Number.isFinite(longitude) &&
    latitude >= -90 &&
    latitude <= 90 &&
    longitude >= -180 &&
    longitude <= 180
  );
}
function setStrayMapStatus(message, error = false) {
  let target = $('#stray-map-status');
  if (target) {
    target.textContent = message;
    target.classList.toggle('validation', error);
  }
}
function strayGeolocationUnavailableMessage() {
  if (!window.isSecureContext)
    return 'Current Location requires HTTPS or the localhost compatibility URL. You can still type a location or click the map.';
  if (!navigator.geolocation)
    return 'This browser does not support location access. You can still type a location or click the map.';
  return '';
}
function configureStrayGeolocation() {
  let button = $('#use-current-location'),
    message = strayGeolocationUnavailableMessage();
  if (button && message) {
    button.disabled = true;
    button.title = 'Current Location is unavailable on this address.';
  }
  if (message) setStrayMapStatus(message, true);
  return !message;
}
function setStrayCoordinates(latitude, longitude, center = true) {
  if (!validStrayCoordinates(latitude, longitude)) return;
  let latitudeInput = $('#report-latitude'),
    longitudeInput = $('#report-longitude');
  if (latitudeInput) latitudeInput.value = latitude.toFixed(7);
  if (longitudeInput) longitudeInput.value = longitude.toFixed(7);
  if (strayPickerMap) {
    if (!strayPickerMarker) {
      strayPickerMarker = L.marker([latitude, longitude], {
        draggable: true,
      }).addTo(strayPickerMap);
      strayPickerMarker.on('dragend', (event) => {
        let point = event.target.getLatLng();
        setStrayCoordinates(point.lat, point.lng, false);
      });
    } else strayPickerMarker.setLatLng([latitude, longitude]);
    if (center) strayPickerMap.setView([latitude, longitude], 16);
  }
  setStrayMapStatus(
    `Selected coordinates: ${latitude.toFixed(7)}, ${longitude.toFixed(7)}`,
  );
}
function syncStrayMarkerFromInputs() {
  let latitude = Number($('#report-latitude')?.value),
    longitude = Number($('#report-longitude')?.value);
  if (
    $('#report-latitude')?.value !== '' &&
    $('#report-longitude')?.value !== '' &&
    validStrayCoordinates(latitude, longitude)
  )
    setStrayCoordinates(latitude, longitude, true);
}
function initStrayPickerMap() {
  let container = $('#stray-picker-map');
  if (!container) return;
  configureStrayGeolocation();
  if (typeof L === 'undefined') {
    container.innerHTML =
      '<div class="map-fallback">The optional map could not load. You can still submit the report using the Location field.</div>';
    setStrayMapStatus(
      'Map unavailable; the Location text field remains available.',
      true,
    );
    return;
  }
  if (strayPickerMap) {
    try {
      strayPickerMap.remove();
    } catch (error) {}
  }
  strayPickerMarker = null;
  strayPickerMap = L.map(container).setView(STRAY_MAP_DEFAULT, 11);
  strayMapTileLayer().addTo(strayPickerMap);
  strayPickerMap.on('click', (event) =>
    setStrayCoordinates(event.latlng.lat, event.latlng.lng, false),
  );
  syncStrayMarkerFromInputs();
  setTimeout(() => strayPickerMap?.invalidateSize(), 0);
}
function useCurrentStrayLocation() {
  let button = $('#use-current-location'),
    unavailable = strayGeolocationUnavailableMessage();
  if (unavailable) {
    if (button) button.disabled = true;
    setStrayMapStatus(unavailable, true);
    return;
  }
  if (strayGeolocationDenied) {
    setStrayMapStatus(
      'Location permission is denied for this site. You can still type a location or click the map.',
      true,
    );
    return;
  }
  if (strayGeolocationPending) return;
  strayGeolocationPending = true;
  setButtonBusy(button, true, 'Locating…');
  setStrayMapStatus('Requesting your current location…');
  navigator.geolocation.getCurrentPosition(
    (position) => {
      strayGeolocationPending = false;
      let { latitude, longitude } = position.coords;
      if (validStrayCoordinates(latitude, longitude))
        setStrayCoordinates(latitude, longitude, true);
      else
        setStrayMapStatus(
          'The browser returned an invalid location. You can still use the form normally.',
          true,
        );
      setButtonBusy(button, false);
    },
    (error) => {
      strayGeolocationPending = false;
      strayGeolocationDenied = error.code === 1;
      let reason =
        error.code === 1
          ? 'Location permission was denied for this site.'
          : error.code === 2
            ? 'Your current location is unavailable.'
            : 'The location request timed out.';
      setStrayMapStatus(
        `${reason} You can still type a location or click the map.`,
        true,
      );
      setButtonBusy(button, false);
      if (strayGeolocationDenied && button) {
        button.disabled = true;
        button.title = 'Location permission is denied for this site.';
      }
    },
    { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 },
  );
}
function initStrayDetailMap(latitude, longitude) {
  let container = $('#stray-detail-map');
  if (!container) return;
  if (typeof L === 'undefined') {
    container.innerHTML =
      '<p class="map-fallback">Map unavailable; the reported text location is shown above.</p>';
    return;
  }
  if (strayDetailMap) {
    try {
      strayDetailMap.remove();
    } catch (error) {}
  }
  strayDetailMap = L.map(container, { scrollWheelZoom: false }).setView(
    [latitude, longitude],
    15,
  );
  strayMapTileLayer().addTo(strayDetailMap);
  L.marker([latitude, longitude])
    .addTo(strayDetailMap)
    .bindPopup('Reported sighting location');
  setTimeout(() => strayDetailMap?.invalidateSize(), 0);
}

async function submitStrayReport(event) {
  event.preventDefault();
  if (strayReportFormStep < 3) {
    nextStrayReportStep();
    return;
  }
  if (strayReportSubmitting) return;
  if (!validateStrayReportStep(3)) return;
  let payload = {
      animal_type: $('#report-animal').value,
      breed: $('#report-breed').value.trim(),
      colour: $('#report-colour').value.trim(),
      size: $('#report-size').value,
      sex: $('#report-sex').value,
      condition_category: $('#report-condition-category').value,
      urgency: $('#report-urgency').value,
      location_text: $('#report-location').value.trim(),
      description: $('#report-description').value.trim(),
      condition_notes: $('#report-condition').value.trim(),
      latitude: $('#report-latitude').value.trim(),
      longitude: $('#report-longitude').value.trim(),
    },
    validation = $('#stray-form-validation');
  if (validation) validation.textContent = '';
  if (
    !payload.animal_type ||
    !payload.colour ||
    !payload.size ||
    !payload.sex ||
    !payload.condition_category ||
    !payload.location_text ||
    payload.description.length < 10
  ) {
    let message = 'Complete the required Stray Report fields.';
    if (validation) validation.textContent = message;
    return toast(message, 'error');
  }
  let button = $('#stray-submit');
  strayReportSubmitting = true;
  setButtonBusy(button, true, 'Submitting…');
  try {
    let form = new FormData();
    form.append('payload', JSON.stringify(payload));
    appendEditedImageBatch(form, 'stray-images');
    let response = await fetch(
        new URL('api/stray-reports.php', window.location.href),
        { method: 'POST', credentials: 'same-origin', body: form },
      ),
      result = await response.json();
    if (!response.ok || !result.success) {
      let message = result.message || 'Unable to submit the Stray Report.';
      if (validation) validation.textContent = message;
      return toast(message, 'error');
    }
    clearEditedImageBatch('stray-images');
    toast(result.message);
    go(`/stray/${result.report.id}`);
  } catch (error) {
    let message = 'Unable to submit the Stray Report. Please try again later.';
    if (validation) validation.textContent = message;
    toast(message, 'error');
  } finally {
    strayReportSubmitting = false;
    setButtonBusy(button, false);
  }
}

function databaseAdminStrayReportsPage() {
  setTimeout(loadAdminStrayReports, 0);
  return shell(
    `<div class="admin-page-heading"><div class="page-title"><span class="eyebrow">Rescue monitoring</span><h1>Stray Report Management</h1><p>Review reported sightings, locations, and rescue progress.</p></div></div><section class="panel admin-pet-filters" aria-labelledby="admin-stray-filter-title"><div class="admin-filter-heading"><div><h2 id="admin-stray-filter-title">Find Stray Reports</h2><p class="muted">Search by reference, reporter, location, or description.</p></div><button id="admin-stray-clear" class="btn outline sm" type="button" onclick="clearAdminStrayFilters()" hidden>Clear Filters</button></div><div class="admin-stray-filter-grid"><div class="field"><label for="admin-stray-search">Search</label><input id="admin-stray-search" class="input" maxlength="100" placeholder="STR-000123, location, reporter…" oninput="scheduleAdminStraySearch()"></div><div class="field"><label for="admin-stray-status">Status</label><select id="admin-stray-status" onchange="loadAdminStrayReports()"><option value="">All statuses</option><option value="submitted">Reported</option><option value="under_review">Acknowledged</option><option value="assigned">Rescue in Progress</option><option value="resolved">Resolved</option><option value="closed">Unable to Locate</option></select></div><div class="field"><label for="admin-stray-urgent">Urgency</label><select id="admin-stray-urgent" onchange="loadAdminStrayReports()"><option value="">All reports</option><option value="1">Urgent only</option></select></div></div></section><div class="admin-table-meta"><p id="admin-stray-results-count" class="muted" aria-live="polite">Loading Stray Reports…</p></div><div id="admin-stray-results" class="admin-stray-list" aria-busy="true">${skeletonList(4, 'Loading Stray Reports')}</div><div id="admin-stray-pagination"></div>`,
    true,
  );
}

function scheduleAdminStraySearch() {
  clearTimeout(adminStraySearchTimer);
  adminStraySearchTimer = setTimeout(loadAdminStrayReports, 250);
}
function clearAdminStrayFilters() {
  clearTimeout(adminStraySearchTimer);
  ['admin-stray-search', 'admin-stray-status', 'admin-stray-urgent'].forEach(
    (id) => {
      let field = $(`#${id}`);
      if (field) field.value = '';
    },
  );
  loadAdminStrayReports();
}

function adminStrayCard(report) {
  let image = report.image_path
    ? `<img src="${esc(report.image_path)}" alt="Reported ${esc(report.animal_type)}">`
    : `<span class="admin-stray-placeholder" aria-hidden="true">${report.animal_type === 'dog' ? '🐕' : report.animal_type === 'cat' ? '🐈' : '🐾'}</span>`;
  return `<article class="panel admin-stray-card admin-stray-record" role="button" tabindex="0" aria-label="View and update ${esc(report.reference || strayReference(report.id))}" onclick="openAdminStrayReport(${report.id})" onkeydown="activateAdminStrayRecord(event,${report.id})">${image}<div class="admin-stray-card-main"><div class="admin-stray-card-heading"><div><span class="eyebrow">${esc(report.reference || strayReference(report.id))}</span><h2>${strayAnimalLabel(report.animal_type)} sighting</h2></div><div><span class="badge ${esc(report.report_status)}">${strayStatusLabel(report.report_status)}</span><span class="badge stray-urgency-${esc(report.urgency)}">${strayUrgencyLabel(report.urgency)}</span></div></div><p class="admin-stray-location"><b>${esc(report.location_text)}</b></p><p class="muted">Reported by ${esc(report.reporter_name)} · Submitted ${date(report.submitted_at)} · Updated ${date(report.updated_at)}</p><div class="admin-stray-card-actions"><span>${esc(report.colour || 'Colour not recorded')} · ${petStatusLabel(report.size || 'unknown')} · ${strayConditionLabel(report.condition_category)}</span><span class="admin-record-cue">View and update <span aria-hidden="true">→</span></span></div></div></article>`;
}
function activateAdminStrayRecord(event, id) {
  if (event.key !== 'Enter' && event.key !== ' ') return;
  event.preventDefault();
  openAdminStrayReport(id);
}

async function loadAdminStrayReports() {
  let target = $('#admin-stray-results'),
    controls = $('#admin-stray-pagination');
  if (!target) return;
  let query = $('#admin-stray-search')?.value.trim() || '',
    status = $('#admin-stray-status')?.value || '',
    urgent = $('#admin-stray-urgent')?.value || '';
  setSkeleton(target, skeletonList(4, 'Loading Stray Reports'));
  if (controls) controls.innerHTML = '';
  adminStrayPage = 1;
  try {
    let url = new URL('api/admin-stray-reports.php', window.location.href);
    if (query) url.searchParams.set('q', query);
    if (status) url.searchParams.set('status', status);
    if (urgent) url.searchParams.set('urgent', urgent);
    let response = await fetch(url, { credentials: 'same-origin' }),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    adminStrayReports = result.reports || [];
    clearLoading(target);
    renderAdminStrayReports();
    let clear = $('#admin-stray-clear');
    if (clear) clear.hidden = !(query || status || urgent);
  } catch (error) {
    clearLoading(target);
    let count = $('#admin-stray-results-count');
    if (count) count.textContent = 'Unable to load Stray Reports';
    target.innerHTML = `<div class="panel empty error-state"><h2>Stray Report management unavailable</h2><p>${esc(error.message || 'Please try again later.')}</p><button class="btn" onclick="loadAdminStrayReports()">Retry</button></div>`;
  }
}

// Administrator rescue workflow
function renderAdminStrayReports() {
  let target = $('#admin-stray-results'),
    count = $('#admin-stray-results-count'),
    controls = $('#admin-stray-pagination');
  if (!target) return;
  let page = paginatedItems(
    adminStrayReports,
    adminStrayPage,
    adminStrayPageSize,
  );
  adminStrayPage = page.page;
  if (count)
    count.textContent = page.total
      ? `Showing ${page.start + 1}–${page.end} of ${page.total} matching Stray Reports`
      : '0 matching Stray Reports';
  target.innerHTML = page.items.length
    ? page.items.map(adminStrayCard).join('')
    : '<div class="panel empty"><h2>No matching Stray Reports</h2><p>Try another reference, status, or urgency filter.</p><button class="btn outline" onclick="clearAdminStrayFilters()">Clear Filters</button></div>';
  if (controls)
    controls.innerHTML = paginationMarkup(
      page.page,
      page.totalPages,
      'changeAdminStrayPage',
      'Stray Report management pages',
    );
}
function changeAdminStrayPage(page) {
  let next = paginatedItems(adminStrayReports, page, adminStrayPageSize);
  if (next.page === adminStrayPage) return;
  adminStrayPage = next.page;
  renderAdminStrayReports();
  paginationScroll('#admin-stray-results-count');
}

function closeAdminStrayReport() {
  adminStrayCurrentVersion = null;
  if (adminStrayDetailMap) {
    try {
      adminStrayDetailMap.remove();
    } catch (error) {}
    adminStrayDetailMap = null;
  }
  $('#admin-stray-modal')?.remove();
  finishAdminModal();
}
function initAdminStrayDetailMap(latitude, longitude) {
  let container = $('#admin-stray-detail-map');
  if (!container || typeof L === 'undefined') return;
  if (adminStrayDetailMap) {
    try {
      adminStrayDetailMap.remove();
    } catch (error) {}
  }
  adminStrayDetailMap = L.map(container, { scrollWheelZoom: false }).setView(
    [latitude, longitude],
    16,
  );
  strayMapTileLayer().addTo(adminStrayDetailMap);
  L.marker([latitude, longitude])
    .addTo(adminStrayDetailMap)
    .bindPopup('Reported sighting location')
    .openPopup();
  setTimeout(() => adminStrayDetailMap?.invalidateSize(), 0);
}

async function openAdminStrayReport(id) {
  if ($('#admin-stray-modal')) closeAdminStrayReport();
  beginAdminModal();
  document.body.insertAdjacentHTML(
    'beforeend',
    '<div id="admin-stray-modal" class="modal-overlay" role="presentation" onclick="if(event.target===this)closeAdminStrayReport()"><section class="panel modal-panel admin-stray-modal" data-admin-modal role="dialog" aria-modal="true" aria-labelledby="admin-stray-modal-title"><div class="empty loading-state">Loading Stray Report…</div></section></div>',
  );
  try {
    let url = new URL('api/admin-stray-reports.php', window.location.href);
    url.searchParams.set('id', id);
    let response = await fetch(url, { credentials: 'same-origin' }),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    let report = result.report,
      modal = $('.admin-stray-modal'),
      images = report.images || [],
      latitude = Number(report.latitude),
      longitude = Number(report.longitude),
      hasCoordinates =
        report.latitude !== null &&
        report.longitude !== null &&
        validStrayCoordinates(latitude, longitude),
      gallery = strayDetailGallery(report, images),
      statusOptions = Object.entries(STRAY_STATUS_LABELS)
        .filter(
          ([value]) =>
            value !== 'submitted' || report.report_status === 'submitted',
        )
        .map(
          ([value, label]) =>
            `<option value="${value}" ${report.report_status === value ? 'selected' : ''}>${label}</option>`,
        )
        .join('');
    adminStrayCurrentVersion = {
      id: Number(report.id),
      status: report.report_status,
      notes: report.admin_notes || '',
    };
    modal.innerHTML = `<header class="admin-stray-modal-header"><div><span class="eyebrow">${esc(report.reference)}</span><h2 id="admin-stray-modal-title">${strayAnimalLabel(report.animal_type)} sighting</h2><p>${esc(report.location_text)}</p></div><button class="btn outline sm" data-admin-modal-close type="button" onclick="closeAdminStrayReport()">Close</button></header><div class="admin-stray-modal-grid"><div>${gallery}${hasCoordinates ? `<div id="admin-stray-detail-map" class="stray-map" role="img" aria-label="Map showing the reported stray sighting location"></div>` : '<div class="notice info">No map coordinates were submitted. Use the address or landmark above.</div>'}</div><div><dl class="admin-stray-facts"><div><dt>Reporter</dt><dd>${esc(report.reporter_name)}<small>${esc(report.reporter_email)}</small></dd></div><div><dt>Submitted</dt><dd>${date(report.submitted_at)}</dd></div><div><dt>Latest update</dt><dd>${date(report.updated_at)}</dd></div><div><dt>Animal</dt><dd>${strayAnimalLabel(report.animal_type)} · ${esc(report.breed || 'Unknown breed')} · ${esc(report.colour || 'Colour not recorded')}</dd></div><div><dt>Size / Sex</dt><dd>${petStatusLabel(report.size || 'unknown')} · ${petStatusLabel(report.sex || 'unknown')}</dd></div><div><dt>Condition</dt><dd>${strayConditionLabel(report.condition_category)} · ${strayUrgencyLabel(report.urgency)} urgency</dd></div></dl><section class="admin-stray-description"><h3>Description</h3><p>${esc(report.description)}</p><h3>Additional observations</h3><p>${esc(report.condition_notes || 'None provided.')}</p></section><div class="field"><label for="admin-stray-update-status">Status</label><select id="admin-stray-update-status">${statusOptions}</select></div><div class="field admin-stray-note-field"><label for="admin-stray-note">Reporter-visible rescue update <span class="muted">(optional)</span></label><textarea id="admin-stray-note" maxlength="2000" rows="4" placeholder="Visible to the reporter only; do not include private staff information.">${esc(report.admin_notes || '')}</textarea></div><div id="admin-stray-update-message" class="validation" role="alert" aria-live="polite"></div><div class="form-actions"><button id="admin-stray-save" class="btn" type="button" onclick="saveAdminStrayReport(${report.id})">Save Update</button><button class="btn outline" type="button" onclick="closeAdminStrayReport()">Cancel</button></div></div></div>`;
    setTimeout(
      () => modal.querySelector('[data-admin-modal-close]')?.focus(),
      0,
    );
    if (hasCoordinates)
      setTimeout(() => initAdminStrayDetailMap(latitude, longitude), 0);
  } catch (error) {
    let modal = $('.admin-stray-modal');
    if (modal)
      modal.innerHTML = `<div class="empty error-state"><h2>Unable to load Stray Report</h2><p>${esc(error.message || 'Please try again later.')}</p><button class="btn outline" onclick="closeAdminStrayReport()">Close</button></div>`;
  }
}

async function saveAdminStrayReport(id) {
  if (!adminStrayCurrentVersion || adminStrayCurrentVersion.id !== Number(id))
    return toast('Reopen this Stray Report before saving.', 'error');
  let button = $('#admin-stray-save'),
    message = $('#admin-stray-update-message'),
    reportStatus = $('#admin-stray-update-status')?.value || '',
    adminNotes = $('#admin-stray-note')?.value.trim() || '';
  if (message) message.textContent = '';
  setButtonBusy(button, true, 'Saving…');
  try {
    let url = new URL('api/admin-stray-reports.php', window.location.href);
    url.searchParams.set('id', id);
    let response = await fetch(url, {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          report_status: reportStatus,
          admin_notes: adminNotes,
          expected_status: adminStrayCurrentVersion.status,
          expected_admin_notes: adminStrayCurrentVersion.notes,
        }),
      }),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    toast(result.message, result.changed ? 'success' : 'info');
    closeAdminStrayReport();
    loadAdminStrayReports();
    if (typeof refreshNotificationCount === 'function')
      refreshNotificationCount();
  } catch (error) {
    if (message)
      message.textContent =
        error.message || 'Unable to update the Stray Report.';
    setButtonBusy(button, false);
  }
}
