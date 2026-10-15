let publicPetCache = [];
let publicPetPagination = {
  current_page: 1,
  total_records: 0,
  total_pages: 0,
  page_size: 8,
  has_previous: false,
  has_next: false,
};

let publicPetRequestId = 0;
let publicPetAbortController = null,
  publicPetRequestedCriteria = '',
  publicPetAppliedCriteria = '',
  publicPetHasLoaded = false;
let adminPetCache = [];
let adminPetPage = 1;
let adminPetFilterTimer = null;
const adminPetPageSize = 8;
let adminMedicalCache = [];
let activeMedicalPetId = null;
let adminPetImageCache = [];
let activeImagePetId = null;
window.addEventListener('hashchange', () => {
  if (activeImagePetId !== null) closePetImageManager();
});

const petStatusLabel = (status) =>
  String(status || '').replace(
    /(^|_)([a-z])/g,
    (_, space, letter) => (space ? ' ' : '') + letter.toUpperCase(),
  );
const petImage = (pet) =>
  pet.image_path ||
  'https://images.unsplash.com/photo-1548199973-03cce0bbc87b?auto=format&fit=crop&w=900&q=85';
const petCardFromApi = (pet) =>
  `<a class="card pet-listing-card interactive-card" href="#/pet/${pet.id}"><img class="pet-img" src="${esc(petImage(pet))}" alt=""><div class="card-body"><div class="pet-title"><strong>${esc(pet.name)}</strong><span class="badge ${esc(pet.adoption_status)}">${esc(petStatusLabel(pet.adoption_status))}</span></div><p class="pet-listing-kind">${esc(pet.breed || 'Breed not specified')} · ${esc(petStatusLabel(pet.species))}</p><dl class="pet-listing-facts"><div><dt class="sr-only">Sex</dt><dd>${petDetailIcon(pet.sex === 'female' ? 'female' : pet.sex === 'male' ? 'male' : 'user')}${esc(petStatusLabel(pet.sex))}</dd></div><div><dt class="sr-only">Age</dt><dd>${petDetailIcon('calendar')}${esc(pet.age_label || 'Unknown')}</dd></div><div class="wide"><dt class="sr-only">Location</dt><dd>${petDetailIcon('pin')}${esc(pet.location || 'Not specified')}</dd></div></dl><span class="pet-listing-cue">View details <span aria-hidden="true">→</span></span></div></a>`;

