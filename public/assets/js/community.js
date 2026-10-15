let communityPostCache = [];
let communityComposerOpen = false;
let communityEditPostId = null,
  communityEditSaving = false,
  communityEditPreviousFocus = null;
let communityPage = 1,
  adminCommunityPage = 1,
  adminCommunityPostCache = [];
let communityNotificationScroll = null;
let communityAppliedCriteria = null;
const communityPageSize = 6,
  adminCommunityPageSize = 10;

function databaseCommunityPage() {
  communityComposerOpen = false;
  cancelLiveSearch('public-community');
  communityAppliedCriteria = null;
  clearEditedImageBatch('community-images');
  setTimeout(loadCommunityFeed, 0);
  return shell(
    `<div class="community-ux"><div class="community-heading"><div><h1>Community</h1><p>Share rescue stories, encouragement, comments, and likes with other animal lovers.</p></div></div><section class="community-search listing-search-ui" aria-label="Search Community Posts"><label for="community-search">Search posts</label><div class="community-search-row"><input id="community-search" class="input" maxlength="100" placeholder="Search post content or author" oninput="scheduleCommunitySearch()" onkeydown="runCommunitySearch(event)"><button type="button" class="btn outline listing-clear-action" onclick="clearCommunitySearch()">Clear</button><button type="button" class="btn community-create-action" onclick="openCommunityComposer()">+ Create Post</button></div></section><p id="community-results-count" class="muted" aria-live="polite">Loading Community Posts…</p><section id="community-feed" class="community-feed" aria-busy="true">${skeletonList(6, 'Loading Community Posts')}</section><div id="community-pagination"></div></div><div id="community-composer-modal" class="modal-overlay" hidden onclick="if(event.target===this)closeCommunityComposer()"><div class="panel modal-panel community-composer" role="dialog" aria-modal="true" aria-labelledby="community-composer-title"><div class="section-head"><div><h2 id="community-composer-title">Create Community Post</h2><p class="muted">Share an update with the AdoptAPet community.</p></div><button class="link-btn" onclick="closeCommunityComposer()" aria-label="Close Create Post form">Close</button></div><textarea id="post-text" rows="4" maxlength="5000" placeholder="Share a rescue story or community update..." oninput="updatePostCounter()"></textarea><div class="community-image-control">${imageBatchUploadControl('community-images', 'community-image-queue', 'Community Post images', 4)}</div><div class="editor-controls"><span id="post-counter" class="character-counter">0 / 5,000</span><button id="community-publish" class="btn sm" onclick="createPost()">Publish post</button></div></div></div>`,
  );
}

function communityPostDetail(id) {
  let scrollSection =
    communityNotificationScroll?.postId === String(id)
      ? communityNotificationScroll.section
      : null;
  communityNotificationScroll = null;
  if (!/^[1-9][0-9]*$/.test(String(id || '')))
    return shell(
      '<div class="panel empty error-state"><h1>Community Post unavailable</h1><p>Select a valid post from the Community feed.</p><a class="btn" href="#/community">Back to Community</a></div>',
    );
  setTimeout(() => loadCommunityPostDetail(id, scrollSection), 0);
  return shell(
    '<div class="community-ux community-detail"><div class="community-detail-heading"><a class="back-link" href="#/community">← Back to Community</a><h1>Community Post</h1></div><div id="community-detail-post" aria-busy="true">' +
      skeletonList(1, 'Loading Community Post') +
      '</div></div>',
  );
}

async function loadCommunityPostDetail(id, scrollSection = null) {
  let target = $('#community-detail-post');
  if (!target) return;
  try {
    let url = new URL('api/community.php', window.location.href);
    url.searchParams.set('post_id', id);
    let response = await fetch(url, { credentials: 'same-origin' }),
      result = await response.json();
    if (!response.ok || !result.success)
      throw new Error(result.message || 'Community Post not found.');
    if (!target.isConnected) return;
    communityPostCache = [result.post];
    target.removeAttribute('aria-busy');
    target.innerHTML = communityDetailPostHtml(result.post);
    if (scrollSection && currentHashRoute() === '/community/' + id)
      paginationScroll(
        '#community-' +
          (scrollSection === 'comments' ? 'comments-' : 'post-') +
          id,
      );
  } catch (error) {
    if (!target.isConnected) return;
    target.removeAttribute('aria-busy');
    target.innerHTML = `<div class="panel empty error-state"><h2>Community Post unavailable</h2><p>${esc(error.message || 'This post could not be loaded.')}</p><a class="btn" href="#/community">Back to Community</a></div>`;
  }
}

