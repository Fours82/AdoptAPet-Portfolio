let adminNewsItems = [];
let editingNewsId = null;
let publicNewsItems = [];
let newsExistingCoverPath = '';
let newsRemoveCoverImage = false;
let publicNewsPage = 1,
  adminNewsPage = 1;
let publicNewsAppliedCriteria = null;
const publicNewsPageSize = 6,
  adminNewsPageSize = 10;

function newsDisplayDate(value) {
  return value ? date(String(value).replace(' ', 'T')) : 'Not published';
}

function databaseNewsPage() {
  cancelLiveSearch('public-news');
  publicNewsAppliedCriteria = null;
  setTimeout(loadPublicNews, 0);
  return shell(
    `<div class="news-ux"><header class="news-heading"><h1>News</h1><p>Official shelter announcements, adoption drives, and community events.</p></header><section class="news-search listing-search-ui" aria-label="Search News"><label for="news-search">Search announcements</label><div class="news-search-row"><div class="field-with-icon">${uiIcon('search', 'input-icon')}<input id="news-search" class="input" maxlength="100" placeholder="Search title or content" oninput="schedulePublicNewsSearch()" onkeydown="runPublicNewsSearch(event)"></div><button class="btn outline listing-clear-action" type="button" onclick="clearNewsSearch()">Clear</button></div></section><p id="news-results-count" class="muted" aria-live="polite">Loading announcements…</p><section id="news-feed" aria-busy="true">${skeletonCards(6, 'Loading News announcements')}</section><div id="news-pagination"></div></div>`,
  );
}

function publicNewsHtml(items) {
  return items.length
    ? items
        .map(
          (item) =>
            `<a class="card news news-card-link ${item.cover_image_path ? 'has-cover' : 'without-cover'}" href="#/news/${item.id}">${item.cover_image_path ? `<img class="news-card-cover" src="${esc(item.cover_image_path)}" alt="Cover image for ${esc(item.title)}">` : '<div class="news-card-placeholder" aria-hidden="true"><span>🐾</span></div>'}<div class="news-card-content"><small class="muted">${newsDisplayDate(item.published_at)}</small><h3>${esc(item.title)}</h3><p>${esc(item.body)}</p><span class="link-btn">Read more →</span></div></a>`,
        )
        .join('')
    : '<div class="panel empty news-empty-state"><span aria-hidden="true">🐾</span><h2>No News announcements yet</h2><p>Official shelter updates will appear here when they are published.</p></div>';
}

function databaseNewsDetailPage(id) {
  setTimeout(() => loadPublicNewsDetail(id), 0);
  return shell(
    '<article id="news-detail" class="panel empty loading-state">Loading News announcement…</article>',
  );
}

async function loadPublicNewsDetail(id) {
  let target = $('#news-detail');
  if (!target) return;
  try {
    let url = new URL('api/news.php', window.location.href);
    url.searchParams.set('id', id);
    let response = await fetch(url, { credentials: 'same-origin' }),
      result = await response.json();
    if (!response.ok || !result.success)
      throw new Error(result.message || 'News announcement not found.');
    let item = result.news_item;
    target.className = 'news-detail news-detail-ux';
    target.innerHTML = `<a class="back-link" href="#/news">← Back to News</a><div class="news-detail-copy"><small class="muted">${newsDisplayDate(item.published_at)}${item.author_name ? ` · Published by ${esc(item.author_name)}` : ''}</small><h1>${esc(item.title)}</h1>${item.cover_image_path ? `<img class="news-detail-cover" src="${esc(item.cover_image_path)}" alt="Cover image for ${esc(item.title)}">` : ''}<div class="news-detail-body">${esc(item.body).replace(/\r?\n/g, '<br>')}</div></div>`;
  } catch (error) {
    target.className = 'panel empty error-state';
    target.innerHTML = `<h1>News announcement unavailable</h1><p>${esc(error.message || 'The selected announcement could not be found.')}</p><a class="btn" href="#/news">Back to News</a>`;
  }
}

async function fetchPublicNews() {
  let response = await fetch(new URL('api/news.php', window.location.href), {
      credentials: 'same-origin',
    }),
    result = await response.json();
  if (!response.ok || !result.success)
    throw new Error(result.message || 'Unable to load News announcements.');
  return result.news;
}

