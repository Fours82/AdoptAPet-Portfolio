const { match, doesNotMatch } = require('./source-assert');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const pets=fs.readFileSync('public/assets/js/pets.js','utf8');
const app=fs.readFileSync('public/assets/js/app.js','utf8');
const notifications=fs.readFileSync('public/assets/js/notifications.js','utf8');
const style=fs.readFileSync('public/assets/css/style.css','utf8');
const index=fs.readFileSync('public/index.php','utf8');

doesNotMatch(pets,/pageHero\('pets'/);
match(pets,/class="standard-page-heading"><h1>Pet Listings<\/h1>/);
doesNotMatch(pets,/Find your new best friend/i);
match(pets,/id="mobile-pet-filter-toggle"[^>]*aria-expanded="false"/);
for(const id of ['species','breed','sex','location','max-age-months','availability'])match(pets,new RegExp(`id="${id}"`));
match(pets,/values\.status!==['"]available['"]\?1:0/);
match(pets,/badge\.textContent=count\?`\(\$\{count\}\)`:''/);
match(pets,/href="#\/pet\/\$\{pet\.id\}"/);
match(pets,/\$\{esc\(pet\.breed\s*\|\|\s*'Breed not specified'\)\} · \$\{esc\(petStatusLabel\(pet\.species\)\)\}/);
for(const icon of ["'calendar'","'pin'"])assert.ok(pets.includes(`petDetailIcon(${icon})`));
match(pets,/class="pet-listing-cue">View details/);
doesNotMatch(pets,/class="btn sm card-action"[^>]*>View Details/);
match(pets,/oninput="schedulePublicPetSearch\(\)"/);
match(pets,/<form class="catalog-search-row" onsubmit="runPublicPetSearch\(event\)">/);
match(pets,/<button type="submit" class="btn catalog-search-button listing-primary-action">/);
match(pets,/function filterPets\(\)\s*\{\s*loadPublicPets\(1\);\s*\}/);
match(pets,/new AbortController\(\)/);
match(pets,/page_size:8/);
match(pets,/searchParams\.set\('limit','8'\)/);
match(pets,/skeletonCards\(8,'Loading Pet Listings'\)/);
match(pets,/if\(publicPetHasLoaded\)results\.setAttribute\('aria-busy','true'\);else/);
match(pets,/field\.dataset\.optionsLoaded='true'/);
match(pets,/some\(\s*\(?id\)?\s*=>\s*\$\(`#\$\{id\}`\)\?\.dataset\.optionsLoaded\s*!==\s*'true'[\s\S]*updatePublicPetOptions/);

match(style,/\.pet-catalogue \.pets \{ grid-template-columns: repeat\(4,minmax\(0,1fr\)\)/);
match(style,/@media \(max-width: 63rem\)[\s\S]*repeat\(3,minmax\(0,1fr\)\)/);
match(style,/@media \(max-width: 47rem\)[\s\S]*repeat\(2,minmax\(0,1fr\)\)/);
match(style,/@media \(max-width: 40rem\)[\s\S]*\.pet-catalogue \.pets \{ grid-template-columns: minmax\(0,1fr\)/);
match(style,/\.pet-catalogue \.pet-listing-card \.pet-img \{[\s\S]*aspect-ratio: 1\.45;[\s\S]*object-fit: cover;/);
match(style,/\.public-site-shell a:hover,[\s\S]*text-decoration: none;/);
match(style,/\.pet-listing-card:focus-visible \{[^}]*outline: 3px solid rgba\(216,117,38,\.35\)/);

const navSource=app.slice(app.indexOf('function notificationNavButton('),app.indexOf('function header(',app.indexOf('function notificationNavButton(')));
for(const [route,count,active] of [['/notifications',3,true],['/notifications',0,true],['/pets',3,false]]){
  const context={currentHashRoute:()=>route};
  vm.createContext(context);
  vm.runInContext(navSource,context);
  const markup=context.notificationNavButton('/notifications',count);
  assert.equal(markup.includes('class="active"'),active);
  assert.equal(markup.includes('aria-current="page"'),active);
  assert.equal(markup.includes('<b>'),count>0);
  if(count)assert.ok(markup.includes(`<b>${count}</b>`));
}
match(notifications,/button\.innerHTML=`Notifications/);
const notificationHeaderUpdate=notifications.slice(notifications.indexOf('function updateNotificationHeader('),notifications.indexOf('async function refreshNotificationCount('));
doesNotMatch(notificationHeaderUpdate,/button\.className|classList\.(?:add|remove|toggle)/);
match(style,/\.floating-chat-shortcut \{ position: fixed;/);
doesNotMatch(style,/:has\(\.pet-catalogue\) \.floating-chat-shortcut/);
match(app,/function floatingChatShortcut\(\)[^\n]*href="#\/chat"/);
doesNotMatch(style,/unread-placeholder/);
for(const asset of ['style.css?v=136','app.js?v=57','pets.js?v=36','notifications.js?v=18'])assert.ok(index.includes(asset),`${asset} is served`);
match(app,/DOMContentLoaded',restoreAuthentication,\{once:true\}/,'authentication waits until route scripts are loaded');

console.log('Pet Listings UX, responsive catalogue, hover/focus, navigation state, and asset checks passed.');