function openCommunityComposer() {
  let modal = $('#community-composer-modal');
  if (!modal) return;
  communityComposerOpen = true;
  modal.hidden = false;
  setTimeout(() => $('#post-text')?.focus(), 0);
}
function closeCommunityComposer() {
  let modal = $('#community-composer-modal');
  communityComposerOpen = false;
  if (!modal) return;
  modal.hidden = true;
  if ($('#post-text')) $('#post-text').value = '';
  clearEditedImageBatch('community-images');
  updatePostCounter();
}
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && !$('#community-composer-modal')?.hidden)
    closeCommunityComposer();
});

function updatePostCounter() {
  let input = $('#post-text'),
    counter = $('#post-counter');
  if (input && counter)
    counter.textContent = `${input.value.length.toLocaleString()} / 5,000`;
}

function setCommunityCarouselImage(postId, nextIndex) {
  let carousel = $(`#community-carousel-${Number(postId)}`);
  if (!carousel) return;
  let images = Array.from(
      carousel.querySelectorAll('.community-carousel-image'),
    ),
    count = images.length;
  if (!count) return;
  let index = (((Number(nextIndex) || 0) % count) + count) % count;
  carousel.dataset.index = String(index);
  images.forEach((image, imageIndex) => {
    let active = imageIndex === index;
    image.hidden = !active;
    image.setAttribute('aria-hidden', active ? 'false' : 'true');
  });
  carousel
    .querySelectorAll('.community-carousel-dot')
    .forEach((dot, dotIndex) => {
      let active = dotIndex === index;
      dot.classList.toggle('active', active);
      dot.setAttribute('aria-current', active ? 'true' : 'false');
    });
  let indicator = carousel.querySelector('.community-carousel-indicator');
  if (indicator) indicator.textContent = `${index + 1} / ${count}`;
}
function moveCommunityCarousel(postId, direction) {
  let carousel = $(`#community-carousel-${Number(postId)}`);
  if (!carousel) return;
  setCommunityCarouselImage(
    postId,
    Number(carousel.dataset.index || 0) + Number(direction || 0),
  );
}
function communityCarouselKeydown(event, postId) {
  if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
  event.preventDefault();
  moveCommunityCarousel(postId, event.key === 'ArrowLeft' ? -1 : 1);
}
function communityPostImages(post) {
  let images =
    Array.isArray(post.images) && post.images.length
      ? post.images
      : post.image_path
        ? [{ file_path: post.image_path }]
        : [];
  if (!images.length) return '';
  if (images.length === 1)
    return `<div class="community-single-media"><img class="community-post-image" src="${esc(images[0].file_path)}" alt="Image attached to ${esc(post.author_name)}'s Community Post"></div>`;
  let postId = Number(post.id) || 0,
    count = images.length;
  return `<div id="community-carousel-${postId}" class="community-post-carousel" data-index="0" tabindex="0" role="group" aria-roledescription="carousel" aria-label="${count} images attached to ${esc(post.author_name)}'s Community Post" onkeydown="communityCarouselKeydown(event,${postId})"><div class="community-carousel-media">${images.map((image, index) => `<img class="community-carousel-image" src="${esc(image.file_path)}" alt="Image ${index + 1} of ${count} attached to ${esc(post.author_name)}'s Community Post" ${index ? 'hidden aria-hidden="true"' : 'aria-hidden="false"'}>`).join('')}</div><button type="button" class="community-carousel-arrow previous" onclick="moveCommunityCarousel(${postId},-1)" aria-label="Show previous image">&#10094;</button><button type="button" class="community-carousel-arrow next" onclick="moveCommunityCarousel(${postId},1)" aria-label="Show next image">&#10095;</button><span class="community-carousel-indicator" aria-live="polite">1 / ${count}</span><div class="community-carousel-dots" aria-label="Choose an attached image">${images.map((_, index) => `<button type="button" class="community-carousel-dot ${index === 0 ? 'active' : ''}" onclick="setCommunityCarouselImage(${postId},${index})" aria-label="Show image ${index + 1} of ${count}" aria-current="${index === 0 ? 'true' : 'false'}"></button>`).join('')}</div></div>`;
}