async function loadPublicNews() {
  let target = $('#news-feed');
  if (!target) return;
  setSkeleton(target, skeletonCards(6, 'Loading News announcements'));
  try {
    publicNewsItems = await fetchPublicNews();
    publicNewsAppliedCriteria = null;
    clearLoading(target);
    filterPublicNews();
  } catch (error) {
    clearLoading(target);
    target.innerHTML = `<div class="panel empty">${esc(error.message || 'Unable to load News announcements.')}</div>`;
  }
}

function filterPublicNews(resetPage = true) {
  let query = $('#news-search')?.value.trim().toLowerCase() || '';
  if (resetPage && query === publicNewsAppliedCriteria) return;
  let items = publicNewsItems.filter(
      (item) =>
        !query || `${item.title} ${item.body}`.toLowerCase().includes(query),
    ),
    target = $('#news-feed'),
    count = $('#news-results-count'),
    controls = $('#news-pagination');
  if (resetPage) publicNewsPage = 1;
  publicNewsAppliedCriteria = query;
  let page = paginatedItems(items, publicNewsPage, publicNewsPageSize);
  publicNewsPage = page.page;
  if (count)
    count.textContent = page.total
      ? `Showing ${page.start + 1}–${page.end} of ${page.total} published announcements${query ? ` · ${publicNewsItems.length} total` : ''}`
      : `0 published announcements${query ? ` · ${publicNewsItems.length} total` : ''}`;
  if (target)
    target.innerHTML = page.items.length
      ? publicNewsHtml(page.items)
      : '<div class="panel empty"><h2>No matching News</h2><p>Try a different title or keyword.</p></div>';
  if (controls)
    controls.innerHTML = paginationMarkup(
      page.page,
      page.totalPages,
      'changePublicNewsPage',
      'News pages',
    );
}
function changePublicNewsPage(page) {
  let query = $('#news-search')?.value.trim().toLowerCase() || '',
    items = publicNewsItems.filter(
      (item) =>
        !query || `${item.title} ${item.body}`.toLowerCase().includes(query),
    ),
    next = paginatedItems(items, page, publicNewsPageSize);
  if (next.page === publicNewsPage) return;
  publicNewsPage = next.page;
  filterPublicNews(false);
  paginationScroll('#news-results-count');
}
function schedulePublicNewsSearch() {
  scheduleLiveSearch('public-news', filterPublicNews);
}
function runPublicNewsSearch(event) {
  runLiveSearchNow(event, 'public-news', filterPublicNews);
}
function clearNewsSearch() {
  cancelLiveSearch('public-news');
  let field = $('#news-search');
  if (field) field.value = '';
  filterPublicNews();
}

async function loadDashboardNewsPreview() {
  let target = $('#dashboard-news-preview');
  if (!target) return;
  try {
    let items = (await fetchPublicNews()).slice(0, 2);
    target.innerHTML = items.length
      ? items
          .map(
            (item) =>
              `<p><b>${esc(item.title)}</b><br><small class="muted">${newsDisplayDate(item.published_at)}</small></p>`,
          )
          .join('')
      : '<div class="empty">No News announcements have been published yet.</div>';
  } catch (error) {
    target.innerHTML =
      '<div class="empty">Unable to load News announcements.</div>';
  }
}

function databaseAdminNewsPage() {
  editingNewsId = null;
  newsExistingCoverPath = '';
  newsRemoveCoverImage = false;
  clearEditedImageSelection('news-cover-image');
  setTimeout(loadAdminNews, 0);
  return shell(
    `<div class="admin-page-heading"><div class="page-title"><span class="eyebrow">Content administration</span><h1>News Management</h1><p>Create, edit, publish, and remove official shelter announcements.</p></div><button id="admin-news-create" class="btn" type="button" onclick="openNewsEditor()">Create Announcement</button></div><section class="panel admin-pet-filters admin-news-filters" aria-labelledby="admin-news-filter-title"><div class="admin-filter-heading"><div><h2 id="admin-news-filter-title">Find Announcements</h2><p class="muted">Search announcement content or narrow the list by publication status.</p></div><button id="admin-news-clear" class="btn outline sm" type="button" onclick="clearAdminNewsFilters()" hidden>Clear Filters</button></div><div class="admin-news-filter-grid"><div class="field"><label for="admin-news-search">Title or content</label><input id="admin-news-search" class="input" maxlength="255" placeholder="Search News announcements" oninput="filterAdminNews()"></div><div class="field"><label for="admin-news-status-filter">Publication status</label><select id="admin-news-status-filter" onchange="filterAdminNews()"><option value="">All statuses</option><option value="published">Published</option><option value="draft">Draft</option></select></div></div></section><div class="admin-table-meta"><p id="admin-news-results" class="muted" aria-live="polite">Loading News announcements…</p></div><section id="admin-news-list" class="admin-news-list" aria-busy="true">${skeletonList(4, 'Loading News announcements')}</section><div id="admin-news-pagination"></div>`,
    true,
  );
}

