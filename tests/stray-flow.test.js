const { match, doesNotMatch } = require('./source-assert');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'public/assets/js/stray-reports.js'), 'utf8');
const router = fs.readFileSync(path.join(root, 'public/assets/js/app.js'), 'utf8');
const index = fs.readFileSync(path.join(root, 'public/index.php'), 'utf8');
const style = fs.readFileSync(path.join(root, 'public/assets/css/style.css'), 'utf8');
const context = vm.createContext({
  petStatusLabel: value => String(value),
  window: {isSecureContext: false},
  navigator: {},
});
vm.runInContext(source, context);

assert.equal(vm.runInContext('validStrayCoordinates(3.139, 101.6869)', context), true);
assert.equal(vm.runInContext('validStrayCoordinates(91, 101.6869)', context), false);
assert.equal(vm.runInContext('validStrayCoordinates(3.139, -181)', context), false);
assert.equal(vm.runInContext('validStrayCoordinates(Infinity, 101)', context), false);
match(vm.runInContext('strayGeolocationUnavailableMessage()', context), /HTTPS or the localhost compatibility URL/);
assert.equal(vm.runInContext("strayStatusLabel('closed')", context), 'Unable to Locate');
doesNotMatch(source, /strayStatusTimeline\(report\.report_status\)/);
doesNotMatch(source, /latitude\.toFixed\(7\).*longitude\.toFixed\(7\).*gallery=/);
match(source, /validStrayCoordinates\(latitude,longitude\),gallery=/);
match(source, /expected_status:adminStrayCurrentVersion\.status/);
match(source, /expected_admin_notes:adminStrayCurrentVersion\.notes/);
match(source, /value!==['"]submitted['"]\|\|report\.report_status===['"]submitted['"]/);
match(source, /if\(strayReportSubmitting\)return/);
match(source, /imageBatchUploadControl\('stray-images','stray-image-queue','Stray sighting images',3\)/);
match(router, /r\.startsWith\('\/stray\/'\)/);
match(router, /'\/report-stray':\(\)=>requireUser\(\)/);
match(index, /stray-reports\.js\?v=21/);
match(index, /style\.css\?v=136/);
match(style, /\.stray-ux-detail \.stray-detail-main-image \{[^}]*object-fit: contain/);
match(style, /\.stray-detail-fact \.stray-detail-icon \{[^}]*width: 1\.3125rem; height: 1\.3125rem/);
doesNotMatch(style, /\.stray-rescue-updates|\.stray-rescue-note|\.stray-resolved-date|\.stray-detail-empty/);

const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));
const baseReport = {
  id:10, reference:'STR-000010', animal_type:'dog', breed:'Unknown', colour:null,
  size:'unknown', sex:'unknown', condition_category:null, urgency:'high', report_status:'submitted',
  location_text:'KL', description:'Saw an injured dog near the road.', condition_notes:'injured',
  submitted_at:'2026-09-03', updated_at:'2026-09-03', resolved_at:null,
  latitude:null, longitude:null, images:[]
};
async function renderDetail(changes = {}, role = 'Guest') {
  const target = {className:'',innerHTML:''};
  context.$ = selector => selector === '#stray-detail' ? target : null;
  context.fetch = async () => ({ok:true,json:async () => ({success:true,report:{...baseReport,...changes}})});
  context.URL = URL;
  context.window.location = {href:'http://adoptapet.test/#/stray/10'};
  context.esc = escapeHtml;
  context.uiIcon = name => `<svg class="ui-icon stray-detail-icon" data-name="${name}" aria-hidden="true"></svg>`;
  context.date = value => value;
  context.state = {role};
  context.setTimeout = () => {};
  await vm.runInContext('loadStrayReportDetail(10)', context);
  return target.innerHTML;
}

(async () => {
  const sparse = await renderDetail();
  match(sparse, /No sighting image available/);
  match(sparse, /Back to Stray Reports/);
  doesNotMatch(sparse, /Rescue updates|No rescue updates available yet|Report Another Stray|Current status|Coordinates|Not recorded|Not specified|Unknown|Additional observations|stray-detail-map/);
  assert.equal((sparse.match(/id="stray-description-heading"/g) || []).length, 1);
  doesNotMatch(sparse, / · Updated/);
  assert.equal((sparse.match(/injured/g) || []).length, 1);

  const complete = await renderDetail({
    breed:'Mixed', colour:'Brown and white', size:'medium', sex:'female', condition_category:'injured',
    condition_notes:'Limping on its front leg.', latitude:3.2185868, longitude:101.6736929,
    images:[{file_path:'one.webp'},{file_path:'two.webp'},{file_path:'three.webp'}],
    updated_at:'2026-09-04', admin_notes:'<Report owner update>'
  }, 'Registered User');
  for (const label of ['Animal type','Location','Condition','Breed','Colour','Size','Sex']) match(complete, new RegExp(label));
  match(complete, /id="stray-detail-map"/);
  assert.equal((complete.match(/stray-gallery-thumbnail /g) || []).length, 3);
  match(complete, /Limping on its front leg/);
  match(complete, /<h2 id="stray-update-heading">Rescue update<\/h2>/);
  match(complete, /&lt;Report owner update&gt;/);
  doesNotMatch(complete, /<Report owner update>|stray-rescue-updates/);
  match(complete, /Report Another Stray/);
  match(complete, / · Updated 2026-09-04/);
  doesNotMatch(complete, /3\.2185868|101\.6736929|reporter_email|reporter_name/);

  const resolved = await renderDetail({report_status:'resolved', resolved_at:'2026-09-05'});
  match(resolved, /class="badge resolved">Resolved<\/span>/);
  doesNotMatch(resolved, /Resolved on|Rescue updates|stray-status-timeline/);
  for (const [status,label] of Object.entries({submitted:'Reported',under_review:'Acknowledged',assigned:'Rescue in Progress',closed:'Unable to Locate'})) {
    const detail = await renderDetail({report_status:status, admin_notes:'Private note'});
    match(detail, new RegExp(`class="badge ${status}">${label}<\\/span>`));
    doesNotMatch(detail, /Rescue updates|Private note/);
  }

  const map = {innerHTML:''};
  context.$ = selector => selector === '#stray-detail-map' ? map : null;
  vm.runInContext('initStrayDetailMap(3.2,101.6)', context);
  match(map.innerHTML, /Map unavailable/);
  console.log('Stray detail, map, status and route checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