function communityLikeLabel(count) {
  let value = Math.max(0, Number(count) || 0);
  return `${value} ${value === 1 ? 'like' : 'likes'}`;
}
function communityAvatar(name, owned = false) {
  let path =
    owned && typeof state === 'object' ? state.user?.profile_image_path : null;
  return `<div class="avatar" aria-hidden="true"><span>${esc(
    String(name || '?')
      .charAt(0)
      .toUpperCase(),
  )}</span>${path ? `<img src="${esc(path)}" alt="" onerror="this.remove()">` : ''}</div>`;
}
function communityCommentIcon() {
  return '<svg class="ui-icon community-comment-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5 9 9 0 0 1-4-.9L3 21l1.9-5.5a8.5 8.5 0 1 1 16.1-4Z"/></svg>';
}
function communityCommentHtml(postId, comment) {
  let owned = comment.is_owned_by_current_user
    ? `<button type="button" class="comment-delete-action" onclick="deleteCommunityComment(${Number(postId)},${Number(comment.id)})">Delete</button>`
    : '';
  return `<div id="community-comment-${Number(comment.id)}" class="comment community-comment"><div class="community-comment-main">${communityAvatar(comment.author_name, comment.is_owned_by_current_user)}<div class="community-comment-copy"><b>${esc(comment.author_name)}</b><span class="community-comment-text">${esc(comment.content)}</span><small class="muted">${date(comment.created_at)}</small></div></div>${owned}</div>`;
}

function communityGallery(post) {
  let images =
    Array.isArray(post.images) && post.images.length
      ? post.images
      : post.image_path
        ? [{ file_path: post.image_path }]
        : [];
  if (!images.length) return '';
  let count = Math.min(images.length, 4);
  return `<div class="community-gallery community-gallery--${count}" role="group" aria-label="${count} ${count === 1 ? 'image' : 'images'} attached to ${esc(post.author_name)}'s Community Post">${images
    .slice(0, 4)
    .map(
      (image, index) =>
        `<img src="${esc(image.file_path)}" alt="Image ${index + 1} of ${count} attached to ${esc(post.author_name)}'s Community Post" loading="lazy">`,
    )
    .join('')}</div>`;
}
function communityPostHeader(post) {
  let owned = post.is_owned_by_current_user
    ? `<div class="post-owner-actions"><button id="community-edit-button-${post.id}" type="button" class="link-btn" onclick="editCommunityPost(${post.id})">Edit</button><button type="button" class="link-btn danger-text" onclick="deleteCommunityPost(${post.id})">Delete</button></div>`
    : '';
  return `<div class="post-head"><div class="post-user">${communityAvatar(post.author_name, post.is_owned_by_current_user)}<div><b>${esc(post.author_name)}</b><small class="muted">${date(post.created_at)}${post.updated_at && post.updated_at !== post.created_at ? ' · Edited' : ''}</small></div></div>${owned}</div>`;
}
function communityLikeAction(post) {
  let likeLabel = communityLikeLabel(post.like_count);
  return `<button id="community-like-${post.id}" type="button" class="community-post-action community-like-action${post.liked_by_current_user ? ' is-liked' : ''}" aria-pressed="${post.liked_by_current_user ? 'true' : 'false'}" aria-label="${post.liked_by_current_user ? 'Unlike' : 'Like'} this post; ${likeLabel}" onclick="likePost(${post.id},${post.liked_by_current_user})"><span class="community-like-icon">${uiIcon('heart', 'community-heart-svg')}</span><span class="community-like-label">${likeLabel}</span></button>`;
}
function openCommunityComments(postId) {
  let id = String(postId);
  if (!/^[1-9][0-9]*$/.test(id)) return;
  communityNotificationScroll = { postId: id, section: 'comments' };
  go('/community/' + id);
}
function communityPostHtml(post) {
  return `<article id="community-post-${post.id}" class="card post community-feed-post">${communityPostHeader(post)}<a class="community-post-body" href="#/community/${post.id}" aria-label="Open ${esc(post.author_name)}'s Community Post"><p id="post-content-${post.id}">${esc(post.content)}</p>${communityGallery(post)}</a><div class="post-actions">${communityLikeAction(post)}<button id="community-comment-action-${post.id}" type="button" class="community-post-action community-comment-action" onclick="openCommunityComments(${post.id})">${communityCommentIcon()}<span id="community-comment-count-${post.id}">${post.comment_count}</span><span id="community-comment-label-${post.id}">${Number(post.comment_count) === 1 ? 'comment' : 'comments'}</span></button></div></article>`;
}
function communityDetailPostHtml(post) {
  let comments = (Array.isArray(post.comments) ? post.comments : [])
    .map((comment) => communityCommentHtml(post.id, comment))
    .join('');
  return `<article id="community-post-${post.id}" class="card post community-detail-post">${communityPostHeader(post)}<p id="post-content-${post.id}" class="community-detail-copy">${esc(post.content)}</p>${communityGallery(post)}<div class="post-actions">${communityLikeAction(post)}<span class="community-detail-comment-count">${communityCommentIcon()}<span id="community-comment-count-${post.id}">${post.comment_count}</span> <span id="community-comment-label-${post.id}">${Number(post.comment_count) === 1 ? 'comment' : 'comments'}</span></span></div><section id="community-comments-${post.id}" class="community-discussion" aria-labelledby="community-comments-heading-${post.id}"><h2 id="community-comments-heading-${post.id}">Comments</h2><div class="community-comments">${comments}</div><div class="comment-entry"><input id="comment-${post.id}" class="input" maxlength="2000" aria-label="Write a comment" placeholder="Write a comment" onkeydown="if(event.key==='Enter')commentPost(${post.id})"><button id="comment-button-${post.id}" class="btn sm" onclick="commentPost(${post.id})">Post</button></div></section></article>`;
}