function newsEditorMarkup(item = null) {
  let editing = Boolean(item),
    title = editing ? 'Edit News Announcement' : 'Create News Announcement',
    subtitle = editing
      ? 'Update the announcement, publication state, or cover image.'
      : 'Publish immediately or keep the announcement as a draft.';
  return `<div id="admin-news-modal" class="modal-overlay" role="presentation" onclick="if(event.target===this)closeNewsEditor()"><section class="panel modal-panel admin-news-modal admin-news-editor" data-admin-modal role="dialog" aria-modal="true" aria-labelledby="news-editor-title" aria-describedby="news-editor-description"><header class="admin-news-editor-heading"><div><span class="eyebrow">Announcement editor</span><h2 id="news-editor-title">${title}</h2><p id="news-editor-description" class="muted">${subtitle}</p></div><button class="btn outline sm" data-admin-modal-close type="button" onclick="closeNewsEditor()" aria-label="Close News editor">Close</button></header><div class="admin-news-editor-body"><div class="form-grid"><div class="field full"><label for="news-title">News title</label><input id="news-title" class="input" maxlength="255" placeholder="Announcement title" value="${esc(item?.title || '')}"></div><div class="field full"><label for="news-body">Announcement</label><textarea id="news-body" rows="7" placeholder="Write the official announcement...">${esc(item?.body || '')}</textarea></div><div class="field admin-news-status-field"><label for="news-status">Publication status</label><select id="news-status" onchange="updateNewsSaveLabel()"><option value="published" ${!item || item.publication_status === 'published' ? 'selected' : ''}>Published</option><option value="draft" ${item?.publication_status === 'draft' ? 'selected' : ''}>Draft</option></select></div><div class="field full news-cover-field">${newsCoverUploadControl(newsExistingCoverPath)}</div></div></div><footer class="form-actions admin-news-editor-footer"><button id="news-save-button" class="btn" type="button" onclick="saveNews()">${editing ? 'Update Announcement' : 'Publish News'}</button><button class="btn outline" type="button" onclick="closeNewsEditor()">Cancel</button></footer></section></div>`;
}

function openNewsEditor(id = null) {
  let item =
    id === null
      ? null
      : adminNewsItems.find((newsItem) => newsItem.id === Number(id));
  if (id !== null && !item) return toast('News announcement not found.');
  beginAdminModal();
  editingNewsId = item?.id || null;
  newsExistingCoverPath = item?.cover_image_path || '';
  newsRemoveCoverImage = false;
  clearEditedImageSelection('news-cover-image');
  $('#admin-news-modal')?.remove();
  document.body.insertAdjacentHTML('beforeend', newsEditorMarkup(item));
  updateNewsSaveLabel();
  setTimeout(() => $('#news-title')?.focus(), 0);
}

function closeNewsEditor() {
  clearEditedImageSelection('news-cover-image');
  editingNewsId = null;
  newsExistingCoverPath = '';
  newsRemoveCoverImage = false;
  $('#admin-news-modal')?.remove();
  finishAdminModal();
}

function newsCoverUploadControl(existingPath = '') {
  let preview = existingPath
    ? `<img src="${esc(existingPath)}" alt="Current News cover image">`
    : '<span>No cover image selected</span>';
  return `<div class="image-upload"><label for="news-cover-image">Cover image (optional)</label><input id="news-cover-image" class="input" type="file" accept="image/jpeg,image/png,image/webp" onchange="selectNewsCoverImage(event)"><p class="field-help">JPEG, PNG or WebP · maximum 5 MB. Upload directly or use the optional image editor before saving.</p><div id="news-cover-preview" class="preview image-upload-preview news-cover-preview">${preview}</div><div class="editor-controls"><button type="button" onclick="reopenImageEditor('news-cover-image','news-cover-preview')">Edit Selected Image</button><button type="button" onclick="clearNewsCoverImage()">${existingPath ? 'Remove Cover Image' : 'Clear Selection'}</button></div><small id="news-cover-state" class="helper">${existingPath ? 'Current cover image will be kept unless replaced or removed.' : 'Announcements can be published without a cover image.'}</small></div>`;
}