function petsPage() {
  cancelLiveSearch('public-pets');
  publicPetAbortController?.abort();
  publicPetRequestedCriteria = '';
  publicPetAppliedCriteria = '';
  publicPetHasLoaded = false;
  setTimeout(() => loadPublicPets(1), 0);
  return shell(
    `<div class="pet-catalogue"><header class="standard-page-heading"><h1>Pet Listings</h1><p>Browse pets currently available for adoption and find your new companion.</p></header><section class="panel catalog-filter-panel listing-search-ui" aria-labelledby="pet-search-heading"><div class="catalog-filter-heading"><h2 id="pet-search-heading" class="listing-search-heading">Find your companion</h2></div><form class="catalog-search-row" onsubmit="runPublicPetSearch(event)"><div class="field"><label for="search" class="sr-only">Search Pet Listings</label><div class="field-with-icon">${uiIcon('search', 'input-icon')}<input id="search" class="input" maxlength="100" placeholder="Search pet name or breed" oninput="schedulePublicPetSearch()"></div></div><button type="submit" class="btn catalog-search-button listing-primary-action">${uiIcon('search')} Search</button><button id="mobile-pet-filter-toggle" type="button" class="btn outline mobile-pet-filter-toggle listing-filter-toggle" aria-controls="mobile-pet-filter-panel" aria-expanded="false" onclick="toggleMobilePetFilters()">Filters <span id="mobile-pet-filter-count" class="mobile-pet-filter-count listing-filter-count" hidden></span></button></form><div id="mobile-pet-filter-panel" class="mobile-pet-filter-panel"><div class="catalog-filter-grid pet-filter-grid"><div class="field"><label for="species">Species</label><select id="species" onchange="handlePublicPetFilterChange()"><option value="">All species</option><option value="dog">Dog</option><option value="cat">Cat</option><option value="other">Other</option></select></div><div class="field"><label for="breed">Breed</label><select id="breed" onchange="handlePublicPetFilterChange()"><option value="">All breeds</option></select></div><div class="field"><label for="sex">Sex</label><select id="sex" onchange="handlePublicPetFilterChange()"><option value="">All sexes</option><option value="male">Male</option><option value="female">Female</option><option value="unknown">Unknown</option></select></div><div class="field"><label for="location">Location</label><select id="location" onchange="handlePublicPetFilterChange()"><option value="">All locations</option></select></div><div class="field"><label for="max-age-months">Age</label><select id="max-age-months" onchange="handlePublicPetFilterChange()"><option value="">Any age</option><option value="12">Up to 1 year</option><option value="36">Up to 3 years</option><option value="84">Up to 7 years</option></select></div><div class="field"><label for="availability">Availability</label><select id="availability" onchange="handlePublicPetFilterChange()"><option value="available" selected>Available</option><option value="pending">Pending</option><option value="adopted">Adopted</option><option value="">All public statuses</option></select></div></div><div class="catalog-filter-actions"><button type="button" class="btn mobile-pet-filter-apply" onclick="applyMobilePetFilters()">Apply Filters</button><button type="button" class="btn outline sm desktop-pet-filter-clear listing-clear-action" onclick="clearPetFilters()">Clear All Filters</button><button type="button" class="btn outline mobile-pet-filter-clear listing-clear-action" onclick="clearPetFilters()">Clear Filters</button><button type="button" class="btn outline mobile-pet-filter-close" onclick="setMobilePetFiltersOpen(false)">Close</button></div></div></section><div class="catalog-results-meta"><p id="results-count" class="muted" aria-live="polite">Loading available Pet Listings…</p></div><div id="pet-results" class="grid pets" aria-busy="true">${skeletonCards(8, 'Loading Pet Listings')}</div><div id="pet-pagination"></div></div>`,
  );
}
function publicPetFilterValues() {
  return {
    q: $('#search')?.value.trim() || '',
    species: $('#species')?.value || '',
    breed: $('#breed')?.value || '',
    sex: $('#sex')?.value || '',
    location: $('#location')?.value || '',
    max_age_months: $('#max-age-months')?.value || '',
    status: $('#availability')?.value ?? 'available',
  };
}
function updatePublicPetOptions(options = {}) {
  for (let [id, key, label] of [
    ['breed', 'breeds', 'All breeds'],
    ['location', 'locations', 'All locations'],
  ]) {
    let field = $(`#${id}`);
    if (!field) continue;
    let selected = field.value;
    field.innerHTML =
      `<option value="">${label}</option>` +
      (options[key] || [])
        .map((value) => `<option value="${esc(value)}">${esc(value)}</option>`)
        .join('');
    field.value = selected;
    field.dataset.optionsLoaded = 'true';
  }
  updateMobilePetFilterCount();
}
// Public pet catalogue and filters
async function loadPublicPets(page = 1, scrollAfter = false) {
  let results = $('#pet-results'),
    count = $('#results-count'),
    url = new URL('api/pets.php', window.location.href),
    filters = publicPetFilterValues(),
    criteria = JSON.stringify({ page: Number(page) || 1, filters });
  if (
    !results ||
    criteria === publicPetAppliedCriteria ||
    criteria === publicPetRequestedCriteria
  )
    return;
  publicPetRequestedCriteria = criteria;
  publicPetAbortController?.abort();
  publicPetAbortController = new AbortController();
  let requestId = ++publicPetRequestId;
  url.searchParams.set('page', String(page));
  url.searchParams.set('limit', '8');
  for (let [key, value] of Object.entries(filters)) {
    if (key === 'status' || value !== '') url.searchParams.set(key, value);
  }
  if (publicPetHasLoaded) results.setAttribute('aria-busy', 'true');
  else {
    if (count) count.textContent = 'Loading Pet Listings…';
    setSkeleton(results, skeletonCards(8, 'Loading Pet Listings'));
  }
  try {
    let response = await fetch(url, {
        signal: publicPetAbortController.signal,
      }),
      result = await response.json();
    if (requestId !== publicPetRequestId) return;
    if (!response.ok || !result.success) throw new Error(result.message);
    publicPetCache = result.pets || [];
    publicPetPagination = result.pagination || publicPetPagination;
    publicPetAppliedCriteria = criteria;
    publicPetHasLoaded = true;
    if (
      ['breed', 'location'].some(
        (id) => $(`#${id}`)?.dataset.optionsLoaded !== 'true',
      )
    )
      updatePublicPetOptions(result.filter_options || {});
    clearLoading(results);
    renderPublicPets(publicPetCache, publicPetPagination);
    if (scrollAfter && results?.isConnected) paginationScroll('#results-count');
  } catch (error) {
    if (error.name === 'AbortError' || requestId !== publicPetRequestId) return;
    clearLoading(results);
    if (!publicPetHasLoaded) {
      if (count) count.textContent = 'Pet Listings could not be loaded.';
      results.innerHTML = `<div class="empty panel error-state"><h2>Unable to load pets</h2><p>${esc(error.message || 'Please try again later.')}</p><button class="btn" onclick="loadPublicPets(${Number(page) || 1})">Retry</button></div>`;
    } else if (count)
      count.textContent =
        'Pet Listings could not be updated. Please try again.';
  } finally {
    if (requestId === publicPetRequestId) publicPetRequestedCriteria = '';
  }
}
function publicPetFiltersAreMobile() {
  return window.matchMedia('(max-width: 40rem)').matches;
}
function setMobilePetFiltersOpen(open) {
  let panel = $('#mobile-pet-filter-panel'),
    button = $('#mobile-pet-filter-toggle');
  if (!panel || !button) return;
  panel.classList.toggle('is-open', Boolean(open));
  button.setAttribute('aria-expanded', String(Boolean(open)));
  if (open) setTimeout(() => panel.querySelector('select')?.focus(), 0);
}
function toggleMobilePetFilters() {
  let panel = $('#mobile-pet-filter-panel');
  setMobilePetFiltersOpen(!panel?.classList.contains('is-open'));
}
function updateMobilePetFilterCount() {
  let values = publicPetFilterValues(),
    count =
      ['species', 'breed', 'sex', 'location', 'max_age_months'].filter(
        (key) => values[key],
      ).length + (values.status !== 'available' ? 1 : 0),
    badge = $('#mobile-pet-filter-count');
  if (!badge) return;
  badge.textContent = count ? `(${count})` : '';
  badge.hidden = count === 0;
}
function handlePublicPetFilterChange() {
  updateMobilePetFilterCount();
  if (!publicPetFiltersAreMobile()) filterPets();
}
function applyMobilePetFilters() {
  filterPets();
  setMobilePetFiltersOpen(false);
}

function schedulePublicPetSearch() {
  scheduleLiveSearch('public-pets', filterPets);
}
function runPublicPetSearch(event) {
  runLiveSearchNow(event, 'public-pets', filterPets);
}
function filterPets() {
  loadPublicPets(1);
}
function clearPetFilters() {
  cancelLiveSearch('public-pets');
  ['search', 'species', 'breed', 'sex', 'location', 'max-age-months'].forEach(
    (id) => {
      let field = $(`#${id}`);
      if (field) field.value = '';
    },
  );
  let availability = $('#availability');
  if (availability) availability.value = 'available';
  updateMobilePetFilterCount();
  loadPublicPets(1);
  if (publicPetFiltersAreMobile()) setMobilePetFiltersOpen(false);
}
function changePetPage(page) {
  let target = Number(page);
  if (
    target < 1 ||
    target > publicPetPagination.total_pages ||
    target === publicPetPagination.current_page
  )
    return;
  loadPublicPets(target, true);
}
function renderPublicPets(pets, pagination) {
  let count = $('#results-count'),
    results = $('#pet-results'),
    controls = $('#pet-pagination'),
    status = $('#availability')?.value || '',
    statusText = status ? petStatusLabel(status).toLowerCase() : 'public',
    start = pagination.total_records
      ? (pagination.current_page - 1) * pagination.page_size + 1
      : 0,
    end = Math.min(
      pagination.current_page * pagination.page_size,
      pagination.total_records,
    );
  if (count)
    count.textContent = pagination.total_records
      ? `Showing ${start}–${end} of ${pagination.total_records} ${statusText} Pet Listing${pagination.total_records === 1 ? '' : 's'}`
      : `0 ${statusText} Pet Listings found`;
  if (results)
    results.innerHTML = pets.length
      ? pets.map(petCardFromApi).join('')
      : '<div class="empty panel"><h2>No matching pets</h2><p>No public Pet Listings match these filters.</p><button class="btn outline" onclick="clearPetFilters()">Clear All Filters</button></div>';
  if (controls)
    controls.innerHTML = paginationMarkup(
      pagination.current_page,
      pagination.total_pages,
      'changePetPage',
      'Pet Listing pages',
    );
}