async function loadCommunityFeed() {
  let target = $('#community-feed');
  if (!target) return;
  setSkeleton(target, skeletonList(6, 'Loading Community Posts'));
  try {
    let response = await fetch(
        new URL('api/community.php', window.location.href),
        { credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    communityPostCache = result.posts || [];
    communityAppliedCriteria = null;
    clearLoading(target);
    filterCommunityFeed();
  } catch (error) {
    clearLoading(target);
    target.innerHTML = `<div class="panel empty error-state"><h2>Unable to load Community Posts</h2><p>${esc(error.message || 'Please try again.')}</p><button class="btn" onclick="loadCommunityFeed()">Retry</button></div>`;
  }
}

function filterCommunityFeed(resetPage = true) {
  let query = $('#community-search')?.value.trim().toLowerCase() || '';
  if (resetPage && query === communityAppliedCriteria) return;
  let items = communityPostCache.filter(
      (post) =>
        !query ||
        `${post.author_name} ${post.content}`.toLowerCase().includes(query),
    ),
    target = $('#community-feed'),
    count = $('#community-results-count'),
    controls = $('#community-pagination');
  if (resetPage) communityPage = 1;
  communityAppliedCriteria = query;
  let page = paginatedItems(items, communityPage, communityPageSize);
  communityPage = page.page;
  if (count)
    count.textContent = page.total
      ? `Showing ${page.start + 1}–${page.end} of ${page.total} Community Posts${query ? ` · ${communityPostCache.length} total` : ''}`
      : `0 Community Posts${query ? ` · ${communityPostCache.length} total` : ''}`;
  if (target)
    target.innerHTML = page.items.length
      ? page.items.map(communityPostHtml).join('')
      : '<div class="panel empty"><h2>No matching Community Posts</h2><p>Try another keyword or clear the search.</p></div>';
  if (controls)
    controls.innerHTML = paginationMarkup(
      page.page,
      page.totalPages,
      'changeCommunityPage',
      'Community pages',
    );
}
function changeCommunityPage(page) {
  let query = $('#community-search')?.value.trim().toLowerCase() || '',
    items = communityPostCache.filter(
      (post) =>
        !query ||
        `${post.author_name} ${post.content}`.toLowerCase().includes(query),
    ),
    next = paginatedItems(items, page, communityPageSize);
  if (next.page === communityPage) return;
  communityPage = next.page;
  filterCommunityFeed(false);
  paginationScroll('#community-results-count');
}
function scheduleCommunitySearch() {
  scheduleLiveSearch('public-community', filterCommunityFeed);
}
function runCommunitySearch(event) {
  runLiveSearchNow(event, 'public-community', filterCommunityFeed);
}
function clearCommunitySearch() {
  cancelLiveSearch('public-community');
  let field = $('#community-search');
  if (field) field.value = '';
  filterCommunityFeed();
}

async function createDatabaseCommunityPost() {
  let content = $('#post-text')?.value.trim() || '';
  if (!content || content.length > 5000)
    return toast(
      'Community Post content is required and must be 5,000 characters or fewer.',
    );
  let button = $('#community-publish');
  setButtonBusy(button, true, 'Publishing…');
  try {
    let form = new FormData();
    form.append('payload', JSON.stringify({ content }));
    appendEditedImageBatch(form, 'community-images');
    let response = await fetch(
        new URL('api/community.php', window.location.href),
        { method: 'POST', credentials: 'same-origin', body: form },
      ),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to publish the Community Post.');
    toast(result.message);
    closeCommunityComposer();
    await loadCommunityFeed();
  } catch (error) {
    toast('Unable to publish the Community Post. Please try again later.');
  } finally {
    if (button && document.body.contains(button)) setButtonBusy(button, false);
  }
}

function communityEditModalMarkup(post) {
  return `<div id="community-edit-modal" class="modal-overlay" onclick="if(event.target===this)closeCommunityEditModal()"><section class="panel modal-panel community-edit-modal" role="dialog" aria-modal="true" aria-labelledby="community-edit-title" aria-describedby="community-edit-help"><div class="section-head"><div><h2 id="community-edit-title">Edit Post</h2><p id="community-edit-help" class="muted">Update the post text. Existing images will remain unchanged.</p></div><button type="button" class="link-btn" onclick="closeCommunityEditModal()" aria-label="Close Edit Post dialog">Close</button></div><div class="field"><label for="community-edit-text">Post text</label><textarea id="community-edit-text" rows="6" maxlength="5000" oninput="updateCommunityEditCounter()">${esc(post.content)}</textarea><div class="community-edit-meta"><span id="community-edit-counter" class="character-counter">${String(post.content).length.toLocaleString()} / 5,000</span></div></div><div id="community-edit-message" class="validation" role="alert" aria-live="polite"></div><div class="form-actions community-edit-actions"><button type="button" class="btn outline" onclick="closeCommunityEditModal()">Cancel</button><button id="community-edit-save" type="button" class="btn" onclick="saveCommunityPostEdit()">Save Changes</button></div></section></div>`;
}
function editCommunityPost(postId) {
  let post = communityPostCache.find(
    (item) => Number(item.id) === Number(postId),
  );
  if (!post) return toast('Your Community Post is no longer available.');
  $('#community-edit-modal')?.remove();
  communityEditPostId = Number(postId);
  communityEditSaving = false;
  communityEditPreviousFocus =
    document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;
  let page = document.querySelector('.public-site-page') || document.body;
  page.insertAdjacentHTML('beforeend', communityEditModalMarkup(post));
  setTimeout(() => $('#community-edit-text')?.focus(), 0);
}
function updateCommunityEditCounter() {
  let input = $('#community-edit-text'),
    counter = $('#community-edit-counter');
  if (input && counter)
    counter.textContent = `${input.value.length.toLocaleString()} / 5,000`;
}
function setCommunityEditMessage(message) {
  let target = $('#community-edit-message');
  if (target) target.textContent = String(message || '');
}
function closeCommunityEditModal(restoreFocus = true) {
  if (communityEditSaving) return;
  let trigger = communityEditPreviousFocus;
  $('#community-edit-modal')?.remove();
  communityEditPostId = null;
  communityEditPreviousFocus = null;
  if (restoreFocus && trigger?.isConnected) trigger.focus();
}
function handleCommunityEditKeydown(event) {
  let modal = $('#community-edit-modal');
  if (!modal || communityEditSaving) return;
  if (event.key === 'Escape') {
    event.preventDefault();
    closeCommunityEditModal();
    return;
  }
  if (event.key !== 'Tab') return;
  let focusable = Array.from(
    modal.querySelectorAll(
      'button:not([disabled]),textarea:not([disabled]),[href],[tabindex]:not([tabindex="-1"])',
    ),
  );
  if (!focusable.length) return;
  let first = focusable[0],
    last = focusable[focusable.length - 1];
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault();
    last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault();
    first.focus();
  }
}
document.addEventListener('keydown', handleCommunityEditKeydown);
async function saveCommunityPostEdit() {
  let postId = communityEditPostId,
    input = $('#community-edit-text'),
    content = input?.value.trim() || '',
    button = $('#community-edit-save');
  setCommunityEditMessage('');
  if (!content || content.length > 5000) {
    let message =
      'Community Post content is required and must be 5,000 characters or fewer.';
    setCommunityEditMessage(message);
    return toast(message);
  }
  communityEditSaving = true;
  setButtonBusy(button, true, 'Saving…');
  try {
    let response = await fetch(
        new URL(
          `api/community.php?action=post&post_id=${encodeURIComponent(postId)}`,
          window.location.href,
        ),
        {
          method: 'PATCH',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ content }),
        },
      ),
      result = await response.json();
    if (!response.ok || !result.success) {
      let message = result.message || 'Unable to update the Community Post.';
      setCommunityEditMessage(message);
      return toast(message);
    }
    let post = communityPostCache.find(
      (item) => Number(item.id) === Number(postId),
    );
    if (post) {
      post.content = content;
      post.updated_at = new Date().toISOString();
    }
    communityEditSaving = false;
    closeCommunityEditModal(false);
    let detail = $('#community-detail-post');
    if (detail && post) detail.innerHTML = communityDetailPostHtml(post);
    else filterCommunityFeed(false);
    setTimeout(() => $(`#community-edit-button-${postId}`)?.focus(), 0);
    toast(result.message);
  } catch (error) {
    setCommunityEditMessage(
      'Unable to update the Community Post. Please try again later.',
    );
    toast('Unable to update the Community Post. Please try again later.');
  } finally {
    communityEditSaving = false;
    if (button?.isConnected) setButtonBusy(button, false);
  }
}
async function deleteCommunityPost(postId) {
  if (
    !(await confirmAction({
      title: 'Delete your Community Post?',
      message: 'This will remove the post from the Community feed.',
      confirmLabel: 'Delete',
      tone: 'danger',
    }))
  )
    return;
  try {
    let response = await fetch(
        new URL(
          `api/community.php?action=post&post_id=${encodeURIComponent(postId)}`,
          window.location.href,
        ),
        { method: 'DELETE', credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to delete the Community Post.');
    toast(result.message);
    if (currentHashRoute().startsWith('/community/')) go('/community');
    else await loadCommunityFeed();
  } catch (error) {
    toast('Unable to delete the Community Post. Please try again later.');
  }
}

async function toggleDatabaseCommunityLike(postId, liked) {
  let button = $(`#community-like-${postId}`);
  if (button?.disabled) return;
  if (button) {
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
  }
  try {
    let response = await fetch(
        new URL(
          `api/community.php?action=like&post_id=${encodeURIComponent(postId)}`,
          window.location.href,
        ),
        {
          method: liked ? 'DELETE' : 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          ...(liked ? {} : { body: '{}' }),
        },
      ),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(
        result.message || 'Unable to update the Community Post like.',
      );
    updateCommunityLikeState(postId, Boolean(result.liked), result.like_count);
  } catch (error) {
    toast('Unable to update the Community Post like. Please try again later.');
  } finally {
    if (button?.isConnected) {
      button.disabled = false;
      button.removeAttribute('aria-busy');
    }
  }
}

function updateCommunityLikeState(postId, liked, count) {
  let post = communityPostCache.find(
      (item) => Number(item.id) === Number(postId),
    ),
    value = Math.max(0, Number(count) || 0),
    button = $(`#community-like-${postId}`),
    label = communityLikeLabel(value);
  if (post) {
    post.liked_by_current_user = liked;
    post.like_count = value;
  }
  if (!button) return;
  button.classList.toggle('is-liked', liked);
  button.setAttribute('aria-pressed', liked ? 'true' : 'false');
  button.setAttribute(
    'aria-label',
    `${liked ? 'Unlike' : 'Like'} this post; ${label}`,
  );
  button.setAttribute('onclick', `likePost(${Number(postId)},${liked})`);
  let copy = button.querySelector('.community-like-label');
  if (copy) copy.textContent = label;
}
function updateCommunityCommentCount(postId, count) {
  let post = communityPostCache.find(
      (item) => Number(item.id) === Number(postId),
    ),
    value = Math.max(0, Number(count) || 0),
    target = $(`#community-comment-count-${postId}`),
    label = $(`#community-comment-label-${postId}`);
  if (post) post.comment_count = value;
  if (target) target.textContent = String(value);
  if (label) label.textContent = value === 1 ? 'comment' : 'comments';
}

async function createDatabaseCommunityComment(postId) {
  let input = $(`#comment-${postId}`),
    content = input?.value.trim() || '';
  if (!content || content.length > 2000)
    return toast(
      'Comment content is required and must be 2,000 characters or fewer.',
    );
  let button = $(`#comment-button-${postId}`);
  setButtonBusy(button, true, 'Posting…');
  try {
    let response = await fetch(
        new URL(
          `api/community.php?action=comment&post_id=${encodeURIComponent(postId)}`,
          window.location.href,
        ),
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ content }),
        },
      ),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to post the comment.');
    let post = communityPostCache.find(
        (item) => Number(item.id) === Number(postId),
      ),
      comments = $(`#community-comments-${postId} .community-comments`);
    if (post) {
      if (!Array.isArray(post.comments)) post.comments = [];
      post.comments.push(result.comment);
    }
    if (comments)
      comments.insertAdjacentHTML(
        'beforeend',
        communityCommentHtml(postId, result.comment),
      );
    if (input) input.value = '';
    updateCommunityCommentCount(postId, result.comment_count);
    toast(result.message);
  } catch (error) {
    toast('Unable to post the comment. Please try again later.');
  } finally {
    if (button && document.body.contains(button)) setButtonBusy(button, false);
  }
}