function resetNewsCoverEditor(existingPath = '') {
  clearEditedImageSelection('news-cover-image');
  newsExistingCoverPath = existingPath || '';
  newsRemoveCoverImage = false;
  let input = $('#news-cover-image'),
    field = document.querySelector('.news-cover-field');
  if (input) input.value = '';
  if (field) field.innerHTML = newsCoverUploadControl(newsExistingCoverPath);
}

function selectNewsCoverImage(event) {
  newsRemoveCoverImage = false;
  openImageEditor(event, 'news-cover-preview');
  let stateText = $('#news-cover-state');
  if (stateText)
    stateText.textContent =
      'The selected image will replace the current cover when you save.';
}
function clearNewsCoverImage() {
  removeEditedImage('news-cover-image', 'news-cover-preview');
  newsRemoveCoverImage = Boolean(editingNewsId && newsExistingCoverPath);
  let stateText = $('#news-cover-state');
  if (stateText)
    stateText.textContent = newsRemoveCoverImage
      ? 'The current cover image will be removed when you save.'
      : 'This announcement will be saved without a cover image.';
}

function adminNewsExcerpt(body, limit = 190) {
  let text = String(body || '')
    .replace(/\s+/g, ' ')
    .trim();
  if (text.length <= limit) return text;
  let excerpt = text.slice(0, limit + 1),
    lastSpace = excerpt.lastIndexOf(' ');
  return `${excerpt.slice(0, lastSpace > limit * 0.65 ? lastSpace : limit).trim()}…`;
}

function adminNewsHtml(items) {
  return items.length
    ? items
        .map(
          (item) =>
            `<article class="panel admin-news-card"><div class="admin-news-thumbnail ${item.cover_image_path ? '' : 'empty'}">${item.cover_image_path ? `<img src="${esc(item.cover_image_path)}" alt="">` : '<span aria-hidden="true">🐾</span>'}</div><div class="admin-news-card-main"><div class="admin-news-card-meta"><span class="badge ${item.publication_status}">${item.publication_status === 'published' ? 'Published' : 'Draft'}</span><span>${item.publication_status === 'published' ? `Published ${newsDisplayDate(item.published_at)}` : `Created ${newsDisplayDate(item.created_at)}`}</span><span>Updated ${newsDisplayDate(item.updated_at)}</span></div><h3>${esc(item.title)}</h3><p>${esc(adminNewsExcerpt(item.body))}</p><small>By ${esc(item.author_name)} · ${item.cover_image_path ? 'Cover image included' : 'No cover image'}</small></div><div class="admin-news-card-actions"><button class="btn outline sm" type="button" onclick="editNews(${item.id})">Edit</button><button class="btn danger sm" type="button" onclick="deleteNews(${item.id})">Remove</button></div></article>`,
        )
        .join('')
    : '<div class="panel empty"><h2>No matching News announcements</h2><p>Try a different keyword or publication status.</p></div>';
}

