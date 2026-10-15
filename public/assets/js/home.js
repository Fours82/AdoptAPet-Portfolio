function databaseHomePage() {
  setTimeout(loadDatabaseHome, 0);
  let secondary =
    state.role === 'Registered User'
      ? '<a class="btn outline hero-outline" href="#/community">Visit Community</a>'
      : '<a class="btn outline hero-outline" href="#/register">Create an Account</a>';
  return shell(
    `<div class="home-ux"><section class="hero home-hero"><div class="home-hero-copy"><span class="role-chip hero-chip">Web-Based Pet Adoption System</span><h1>Every pet deserves a loving home.</h1><p>Discover rescued pets, apply to adopt, support animal welfare, and connect with people who care.</p><div class="form-actions"><a class="btn light" href="#/pets">Browse Available Pets</a>${secondary}</div></div><div class="hero-art" aria-hidden="true">🐾</div></section><div id="home-content"><section class="section"><div class="panel empty loading-state">Loading current AdoptAPet highlights…</div></section></div></div>`,
  );
}

function homePetCard(pet) {
  let image =
    pet.image_path ||
    'https://images.unsplash.com/photo-1548199973-03cce0bbc87b?auto=format&fit=crop&w=900&q=85';
  let status = pet.adoption_status || 'available';
  return `<a class="card pet-listing-card interactive-card home-pet-card" href="#/pet/${pet.id}" aria-label="View ${esc(pet.name)} Pet Details"><img class="pet-img" src="${esc(image)}" alt="${esc(pet.name)}"><div class="card-body"><div class="pet-title"><strong>${esc(pet.name)}</strong><span class="badge ${esc(status)}">${esc(petStatusLabel(status))}</span></div><p class="home-pet-kind">${esc(petStatusLabel(pet.species))} · ${esc(pet.breed || 'Breed not specified')}</p><p class="home-pet-location">${esc(pet.location || 'Location not specified')}</p><span class="home-pet-cue">View details <span aria-hidden="true">→</span></span></div></a>`;
}

function homePreviewCard(item, type) {
  if (type === 'news')
    return `<a class="preview-card preview-card-link home-news-preview ${item.cover_image_path ? 'has-cover' : 'no-cover'}" href="#/news/${item.id}">${item.cover_image_path ? `<img src="${esc(item.cover_image_path)}" alt="">` : ''}<div><small class="muted">${date(String(item.published_at).replace(' ', 'T'))}</small><h3>${esc(item.title)}</h3><p>${esc(item.body)}</p><span class="link-btn">Read announcement →</span></div></a>`;
  let content = `<small class="muted">${esc(item.author_name)} · ${date(String(item.created_at).replace(' ', 'T'))}</small><p>${esc(item.content)}</p>${state.role === 'Registered User' ? '<span class="link-btn">Open post →</span>' : ''}`;
  return state.role === 'Registered User'
    ? `<a class="preview-card preview-card-link home-community-preview" href="#/community/${item.id}">${content}</a>`
    : `<article class="preview-card home-community-preview">${content}</article>`;
}

async function loadDatabaseHome() {
  let target = $('#home-content');
  if (!target) return;
  try {
    let response = await fetch(new URL('api/home.php', window.location.href), {
        credentials: 'same-origin',
      }),
      result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.message);
    let pets = result.featured_pets || [],
      news = result.news || [],
      posts = result.community_posts || [];
    target.innerHTML = `<section class="section home-featured"><div class="section-head"><div><h2>Pets looking for a home</h2><p class="muted">Meet a few companions from our current database-backed listings.</p></div><a class="btn outline" href="#/pets">View all pets</a></div><div class="grid pets home-pets">${pets.length ? pets.map(homePetCard).join('') : '<div class="panel empty">No pets are currently available. Please check again soon.</div>'}</div></section><section class="section home-how"><div class="section-head"><div><h2>How AdoptAPet works</h2><p class="muted">A simple, caring path from discovery to adoption.</p></div></div><div class="three-col home-steps"><article class="panel"><span class="home-step-number">1</span><h3>Find a pet</h3><p>Browse verified listings and learn about each animal's story, location, and care needs.</p><a class="link-btn" href="#/pets">Browse Pet Listings</a></article><article class="panel"><span class="home-step-number">2</span><h3>Apply with care</h3><p>Create an account, submit an application, and follow updates from the shelter team.</p><a class="link-btn" href="${state.role === 'Guest' ? '#/register' : '#/applications'}">${state.role === 'Guest' ? 'Create an Account' : 'My Applications'}</a></article><article class="panel"><span class="home-step-number">3</span><h3>Support animal welfare</h3><p>Report a stray sighting, join the community, or contribute to ongoing rescue care.</p><a class="link-btn" href="#/strays">Browse Stray Reports</a></article></div></section><section class="section home-action-callout"><div><span class="eyebrow">Seen an animal that may need help?</span><h2>Share a stray sighting with the community.</h2><p>Your description and optional map location can help rescuers assess the report.</p></div><a class="btn light" href="${state.role === 'Registered User' ? '#/report-stray' : '#/register'}">${state.role === 'Registered User' ? 'Report a Stray' : 'Register to Report'}</a></section><section class="section home-highlights"><div class="home-highlight-column"><div class="section-head"><h2>Latest News</h2><a href="#/news">View all</a></div><div class="home-preview-list">${news.length ? news.map((item) => homePreviewCard(item, 'news')).join('') : '<div class="panel empty">No News announcements yet.</div>'}</div></div><div class="home-highlight-column"><div class="section-head"><h2>Community Highlights</h2>${state.role === 'Registered User' ? '<a href="#/community">Open Community</a>' : ''}</div><div class="home-preview-list">${posts.length ? posts.map((item) => homePreviewCard(item, 'community')).join('') : '<div class="panel empty">No Community Posts yet.</div>'}</div></div></section><section class="section support-callout home-support-callout"><div><h2>Help care continue</h2><p class="muted">Every completed contribution supports food, shelter, and medical care for rescued animals.</p></div><a class="btn" href="${state.role === 'Registered User' ? '#/donate' : '#/donations'}">${state.role === 'Registered User' ? 'Make a Donation' : 'View Donation Board'}</a></section>`;
  } catch (error) {
    target.innerHTML = `<section class="section"><div class="panel empty error-state"><h2>We could not load the latest highlights</h2><p>${esc(error.message || 'Please try again.')}</p><button class="btn" onclick="loadDatabaseHome()">Retry</button></div></section>`;
  }
}

render();