function petDetail(id) {
  setTimeout(() => loadPetDetail(id), 0);
  return shell(
    `<div id="pet-detail" class="pet-detail-page"><div class="pet-detail-loading loading-state" role="status">Loading Pet Listing…</div></div>`,
  );
}
function petMedicalCards(records) {
  return `<ol class="pet-care-list">${records.map((record) => `<li class="pet-care-item"><div class="pet-care-heading"><h3>${esc(record.title || petStatusLabel(record.record_type))}</h3><span class="pet-care-type">${esc(petStatusLabel(record.record_type))}</span></div><p class="pet-care-date">${record.record_date ? date(record.record_date) : 'Date not recorded'}${record.next_due_date ? ` <span>· Next due ${date(record.next_due_date)}</span>` : ''}</p>${record.details ? `<p class="pet-care-details">${esc(record.details)}</p>` : ''}</li>`).join('')}</ol>`;
}
function petMedicalHtml(records, kind) {
  if (kind === 'vaccination') {
    let vaccinations = records.filter(
      (record) => record.record_type === 'vaccination',
    );
    return `<section class="pet-detail-section" aria-labelledby="pet-vaccination-heading"><h2 id="pet-vaccination-heading">${petDetailIcon('vaccine')}Vaccinations</h2>${vaccinations.length ? petMedicalCards(vaccinations) : '<p class="pet-detail-empty">No vaccination records have been added.</p>'}</section>`;
  }
  let history = records.filter(
    (record) => record.record_type !== 'vaccination',
  );
  return `<section class="pet-detail-section" aria-labelledby="pet-health-heading"><h2 id="pet-health-heading">${petDetailIcon('medical')}Health &amp; medical</h2>${history.length ? petMedicalCards(history) : '<p class="pet-detail-empty">No public medical or treatment records have been added.</p>'}</section>`;
}
function petDetailIcon(name) {
  let paths = {
    female: '<circle cx="12" cy="9" r="6"/><path d="M12 15v7M9 19h6"/>',
    male: '<circle cx="10" cy="14" r="6"/><path d="m14.5 9.5 6-6M16 3.5h4.5V8"/>',
    medical:
      '<rect x="5" y="4" width="14" height="18" rx="2"/><path d="M9 4V2h6v2M12 9v6M9 12h6"/>',
    vaccine: '<path d="m15 3 6 6M18 2l4 4M7 17l9-9M6 18l-3 3M11 9l4 4"/>',
    chat: '<path d="M20 11.5a8 8 0 0 1-8 8 8.2 8.2 0 0 1-3.6-.8L4 20l1.3-4.4A8 8 0 1 1 20 11.5Z"/>',
  };
  return paths[name]
    ? `<svg class="ui-icon pet-detail-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${paths[name]}</svg>`
    : uiIcon(name, 'pet-detail-icon');
}
function selectPetGalleryImage(button) {
  let main = $('#pet-main-image');
  if (!main || !button) return;
  main.src = button.dataset.image || main.src;
  main.alt = button.dataset.alt || main.alt;
  document.querySelectorAll('.pet-gallery-thumbnail').forEach((item) => {
    let active = item === button;
    item.classList.toggle('active', active);
    item.setAttribute('aria-current', active ? 'true' : 'false');
  });
}
// Pet detail and medical history
async function loadPetDetail(id) {
  let target = $('#pet-detail');
  if (!target) return;
  try {
    let response = await fetch(
        new URL(
          `api/pets.php?id=${encodeURIComponent(id)}`,
          window.location.href,
        ),
      ),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    let pet = result.pet,
      records = pet.medical_records || [],
      images = pet.images || [],
      available = pet.adoption_status === 'available',
      primary = images[0] || null,
      mainImage = primary?.file_path || petImage(pet),
      mainAlt =
        primary?.file_path || pet.image_path
          ? `${pet.name} — primary photo`
          : 'Illustrative pet image; no photo available',
      photoNote = !(primary?.file_path || pet.image_path)
        ? '<p class="pet-detail-image-note">Illustrative image — no photo of this pet is available.</p>'
        : '',
      gallery =
        images.length > 1
          ? `<div class="pet-gallery-thumbnails" role="group" aria-label="Photos of ${esc(pet.name)}">${images.map((image, index) => `<button type="button" class="pet-gallery-thumbnail ${index === 0 ? 'active' : ''}" aria-label="Show photo ${index + 1} of ${esc(pet.name)}" aria-current="${index === 0 ? 'true' : 'false'}" data-image="${esc(image.file_path)}" data-alt="${esc(`${pet.name} — photo ${index + 1}`)}" onclick="selectPetGalleryImage(this)"><img src="${esc(image.file_path)}" alt="" loading="lazy"></button>`).join('')}</div>`
          : '';
    let adoptionIcon = uiIcon('heart', 'pet-detail-action-icon'),
      chatIcon = petDetailIcon('chat');
    let applyAction =
      available && state.role === 'Registered User'
        ? `<button type="button" class="btn pet-apply-button" onclick="go('/apply/${pet.id}')">${adoptionIcon}Apply for Adoption</button>`
        : available && state.role === 'Guest'
          ? `<a class="btn pet-apply-button" href="#/login">${adoptionIcon}Log in to apply</a>`
          : '';
    let chatAction =
      state.role === 'Registered User'
        ? `<a class="btn outline" href="#/chat">${chatIcon}Chat with Shelter</a>`
        : isAdminUiRole(state.role)
          ? `<a class="btn outline" href="#/admin/chat">${chatIcon}Admin Chat</a>`
          : `<a class="btn outline" href="#/login">${chatIcon}Log in to chat</a>`;
    target.className = 'pet-detail-page';
    target.innerHTML = `<nav class="pet-detail-breadcrumb" aria-label="Breadcrumb"><a href="#/pets">← Back to Pet Listings</a><span aria-hidden="true">/</span><span>${esc(pet.name)}</span></nav><div class="pet-detail-hero"><div class="pet-detail-gallery"><div class="pet-detail-image-frame"><img id="pet-main-image" class="detail-image" src="${esc(mainImage)}" alt="${esc(mainAlt)}"></div>${gallery}${photoNote}</div><div class="pet-detail-summary"><span class="badge ${esc(pet.adoption_status)}">${esc(petStatusLabel(pet.adoption_status))}</span><h1>${esc(pet.name)}</h1><p class="pet-detail-intro">${esc(pet.breed || 'Breed not specified')} · ${esc(petStatusLabel(pet.species))}</p><dl class="pet-detail-facts"><div class="pet-detail-fact"><span class="pet-detail-fact-icon">${petDetailIcon(pet.sex === 'female' ? 'female' : pet.sex === 'male' ? 'male' : 'user')}</span><dt>Sex</dt><dd>${esc(petStatusLabel(pet.sex))}</dd></div><div class="pet-detail-fact"><span class="pet-detail-fact-icon">${petDetailIcon('calendar')}</span><dt>Age</dt><dd>${esc(pet.age_label || 'Unknown')}</dd></div><div class="pet-detail-fact"><span class="pet-detail-fact-icon">${petDetailIcon('pin')}</span><dt>Location</dt><dd>${esc(pet.location || 'Not specified')}</dd></div><div class="pet-detail-fact"><span class="pet-detail-fact-icon">${petDetailIcon('calendar')}</span><dt>Intake date</dt><dd>${pet.intake_date ? date(pet.intake_date) : 'Not recorded'}</dd></div></dl><div class="pet-detail-action-area">${available ? '<p class="pet-detail-availability">Available for adoption</p>' : `<p class="pet-detail-availability unavailable">${esc(petStatusLabel(pet.adoption_status))} — not accepting new applications</p>`}<div class="pet-detail-actions">${applyAction}${chatAction}</div>${available && state.role === 'Guest' ? '<p class="pet-detail-action-hint">Sign in or create an account to begin an application.</p>' : ''}</div></div></div><div class="pet-detail-content"><div class="pet-detail-content-main"><section class="pet-detail-section" aria-labelledby="pet-about-heading"><h2 id="pet-about-heading">About ${esc(pet.name)}</h2><p class="pet-detail-story">${esc(pet.story || 'No story has been added yet.')}</p></section>${petMedicalHtml(records, 'medical')}</div><aside class="pet-detail-content-aside">${petMedicalHtml(records, 'vaccination')}<section class="pet-detail-section" aria-labelledby="pet-intake-heading"><h2 id="pet-intake-heading">${petDetailIcon('home')}Rescue &amp; intake</h2><dl class="pet-detail-side-facts"><div><dt>Location</dt><dd>${esc(pet.location || 'Not specified')}</dd></div><div><dt>Intake date</dt><dd>${pet.intake_date ? date(pet.intake_date) : 'Not recorded'}</dd></div></dl></section></aside></div><section class="pet-detail-section pet-detail-adoption-info" aria-labelledby="pet-adoption-heading"><h2 id="pet-adoption-heading">${petDetailIcon('paw')}Adoption information</h2><p>Adopters should be ready to provide a safe home, appropriate food and veterinary care, enough daily time, and a long-term commitment. The shelter team reviews every application and may contact you for more information.</p></section>`;
  } catch (error) {
    target.className = 'pet-detail-page pet-detail-error';
    target.innerHTML = `<div class="panel empty error-state"><h1>Pet Listing unavailable</h1><p>${esc(error.message || 'This listing could not be found.')}</p><div class="form-actions"><a class="btn" href="#/pets">Browse Available Pets</a><button class="btn outline" onclick="location.reload()">Retry</button></div></div>`;
  }
}