async function deleteCommunityComment(postId, commentId) {
  if (
    !(await confirmAction({
      title: 'Delete your comment?',
      message: 'This will remove your comment from the Community Post.',
      confirmLabel: 'Delete',
      tone: 'danger',
    }))
  )
    return;
  let action = $(`#community-comment-${commentId} .comment-delete-action`);
  if (action) {
    action.disabled = true;
    action.setAttribute('aria-busy', 'true');
  }
  try {
    let response = await fetch(
        new URL(
          `api/community.php?action=comment&comment_id=${encodeURIComponent(commentId)}`,
          window.location.href,
        ),
        { method: 'DELETE', credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to delete the comment.');
    let post = communityPostCache.find(
      (item) => Number(item.id) === Number(postId),
    );
    if (post && Array.isArray(post.comments))
      post.comments = post.comments.filter(
        (comment) => Number(comment.id) !== Number(commentId),
      );
    $(`#community-comment-${commentId}`)?.remove();
    updateCommunityCommentCount(postId, result.comment_count);
    $(`#comment-${postId}`)?.focus();
    toast(result.message);
  } catch (error) {
    toast('Unable to delete the comment. Please try again later.');
  } finally {
    if (action?.isConnected) {
      action.disabled = false;
      action.removeAttribute('aria-busy');
    }
  }
}

function databaseAdminCommunityPage() {
  setTimeout(loadAdminCommunityPosts, 0);
  return shell(
    `<div class="admin-page-heading"><div class="page-title"><span class="eyebrow">Community safety</span><h1>Community Moderation</h1><p>Review Community Posts and remove content that violates community guidelines.</p></div></div><div class="admin-table-meta"><p id="admin-community-results-count" class="muted" aria-live="polite">Loading Community Posts…</p></div><section id="admin-community-feed" class="admin-community-feed" aria-busy="true">${skeletonList(4, 'Loading Community Posts')}</section><div id="admin-community-pagination"></div>`,
    true,
  );
}

function adminCommunityPostHtml(post) {
  let images = post.images?.length
      ? post.images
      : post.image_path
        ? [{ file_path: post.image_path }]
        : [],
    media = images.length
      ? `<div class="admin-community-media"><img src="${esc(images[0].file_path)}" alt="Community Post image by ${esc(post.author_name)}">${images.length > 1 ? `<span class="admin-community-media-count">+${images.length - 1} more</span>` : ''}</div>`
      : '';
  return `<article class="panel admin-community-post"><div class="post-user"><div class="avatar">${esc(post.author_name.charAt(0))}</div><div><b>${esc(post.author_name)}</b><small class="muted">${date(post.created_at)}</small></div></div><div class="admin-community-record">${media}<div class="admin-community-main"><p class="admin-community-copy">${esc(post.content)}</p><div class="admin-community-footer"><small class="muted">${post.like_count} ${post.like_count === 1 ? 'like' : 'likes'} · ${post.comment_count} ${post.comment_count === 1 ? 'comment' : 'comments'}</small><button class="btn danger sm" type="button" onclick="removePost(${post.id})">Remove Community Post</button></div></div></div></article>`;
}

async function loadAdminCommunityPosts() {
  let target = $('#admin-community-feed');
  if (!target) return;
  setSkeleton(target, skeletonList(4, 'Loading Community Posts'));
  try {
    let response = await fetch(
        new URL('api/admin-community.php', window.location.href),
        { credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    adminCommunityPostCache = result.posts || [];
    adminCommunityPage = 1;
    clearLoading(target);
    renderAdminCommunityPosts();
  } catch (error) {
    clearLoading(target);
    target.innerHTML = `<div class="panel empty">${esc(error.message || 'Unable to load Community moderation.')}</div>`;
  }
}
function renderAdminCommunityPosts() {
  let target = $('#admin-community-feed'),
    count = $('#admin-community-results-count'),
    controls = $('#admin-community-pagination');
  if (!target) return;
  let page = paginatedItems(
    adminCommunityPostCache,
    adminCommunityPage,
    adminCommunityPageSize,
  );
  adminCommunityPage = page.page;
  if (count)
    count.textContent = page.total
      ? `Showing ${page.start + 1}–${page.end} of ${page.total} Community Posts`
      : '0 Community Posts';
  target.innerHTML = page.items.length
    ? page.items.map(adminCommunityPostHtml).join('')
    : '<div class="panel empty">No published Community Posts require moderation.</div>';
  if (controls)
    controls.innerHTML = paginationMarkup(
      page.page,
      page.totalPages,
      'changeAdminCommunityPage',
      'Community moderation pages',
    );
}
function changeAdminCommunityPage(page) {
  let next = paginatedItems(
    adminCommunityPostCache,
    page,
    adminCommunityPageSize,
  );
  if (next.page === adminCommunityPage) return;
  adminCommunityPage = next.page;
  renderAdminCommunityPosts();
  paginationScroll('#admin-community-results-count');
}

async function removeDatabaseCommunityPost(postId) {
  if (
    !(await confirmAction({
      title: 'Remove this Community Post?',
      message: 'Its comments and likes will also be removed.',
      confirmLabel: 'Remove post',
      tone: 'danger',
    }))
  )
    return;
  try {
    let response = await fetch(
        new URL(
          `api/admin-community.php?id=${encodeURIComponent(postId)}`,
          window.location.href,
        ),
        { method: 'DELETE', credentials: 'same-origin' },
      ),
      result = await response.json();
    if (!response.ok || !result.success)
      return toast(result.message || 'Unable to remove the Community Post.');
    toast(result.message);
    await loadAdminCommunityPosts();
  } catch (error) {
    toast('Unable to remove the Community Post. Please try again later.');
  }
}

render();
