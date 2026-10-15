const { match, doesNotMatch } = require('./source-assert');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
const pets = fs.readFileSync(path.join(root, 'public/assets/js/pets.js'), 'utf8');
const style = fs.readFileSync(path.join(root, 'public/assets/css/style.css'), 'utf8');
const index = fs.readFileSync(path.join(root, 'public/index.php'), 'utf8');
const detailSource = pets.slice(pets.indexOf('function petDetail('), pets.indexOf('function adminPets('));
assert.ok(detailSource.includes('async function loadPetDetail('));
doesNotMatch(detailSource, /original_filename/);
match(index, /style\.css\?v=136/);
match(index, /pets\.js\?v=36/);
match(style, /pet-theme-background\.png/);
match(style, /\.pet-detail-image-frame \.detail-image \{[^}]*object-fit: contain/);
match(style, /\.pet-detail-image-frame \.detail-image \{[^}]*position: absolute/);
match(style, /\.pet-detail-fact-icon \.ui-icon \{[^}]*width: 1\.3125rem; height: 1\.3125rem; stroke-width: 1\.8/);
match(style, /\.pet-detail-section h2 \.pet-detail-icon \{[^}]*width: 1\.625rem; height: 1\.625rem;[^}]*stroke-width: 1\.8/);
match(style, /\.pet-detail-fact-icon \.ui-icon \{ width: 1\.1875rem; height: 1\.1875rem; \}/);
match(style, /\.pet-detail-actions \.btn \.ui-icon \{ width: 1\.1875rem; height: 1\.1875rem; color: currentColor; stroke-width: 1\.8; \}/);
match(style, /\.pet-detail-actions \.btn \.ui-icon \{ width: 1\.125rem; height: 1\.125rem; \}/);

const basePet = {
  id: 12, name: 'Milo', species: 'dog', breed: 'Mixed', sex: 'male',
  age_label: '2 years', location: 'Kuala Lumpur', intake_date: '2026-04-15',
  adoption_status: 'available', story: 'A calm pet.', image_path: '',
  images: [
    {file_path: 'uploads/pets/one.webp', original_filename: 'private-name-one.jpg'},
    {file_path: 'uploads/pets/two.webp', original_filename: 'private-name-two.jpg'}
  ],
  medical_records: [
    {record_type: 'treatment', title: 'Checkup', details: 'Doing well.', record_date: '2026-04-16', next_due_date: null},
    {record_type: 'vaccination', title: 'Rabies', details: 'Vaccinated.', record_date: '2026-04-18', next_due_date: '2027-04-18'}
  ]
};
const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));

async function render(role, changes = {}, failure = false) {
  const target = {className:'',innerHTML:''};
  const pet = {...basePet, ...changes};
  const context = {
    $: selector => selector === '#pet-detail' ? target : null,
    fetch: async () => ({ok: !failure, json: async () => failure ? {success:false,message:'Not found'} : {success:true,pet}}),
    URL,
    window: {location:{href:'http://adoptapet.test/#/pet/12'}},
    state: {role},
    esc: escapeHtml,
    uiIcon: name => `<svg class="ui-icon" data-name="${name}" aria-hidden="true"></svg>`,
    date: value => value,
    petImage: item => item.image_path || 'fallback-image',
    petStatusLabel: value => String(value || '').replace(/(^|_)([a-z])/g, (_, separator, letter) => (separator ? ' ' : '') + letter.toUpperCase()),
    isAdminUiRole: value => value === 'Administrator' || value === 'Super Administrator'
  };
  vm.runInNewContext(detailSource, context);
  await context.loadPetDetail(12);
  return target.innerHTML;
}

(async () => {
  const guest = await render('Guest');
  match(guest, /Back to Pet Listings/);
  match(guest, /Log in to apply/);
  match(guest, /href="#\/login"/);
  match(guest, /class="btn pet-apply-button"[^>]*><svg[^>]*aria-hidden="true"[^>]*>.*<\/svg>Log in to apply<\/a>/);
  match(guest, /class="btn outline" href="#\/login"><svg[^>]*aria-hidden="true"[^>]*>.*<\/svg>Log in to chat<\/a>/);
  match(guest, /Photos of Milo/);
  match(guest, /Show photo 2 of Milo/);
  match(guest, /About Milo/);
  match(guest, /Health &amp; medical/);
  match(guest, /data-name="pin"/);
  match(guest, /data-name="calendar"/);
  assert.equal((guest.match(/class="pet-detail-fact"/g) || []).length, 4);
  match(guest, /id="pet-health-heading"><svg[^>]*aria-hidden="true"/);
  match(guest, /id="pet-vaccination-heading"><svg[^>]*aria-hidden="true"/);
  match(guest, /class="pet-detail-content-main">[\s\S]*id="pet-health-heading"/);
  match(guest, /class="pet-detail-content-aside">[\s\S]*id="pet-vaccination-heading"/);
  match(guest, /class="pet-detail-section pet-detail-adoption-info"/);
  assert.ok(guest.indexOf('id="pet-health-heading"') < guest.indexOf('id="pet-vaccination-heading"'));
  assert.ok(guest.indexOf('id="pet-vaccination-heading"') < guest.indexOf('id="pet-intake-heading"'));
  assert.ok(guest.indexOf('id="pet-intake-heading"') < guest.indexOf('id="pet-adoption-heading"'));
  match(guest, /Rabies/);
  match(guest, /Next due 2027-04-18/);
  doesNotMatch(guest, /private-name/);

  const user = await render('Registered User');
  match(user, /Apply for Adoption/);
  match(user, /go\('\/apply\/12'\)/);
  match(user, /Chat with Shelter/);
  match(user, /class="btn pet-apply-button"[^>]*><svg[^>]*aria-hidden="true"[^>]*>.*<\/svg>Apply for Adoption<\/button>/);
  match(user, /class="btn outline" href="#\/chat"><svg[^>]*aria-hidden="true"[^>]*>.*<\/svg>Chat with Shelter<\/a>/);

  for (const role of ['Administrator', 'Super Administrator']) {
    const admin = await render(role);
    doesNotMatch(admin, /Apply for Adoption|Log in to apply/);
    match(admin, /Admin Chat/);
  }

  for (const status of ['pending', 'adopted', 'inactive']) {
    const unavailable = await render('Registered User', {adoption_status:status});
    match(unavailable, /not accepting new applications/);
    doesNotMatch(unavailable, /Apply for Adoption/);
  }

  const empty = await render('Guest', {images:[],medical_records:[],story:'',image_path:''});
  match(empty, /src="fallback-image"/);
  match(empty, /Illustrative pet image; no photo available/);
  match(empty, /Illustrative image — no photo of this pet is available/);
  match(empty, /No story has been added yet/);
  match(empty, /No public medical or treatment records have been added/);
  match(empty, /No vaccination records have been added/);

  const onePhoto = await render('Guest', {images:[{file_path:'uploads/pets/one.webp'}]});
  match(onePhoto, /src="uploads\/pets\/one.webp"/);
  doesNotMatch(onePhoto, /pet-gallery-thumbnails|no photo of this pet/);

  const escaped = await render('Guest', {name:'<Milo>',story:'<script>alert(1)</script>'});
  doesNotMatch(escaped, /<script>/);
  match(escaped, /&lt;Milo&gt;/);

  const missing = await render('Guest', {}, true);
  match(missing, /Pet Listing unavailable/);
  match(missing, /Browse Available Pets/);

  console.log('Pet Detail UX checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