// Administrator pet management
function adminPets() {
  adminPetPage = 1;
  setTimeout(loadAdminPets, 0);
  return shell(
    `<div class="admin-pet-management"><div class="admin-page-heading"><div class="page-title"><span class="eyebrow">Shelter inventory</span><h1>Pet Listing Management</h1><p>Maintain official Pet Listings, intake information, images, medical history, vaccinations, and adoption status.</p></div><button class="btn" onclick="petForm()">Add Pet Listing</button></div><section class="panel admin-pet-filters" aria-labelledby="admin-pet-filter-heading"><div class="admin-filter-heading"><div><h2 id="admin-pet-filter-heading">Find Pet Listings</h2><p class="muted">Search by pet name or refine the list by species and status.</p></div><button id="admin-pet-clear" class="btn outline sm" onclick="clearAdminPetFilters()" disabled>Clear Filters</button></div><div class="admin-pet-filter-grid"><div class="field"><label for="admin-pet-search">Pet name</label><input id="admin-pet-search" class="input" maxlength="100" placeholder="Search pet name" oninput="scheduleAdminPetFilter()"></div><div class="field"><label for="admin-pet-species">Species</label><select id="admin-pet-species" onchange="applyAdminPetFilters()"><option value="">All species</option><option value="dog">Dog</option><option value="cat">Cat</option><option value="other">Other</option></select></div><div class="field"><label for="admin-pet-status">Adoption status</label><select id="admin-pet-status" onchange="applyAdminPetFilters()"><option value="">All statuses</option><option value="available">Available</option><option value="pending">Pending</option><option value="adopted">Adopted</option><option value="inactive">Inactive</option></select></div></div></section><div class="admin-table-meta"><p id="admin-pet-results-count" class="muted" aria-live="polite">Loading Pet Listings…</p></div><div class="panel admin-pet-table-panel"><div class="table-wrap"><table class="table admin-pet-table"><thead><tr><th>Pet</th><th>Species</th><th>Location</th><th>Intake</th><th>Status</th><th>Actions</th></tr></thead><tbody id="admin-pets-table" aria-busy="true">${skeletonRows(5, 6, 'Loading Pet Listings')}</tbody></table></div><div id="admin-pet-pagination"></div></div></div>`,
    true,
  );
}
function adminPetFilterValues() {
  return {
    query: $('#admin-pet-search')?.value.trim().toLowerCase() || '',
    species: $('#admin-pet-species')?.value || '',
    status: $('#admin-pet-status')?.value || '',
  };
}
function scheduleAdminPetFilter() {
  clearTimeout(adminPetFilterTimer);
  adminPetPage = 1;
  adminPetFilterTimer = setTimeout(renderAdminPets, 200);
}
function applyAdminPetFilters() {
  adminPetPage = 1;
  renderAdminPets();
}
function clearAdminPetFilters() {
  clearTimeout(adminPetFilterTimer);
  let search = $('#admin-pet-search'),
    species = $('#admin-pet-species'),
    status = $('#admin-pet-status');
  if (search) search.value = '';
  if (species) species.value = '';
  if (status) status.value = '';
  adminPetPage = 1;
  renderAdminPets();
  search?.focus();
}
function changeAdminPetPage(page) {
  let filters = adminPetFilterValues(),
    total = filteredAdminPets(filters).length,
    totalPages = Math.max(1, Math.ceil(total / adminPetPageSize)),
    next = Number(page);
  if (next < 1 || next > totalPages || next === adminPetPage) return;
  adminPetPage = next;
  renderAdminPets();
  paginationScroll('.admin-pet-table-panel');
}
function filteredAdminPets(filters = adminPetFilterValues()) {
  return adminPetCache.filter(
    (pet) =>
      (!filters.query ||
        String(pet.name || '')
          .toLowerCase()
          .includes(filters.query)) &&
      (!filters.species || pet.species === filters.species) &&
      (!filters.status || pet.adoption_status === filters.status),
  );
}
function adminPetRow(pet) {
  return `<tr><td><div class="table-pet"><img src="${esc(petImage(pet))}" alt=""><span><b>${esc(pet.name)}</b><small>${esc(pet.breed || 'Breed not specified')}</small></span></div></td><td>${petStatusLabel(pet.species)}</td><td class="admin-pet-location">${esc(pet.location || '—')}</td><td>${pet.intake_date ? date(pet.intake_date) : '—'}</td><td><span class="badge ${esc(pet.adoption_status)}">${petStatusLabel(pet.adoption_status)}</span></td><td><div class="pet-row-actions"><button class="btn outline sm" onclick="petForm(${pet.id})">Edit Pet <span class="action-count" aria-label="${Number(pet.image_count) || 0} of 5 images">${Number(pet.image_count) || 0}/5</span></button><button class="btn outline sm" onclick="openMedicalRecords(${pet.id})">Medical Records</button></div></td></tr>`;
}
function renderAdminPets() {
  let body = $('#admin-pets-table'),
    count = $('#admin-pet-results-count'),
    pagination = $('#admin-pet-pagination'),
    clear = $('#admin-pet-clear');
  if (!body) return;
  let filters = adminPetFilterValues(),
    filtered = filteredAdminPets(filters),
    hasFilters = Boolean(filters.query || filters.species || filters.status),
    totalPages = Math.max(1, Math.ceil(filtered.length / adminPetPageSize));
  adminPetPage = Math.min(adminPetPage, totalPages);
  let start = filtered.length ? (adminPetPage - 1) * adminPetPageSize : 0,
    end = Math.min(start + adminPetPageSize, filtered.length),
    pageItems = filtered.slice(start, end);
  if (clear) clear.disabled = !hasFilters;
  if (count)
    count.textContent = filtered.length
      ? `Showing ${start + 1}–${end} of ${filtered.length} matching Pet Listing${filtered.length === 1 ? '' : 's'}${hasFilters ? ` · ${adminPetCache.length} total` : ''}`
      : `0 matching Pet Listings${hasFilters ? ` · ${adminPetCache.length} total` : ''}`;
  body.innerHTML = pageItems.length
    ? pageItems.map(adminPetRow).join('')
    : `<tr><td colspan="6"><div class="empty"><h3>${hasFilters ? 'No matching Pet Listings' : 'No Pet Listings have been created'}</h3><p>${hasFilters ? 'Try another pet name, species, or adoption status.' : 'Add a Pet Listing to begin managing shelter inventory.'}</p>${hasFilters ? '<button class="btn outline sm" onclick="clearAdminPetFilters()">Clear Filters</button>' : ''}</div></td></tr>`;
  if (pagination)
    pagination.innerHTML = paginationMarkup(
      adminPetPage,
      totalPages,
      'changeAdminPetPage',
      'Pet Management pages',
    );
}
async function loadAdminPets() {
  let body = $('#admin-pets-table'),
    count = $('#admin-pet-results-count'),
    pagination = $('#admin-pet-pagination');
  if (!body) return;
  setSkeleton(body, skeletonRows(5, 6, 'Loading Pet Listings'));
  if (count) count.textContent = 'Loading Pet Listings…';
  if (pagination) pagination.innerHTML = '';
  try {
    let response = await fetch(
        new URL('api/admin-pets.php', window.location.href),
        { credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    adminPetCache = result.pets || [];
    clearLoading(body);
    renderAdminPets();
  } catch (error) {
    clearLoading(body);
    if (count) count.textContent = 'Pet Listings could not be loaded.';
    body.innerHTML = `<tr><td colspan="6"><div class="empty error-state"><h3>Unable to load Pet Listings</h3><p>${esc(error.message || 'Please try again later.')}</p><button class="btn sm" onclick="loadAdminPets()">Retry</button></div></td></tr>`;
  }
}
function petForm(id) {
  let pet = id
    ? adminPetCache.find((p) => Number(p.id) === Number(id))
    : {
        name: '',
        species: 'dog',
        breed: '',
        sex: 'unknown',
        estimated_age_months: '',
        location: '',
        intake_date: '',
        adoption_status: 'available',
        story: '',
        image_path: '',
        image_count: 0,
      };
  if (!pet) return toast('Pet listing not found.');
  beginAdminModal();
  $('#modal')?.remove();
  clearEditedImageBatch('pet-gallery-images');
  activeImagePetId = id ? Number(id) : null;
  adminPetImageCache = [];
  let imageSection = id
    ? '<div id="pet-image-manager-content" class="empty loading-state">Loading current pet images…</div>'
    : `<div class="pet-image-manager-summary"><p><b>0 of 5 images</b></p><p class="muted">The first uploaded image will become the Primary image.</p></div>${imageBatchUploadControl('pet-gallery-images', 'pet-gallery-image-queue', 'Pet images', 5, 0, 5)}`;
  document.body.insertAdjacentHTML(
    'beforeend',
    `<div id="modal" class="modal-overlay" role="presentation" onclick="if(event.target===this)closeModal()"><div class="panel modal-panel pet-management-modal pet-edit-workflow-modal" data-admin-modal role="dialog" aria-modal="true" aria-labelledby="pet-workflow-title"><div class="pet-workflow-header"><div><span class="eyebrow">Pet Management</span><h2 id="pet-workflow-title">${id ? 'Edit Pet' : 'Add Pet Listing'}</h2><p class="muted">Update the Pet Details and gallery in one workflow.</p></div><button type="button" class="btn outline sm" data-admin-modal-close onclick="closeModal()" aria-label="Close Pet Listing form">Close</button></div><div class="pet-workflow-body"><fieldset class="pet-form-section"><legend>Pet Details</legend><div class="form-grid"><div class="field"><label for="pet-name">Name</label><input id="pet-name" class="input" maxlength="100" value="${esc(pet.name)}"></div><div class="field"><label for="pet-species">Species</label><select id="pet-species">${['dog', 'cat', 'other'].map((value) => `<option value="${value}" ${pet.species === value ? 'selected' : ''}>${petStatusLabel(value)}</option>`).join('')}</select></div><div class="field"><label for="pet-breed">Breed</label><input id="pet-breed" class="input" maxlength="150" value="${esc(pet.breed || '')}"></div><div class="field"><label for="pet-sex">Sex</label><select id="pet-sex">${['male', 'female', 'unknown'].map((value) => `<option value="${value}" ${pet.sex === value ? 'selected' : ''}>${petStatusLabel(value)}</option>`).join('')}</select></div><div class="field"><label for="pet-age">Estimated age (months)</label><input id="pet-age" type="number" min="0" max="65535" class="input" value="${pet.estimated_age_months ?? ''}"></div><div class="field"><label for="pet-location">Location</label><input id="pet-location" class="input" maxlength="150" value="${esc(pet.location || '')}"></div><div class="field"><label for="pet-intake-date">Intake date</label><input id="pet-intake-date" type="date" class="input" value="${esc(pet.intake_date || '')}"><small class="helper">Optional when the intake date is unknown.</small></div><div class="field"><label for="pet-status">Adoption status</label><select id="pet-status">${['available', 'pending', 'adopted', 'inactive'].map((value) => `<option value="${value}" ${pet.adoption_status === value ? 'selected' : ''}>${petStatusLabel(value)}</option>`).join('')}</select></div><div class="field full"><label for="pet-story">Pet story</label><textarea id="pet-story" rows="4">${esc(pet.story || '')}</textarea></div></div></fieldset><fieldset class="pet-form-section pet-images-workflow"><legend>Pet Images</legend>${imageSection}</fieldset></div><div class="form-actions pet-workflow-actions"><button id="pet-save" class="btn" onclick="savePet(${id || 0})">${id ? 'Save Pet Changes' : 'Create Pet Listing'}</button><button class="btn outline" onclick="closeModal()">Cancel</button></div></div></div>`,
  );
  setTimeout(() => $('#pet-name')?.focus(), 0);
  if (id) setTimeout(() => loadPetImages(true), 0);
}
function closeModal() {
  clearEditedImageSelection('pet-image');
  clearEditedImageBatch('pet-gallery-images');
  activeImagePetId = null;
  adminPetImageCache = [];
  $('#modal')?.remove();
  finishAdminModal();
}
async function uploadQueuedPetImages(petId) {
  let form = new FormData(),
    count = appendEditedImageBatch(form, 'pet-gallery-images');
  if (count === 0) return null;
  let response = await fetch(
      new URL(
        `api/admin-pet-images.php?pet_id=${encodeURIComponent(petId)}`,
        window.location.href,
      ),
      { method: 'POST', credentials: 'same-origin', body: form },
    ),
    result = await response.json();
  if (!response.ok || !result.success)
    throw new Error(
      result.message || 'Unable to upload the selected pet images.',
    );
  return result;
}
async function savePet(id) {
  let payload = {
    name: $('#pet-name').value.trim(),
    species: $('#pet-species').value,
    breed: $('#pet-breed').value.trim(),
    sex: $('#pet-sex').value,
    estimated_age_months: $('#pet-age').value,
    location: $('#pet-location').value.trim(),
    intake_date: $('#pet-intake-date').value,
    adoption_status: $('#pet-status').value,
    story: $('#pet-story').value.trim(),
  };
  if (!payload.name) return toast('Pet name is required.');
  let button = $('#pet-save'),
    detailsSaved = false,
    savedPetId = Number(id) || 0,
    imageCount =
      editedImageBatches.get('pet-gallery-images')?.items?.length || 0;
  setButtonBusy(button, true, imageCount ? 'Saving and uploading…' : 'Saving…');
  try {
    let url = new URL('api/admin-pets.php', window.location.href),
      form = new FormData();
    form.append('payload', JSON.stringify(payload));
    if (id) {
      url.searchParams.set('id', id);
      form.append('_method', 'PATCH');
    }
    let response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        body: form,
      }),
      result = await response.json();
    if (!response.ok || !result.success)
      throw new Error(result.message || 'Unable to save Pet Listing.');
    detailsSaved = true;
    savedPetId = Number(id || result.id);
    if (imageCount) await uploadQueuedPetImages(savedPetId);
    clearEditedImageBatch('pet-gallery-images');
    activeImagePetId = null;
    adminPetImageCache = [];
    $('#modal')?.remove();
    toast(
      imageCount
        ? `Pet Listing and ${imageCount} image${imageCount === 1 ? '' : 's'} saved successfully.`
        : result.message,
    );
    await loadAdminPets();
  } catch (error) {
    if (detailsSaved) {
      activeImagePetId = savedPetId;
      if (button) {
        button.setAttribute('onclick', `savePet(${savedPetId})`);
        button.dataset.originalHtml = 'Retry Save and Image Upload';
      }
      toast(
        `The Pet Listing was saved, but its selected images were not uploaded. ${error.message || 'Try again.'}`,
        'error',
      );
      await loadAdminPets();
    } else
      toast(
        error.message || 'Unable to save Pet Listing. Please try again later.',
        'error',
      );
  } finally {
    if (button && document.body.contains(button)) setButtonBusy(button, false);
  }
}
function closePetImageManager() {
  activeImagePetId = null;
  adminPetImageCache = [];
  clearEditedImageBatch('pet-gallery-images');
  $('#modal')?.remove();
}
function petImageManagerHtml(pet, images) {
  let atLimit = images.length >= 5,
    remaining = Math.max(0, 5 - images.length),
    cards = images
      .map(
        (image) =>
          `<article class="admin-pet-image-card ${image.is_primary ? 'primary' : ''}"><div class="admin-pet-image-thumb"><img src="${esc(image.file_path)}" alt="${esc(image.original_filename || `${pet.name} image`)}">${image.is_primary ? '<span class="badge approved">Primary</span>' : ''}</div><div class="admin-pet-image-meta"><b>${esc(image.original_filename || 'Pet image')}</b><small class="muted">${Math.round(Number(image.file_size_bytes || 0) / 1024)} KB</small></div><div class="table-actions">${image.is_primary ? '<span class="muted">Used on Pet Listings</span>' : `<button type="button" class="link-btn" onclick="setPrimaryPetImage(${image.id})">Set as Primary</button>`}<button type="button" class="link-btn danger-link" onclick="removePetGalleryImage(${image.id})">Remove</button></div></article>`,
      )
      .join('');
  return `<div class="pet-image-manager-summary"><p><b>${images.length} of 5 images</b></p><p class="muted">The Primary image appears on Pet Listings and first on Pet Details.</p></div><div class="admin-pet-image-grid">${cards || '<div class="empty panel">No images have been added for this pet.</div>'}</div>${atLimit ? '<div class="notice warning"><b>Image limit reached</b><br>Remove an image before adding another. A pet can have up to 5 images.</div>' : `<section class="pet-image-add-panel"><h3>Add pet images</h3><p class="muted">${remaining} image slot${remaining === 1 ? '' : 's'} remaining. Add images in one or several selections; edit only the images that need adjustment.</p>${imageBatchUploadControl('pet-gallery-images', 'pet-gallery-image-queue', 'Additional pet images', remaining, images.length, 5)}<div class="form-actions"><button id="pet-gallery-upload" type="button" class="btn" onclick="uploadPetGalleryImages()">Upload Images</button></div></section>`}`;
}
async function openPetImages(petId) {
  petForm(Number(petId));
}
async function loadPetImages(resetSelection = false) {
  let target = $('#pet-image-manager-content');
  if (!target || !activeImagePetId) return;
  if (resetSelection) clearEditedImageBatch('pet-gallery-images');
  try {
    let response = await fetch(
        new URL(
          `api/admin-pet-images.php?pet_id=${encodeURIComponent(activeImagePetId)}`,
          window.location.href,
        ),
        { credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    adminPetImageCache = result.images || [];
    let pet = adminPetCache.find(
      (item) => Number(item.id) === Number(activeImagePetId),
    );
    target.className = '';
    target.innerHTML = petImageManagerHtml(pet, adminPetImageCache);
    let batch = editedImageBatches.get('pet-gallery-images');
    if (batch) {
      batch.existingCount = adminPetImageCache.length;
      batch.maximum = Math.max(0, 5 - adminPetImageCache.length);
      batch.totalMaximum = 5;
      renderImageBatchQueue('pet-gallery-images');
    }
  } catch (error) {
    target.className = 'empty error-state';
    target.innerHTML = `<h3>Unable to load images</h3><p>${esc(error.message || 'Please try again later.')}</p><button type="button" class="btn outline" onclick="loadPetImages()">Retry</button>`;
  }
}
async function uploadPetGalleryImages() {
  if (!activeImagePetId) return;
  let count = editedImageBatches.get('pet-gallery-images')?.items?.length || 0;
  if (count === 0) return toast('Select at least one image before uploading.');
  let button = $('#pet-gallery-upload');
  setButtonBusy(button, true, 'Uploading…');
  try {
    let result = await uploadQueuedPetImages(activeImagePetId);
    toast(result.message);
    clearEditedImageBatch('pet-gallery-images');
    await loadPetImages(true);
    await loadAdminPets();
  } catch (error) {
    toast(
      error.message ||
        'Unable to upload the pet images. Please try again later.',
      'error',
    );
  } finally {
    if (button && document.body.contains(button)) setButtonBusy(button, false);
  }
}
async function setPrimaryPetImage(imageId) {
  if (!activeImagePetId) return;
  try {
    let response = await fetch(
        new URL(
          `api/admin-pet-images.php?pet_id=${encodeURIComponent(activeImagePetId)}&id=${encodeURIComponent(imageId)}`,
          window.location.href,
        ),
        {
          method: 'PATCH',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'set_primary' }),
        },
      ),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to change the Primary image.');
    toast(result.message);
    await loadPetImages();
    await loadAdminPets();
  } catch (error) {
    toast('Unable to change the Primary image. Please try again later.');
  }
}
async function removePetGalleryImage(imageId) {
  if (
    !activeImagePetId ||
    !(await confirmAction({
      title: 'Remove this pet image?',
      message: 'The uploaded file will also be deleted and cannot be restored.',
      confirmLabel: 'Remove image',
      tone: 'danger',
    }))
  )
    return;
  try {
    let response = await fetch(
        new URL(
          `api/admin-pet-images.php?pet_id=${encodeURIComponent(activeImagePetId)}&id=${encodeURIComponent(imageId)}`,
          window.location.href,
        ),
        { method: 'DELETE', credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to remove the pet image.');
    toast(result.message);
    await loadPetImages();
    await loadAdminPets();
  } catch (error) {
    toast('Unable to remove the pet image. Please try again later.');
  }
}
async function togglePet(id) {
  let pet = adminPetCache.find((item) => Number(item.id) === Number(id));
  if (!pet) return toast('Pet listing not found.');
  if (pet.adoption_status === 'inactive') return activatePet(pet);
  if (
    !(await confirmAction({
      title: `Deactivate ${pet.name}'s Pet Listing?`,
      message: 'It will no longer appear as available for adoption.',
      confirmLabel: 'Deactivate',
      tone: 'danger',
    }))
  )
    return;
  try {
    let response = await fetch(
        new URL(
          `api/admin-pets.php?id=${encodeURIComponent(id)}`,
          window.location.href,
        ),
        { method: 'DELETE', credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to deactivate Pet Listing.');
    toast(result.message);
    await loadAdminPets();
  } catch (error) {
    toast('Unable to deactivate Pet Listing. Please try again later.');
  }
}
async function activatePet(pet) {
  if (
    !(await confirmAction({
      title: `Activate ${pet.name}'s Pet Listing?`,
      message:
        'This will mark the pet as available and show the listing publicly.',
      confirmLabel: 'Activate',
      tone: 'positive',
    }))
  )
    return;
  let payload = {
      name: pet.name,
      species: pet.species,
      breed: pet.breed || '',
      sex: pet.sex,
      estimated_age_months: pet.estimated_age_months ?? '',
      location: pet.location || '',
      intake_date: pet.intake_date || '',
      adoption_status: 'available',
      story: pet.story || '',
    },
    form = new FormData();
  form.append('payload', JSON.stringify(payload));
  form.append('_method', 'PATCH');
  try {
    let response = await fetch(
        new URL(
          `api/admin-pets.php?id=${encodeURIComponent(pet.id)}`,
          window.location.href,
        ),
        { method: 'POST', credentials: 'same-origin', body: form },
      ),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to activate Pet Listing.');
    toast('Pet listing activated successfully.');
    await loadAdminPets();
  } catch (error) {
    toast('Unable to activate Pet Listing. Please try again later.');
  }
}

function medicalRecordFormHtml() {
  return `<form class="medical-record-form" onsubmit="saveMedicalRecord(event)"><input id="medical-record-id" type="hidden"><div class="medical-record-form-grid"><div class="field medical-type-field"><label for="medical-type">Record Type</label><select id="medical-type"><option value="vaccination">Vaccination</option><option value="treatment">Treatment</option><option value="sterilization">Sterilization</option><option value="checkup">Checkup</option><option value="other">Other</option></select></div><div class="field medical-title-field"><label for="medical-title">Title</label><input id="medical-title" class="input" maxlength="200" required></div><div class="field"><label for="medical-date">Record Date</label><input id="medical-date" type="date" class="input" required></div><div class="field"><label for="medical-next-due">Next Due Date</label><input id="medical-next-due" type="date" class="input"><small class="helper">Optional for vaccinations and follow-up care.</small></div><div class="field full"><label for="medical-details">Details</label><textarea id="medical-details" rows="5"></textarea></div></div><div class="form-actions medical-form-actions"><button id="medical-save" class="btn" type="submit">Add Medical Record</button><button class="btn outline" type="button" onclick="resetMedicalRecordForm()">Clear Form</button></div></form>`;
}
function openMedicalRecords(petId) {
  let pet = adminPetCache.find((item) => Number(item.id) === Number(petId));
  if (!pet) return toast('Pet listing not found.');
  beginAdminModal();
  activeMedicalPetId = Number(petId);
  adminMedicalCache = [];
  $('#modal')?.remove();
  document.body.insertAdjacentHTML(
    'beforeend',
    `<div id="modal" class="modal-overlay" role="presentation" onclick="if(event.target===this)closeModal()"><div class="panel modal-panel medical-management-modal" data-admin-modal role="dialog" aria-modal="true" aria-labelledby="medical-modal-title"><div class="medical-modal-header"><div><span class="eyebrow">Pet health</span><h2 id="medical-modal-title">Medical Records</h2><p class="muted">${esc(pet.name)} · Maintain vaccination and treatment history.</p></div><button type="button" class="btn outline sm" data-admin-modal-close onclick="closeModal()" aria-label="Close medical records">Close</button></div><div class="medical-management-layout"><section class="medical-form-panel" aria-labelledby="medical-form-heading"><div class="medical-panel-heading"><h3 id="medical-form-heading">Add Record</h3><p class="muted">Record clinical care and future due dates.</p></div>${medicalRecordFormHtml()}</section><section class="medical-history-panel" aria-labelledby="medical-list-heading"><div class="medical-panel-heading"><h3 id="medical-list-heading">Recorded History</h3><p class="muted">Newest records appear first.</p></div><div id="admin-medical-list" class="admin-medical-list"><div class="panel empty loading-state">Loading medical records…</div></div></section></div></div></div>`,
  );
  setTimeout(() => $('#medical-record-type')?.focus(), 0);
  loadMedicalRecords(petId);
}
async function loadMedicalRecords(petId = activeMedicalPetId) {
  let list = $('#admin-medical-list');
  if (!list) return;
  try {
    let response = await fetch(
        new URL(
          `api/admin-medical-records.php?pet_id=${encodeURIComponent(petId)}`,
          window.location.href,
        ),
        { credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    adminMedicalCache = result.medical_records || [];
    renderAdminMedicalRecords();
  } catch (error) {
    list.innerHTML = `<div class="panel empty error-state"><p>${esc(error.message || 'Unable to load medical records.')}</p><button class="btn sm" onclick="loadMedicalRecords(${petId})">Retry</button></div>`;
  }
}
function renderAdminMedicalRecords() {
  let list = $('#admin-medical-list');
  if (!list) return;
  list.innerHTML = adminMedicalCache.length
    ? adminMedicalCache
        .map(
          (record) =>
            `<article class="medical-record-card"><div class="medical-record-card-heading"><div><b>${esc(record.title)}</b><span class="medical-record-date">Recorded ${record.record_date ? date(record.record_date) : 'date not available'}</span></div><span class="badge">${petStatusLabel(record.record_type)}</span></div>${record.next_due_date ? `<p class="medical-next-due"><b>Next due:</b> ${date(record.next_due_date)}</p>` : ''}<p class="medical-record-details">${esc(record.details || 'No additional details.')}</p><div class="form-actions"><button class="btn outline sm" onclick="editMedicalRecord(${record.id})">Edit</button><button class="btn danger sm" onclick="removeMedicalRecord(${record.id})">Remove</button></div></article>`,
        )
        .join('')
    : '<div class="panel empty"><h4>No medical history recorded</h4><p>Add a vaccination, checkup, treatment, sterilization, or other medical record.</p></div>';
}
function resetMedicalRecordForm() {
  let form = $('#medical-record-id');
  if (!form) return;
  form.value = '';
  $('#medical-type').value = 'vaccination';
  $('#medical-title').value = '';
  $('#medical-date').value = '';
  $('#medical-next-due').value = '';
  $('#medical-details').value = '';
  $('#medical-save').textContent = 'Add Medical Record';
  $('#medical-form-heading').textContent = 'Add record';
}
function editMedicalRecord(recordId) {
  let record = adminMedicalCache.find((item) => item.id === recordId);
  if (!record) return toast('Medical record not found.');
  $('#medical-record-id').value = record.id;
  $('#medical-type').value = record.record_type;
  $('#medical-title').value = record.title;
  $('#medical-date').value = record.record_date;
  $('#medical-next-due').value = record.next_due_date || '';
  $('#medical-details').value = record.details || '';
  $('#medical-save').textContent = 'Update Medical Record';
  $('#medical-form-heading').textContent = 'Edit record';
  $('#medical-title').focus();
}
async function saveMedicalRecord(event) {
  event.preventDefault();
  let recordId = Number($('#medical-record-id').value || 0),
    payload = {
      record_type: $('#medical-type').value,
      title: $('#medical-title').value.trim(),
      details: $('#medical-details').value.trim(),
      record_date: $('#medical-date').value,
      next_due_date: $('#medical-next-due').value,
    };
  if (!payload.title || !payload.record_date)
    return toast('Medical record title and record date are required.');
  let button = $('#medical-save');
  setButtonBusy(button, true, 'Saving…');
  try {
    let url = new URL(
        `api/admin-medical-records.php?pet_id=${encodeURIComponent(activeMedicalPetId)}`,
        window.location.href,
      ),
      method = 'POST';
    if (recordId) {
      url.searchParams.set('id', recordId);
      method = 'PATCH';
    }
    let response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      }),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to save medical record.');
    toast(result.message);
    resetMedicalRecordForm();
    await loadMedicalRecords();
  } catch (error) {
    toast('Unable to save medical record. Please try again later.');
  } finally {
    if (button && document.body.contains(button)) setButtonBusy(button, false);
  }
}
async function removeMedicalRecord(recordId) {
  if (
    !(await confirmAction({
      title: 'Remove this medical record?',
      message: 'This action cannot be undone.',
      confirmLabel: 'Remove record',
      tone: 'danger',
    }))
  )
    return;
  try {
    let url = new URL(
        `api/admin-medical-records.php?pet_id=${encodeURIComponent(activeMedicalPetId)}&id=${encodeURIComponent(recordId)}`,
        window.location.href,
      ),
      response = await fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
      }),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to remove medical record.');
    toast(result.message);
    if (Number($('#medical-record-id')?.value || 0) === recordId)
      resetMedicalRecordForm();
    loadMedicalRecords();
  } catch (error) {
    toast('Unable to remove medical record. Please try again later.');
  }
}