async function loadAdminNews() {
  let target = $('#admin-news-list');
  if (!target) return;
  setSkeleton(target, skeletonList(4, 'Loading News announcements'));
  try {
    let response = await fetch(
        new URL('api/admin-news.php', window.location.href),
        { credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success)
      throw new Error(result.message || 'Unable to load News management.');
    adminNewsItems = result.news;
    clearLoading(target);
    filterAdminNews();
  } catch (error) {
    clearLoading(target);
    let count = $('#admin-news-results');
    if (count) count.textContent = 'Unable to load News announcements';
    target.innerHTML = `<div class="panel empty">${esc(error.message || 'Unable to load News management.')}</div>`;
  }
}

function filterAdminNews(resetPage = true) {
  let query = $('#admin-news-search')?.value.trim().toLowerCase() || '',
    status = $('#admin-news-status-filter')?.value || '',
    items = adminNewsItems.filter(
      (item) =>
        (!status || item.publication_status === status) &&
        (!query || `${item.title} ${item.body}`.toLowerCase().includes(query)),
    ),
    target = $('#admin-news-list'),
    count = $('#admin-news-results'),
    clear = $('#admin-news-clear');
  if (resetPage) adminNewsPage = 1;
  let page = paginatedItems(items, adminNewsPage, adminNewsPageSize);
  adminNewsPage = page.page;
  let controls = $('#admin-news-pagination');
  if (count)
    count.textContent = page.total
      ? `Showing ${page.start + 1}–${page.end} of ${page.total} announcements${query || status ? ` · ${adminNewsItems.length} total` : ''}`
      : `0 announcements${query || status ? ` · ${adminNewsItems.length} total` : ''}`;
  if (clear) clear.hidden = !(query || status);
  if (target) target.innerHTML = adminNewsHtml(page.items);
  if (controls)
    controls.innerHTML = paginationMarkup(
      page.page,
      page.totalPages,
      'changeAdminNewsPage',
      'News management pages',
    );
}
function changeAdminNewsPage(page) {
  let query = $('#admin-news-search')?.value.trim().toLowerCase() || '',
    status = $('#admin-news-status-filter')?.value || '',
    items = adminNewsItems.filter(
      (item) =>
        (!status || item.publication_status === status) &&
        (!query || `${item.title} ${item.body}`.toLowerCase().includes(query)),
    ),
    next = paginatedItems(items, page, adminNewsPageSize);
  if (next.page === adminNewsPage) return;
  adminNewsPage = next.page;
  filterAdminNews(false);
  paginationScroll('#admin-news-results');
}

function clearAdminNewsFilters() {
  let search = $('#admin-news-search'),
    status = $('#admin-news-status-filter');
  if (search) search.value = '';
  if (status) status.value = '';
  filterAdminNews();
}

function updateNewsSaveLabel() {
  let button = $('#news-save-button'),
    status = $('#news-status')?.value;
  if (button)
    button.textContent = editingNewsId
      ? status === 'published'
        ? 'Update and Publish'
        : 'Update Draft'
      : status === 'published'
        ? 'Publish News'
        : 'Save Draft';
}

function editDatabaseNews(id) {
  openNewsEditor(id);
}

function cancelNewsEdit() {
  closeNewsEditor();
}

async function saveDatabaseNews() {
  let title = $('#news-title')?.value.trim() || '',
    body = $('#news-body')?.value.trim() || '',
    publication_status = $('#news-status')?.value || '';
  if (!title || title.length > 255 || !body)
    return toast(
      'Enter a title of 255 characters or fewer and an announcement.',
    );
  let button = $('#news-save-button');
  setButtonBusy(button, true, editingNewsId ? 'Saving…' : 'Publishing…');
  try {
    let url = new URL('api/admin-news.php', window.location.href),
      form = new FormData();
    form.append(
      'payload',
      JSON.stringify({
        title,
        body,
        publication_status,
        remove_cover_image: newsRemoveCoverImage,
      }),
    );
    appendEditedImage(form, 'news-cover-image');
    if (editingNewsId) {
      url.searchParams.set('id', editingNewsId);
      form.append('_method', 'PATCH');
    }
    let response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        body: form,
      }),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to save the News announcement.');
    toast(result.message);
    closeNewsEditor();
    await loadAdminNews();
  } catch (error) {
    toast('Unable to save the News announcement. Please try again later.');
  } finally {
    if (button && document.body.contains(button)) setButtonBusy(button, false);
  }
}

async function archiveDatabaseNews(id) {
  let item = adminNewsItems.find((newsItem) => newsItem.id === Number(id)),
    title = item?.title || `News announcement #${id}`;
  if (
    !(await confirmAction({
      title: `Remove “${title}”?`,
      message: 'It will no longer appear on the public News feed.',
      confirmLabel: 'Remove',
      tone: 'danger',
    }))
  )
    return;
  try {
    let url = new URL('api/admin-news.php', window.location.href);
    url.searchParams.set('id', id);
    let response = await fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
      }),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to remove the News announcement.');
    if (editingNewsId === Number(id)) closeNewsEditor();
    toast(result.message);
    await loadAdminNews();
  } catch (error) {
    toast('Unable to remove the News announcement. Please try again later.');
  }
}

render();
