const { match, doesNotMatch } = require('./source-assert');
const assert=require('node:assert/strict');
const fs=require('node:fs');

const read=path=>fs.readFileSync(path,'utf8');
const home=read('public/assets/js/home.js');
const profile=read('public/assets/js/profile.js');
const account=read('public/assets/css/account.css');
const app=read('public/assets/js/app.js');
const style=read('public/assets/css/style.css');
const index=read('public/index.php');

match(home,/class="home-ux"/);
match(home,/class="hero home-hero"/);
match(home,/class="grid pets home-pets"/);
match(home,/class="three-col home-steps"/);
match(home,/class="section home-action-callout"/);
match(home,/class="section home-highlights"/);
match(home,/class="section support-callout home-support-callout"/);
match(home,/Read announcement →/);
match(home,/Open post →/);
doesNotMatch(home,/statistics|available_pets|donation_total/);

match(style,/\.home-pets \{ grid-template-columns: repeat\(3,minmax\(0,1fr\)\)/);
match(style,/@media \(max-width: 52rem\)[\s\S]*?\.home-pets \{ grid-template-columns: repeat\(2,minmax\(0,1fr\)\)/);
match(style,/@media \(max-width: 40rem\)[\s\S]*?\.home-pets \{ grid-template-columns: minmax\(0,1fr\)/);
match(style,/\.home-highlights \{[^}]*grid-template-columns: repeat\(2,minmax\(0,1fr\)\)/);
match(style,/@media \(max-width: 56rem\)[\s\S]*?\.home-highlights,\.home-steps \{ grid-template-columns: minmax\(0,1fr\)/);
match(style,/\.home-pet-card:focus-visible \{[^}]*outline: 3px solid/);
match(style,/\.public-site-shell a:hover,[\s\S]*?text-decoration: none/);

match(account,/\.account-heading \{[^}]*border-left: 3px solid #d87526/);
match(account,/\.account-heading h1 \{[^}]*font-family: Georgia,[^}]*font-size: clamp\(1\.65rem, 3vw, 2rem\)/);
match(profile,/class="activity-heading-copy"/);
match(style,/\.activity-heading-copy \{[^}]*border-left: 3px solid #d87526/);
match(style,/\.public-topbar \.nav\s*>\s*a,\s*\.public-topbar \.nav\s*>\s*button \{[^}]*display: inline-flex;[^}]*align-items: center/);
match(style,/#notification-nav-button b \{[^}]*margin-left: 0;[^}]*align-self: center/);
match(app,/Notifications\$\{unread\s*\?\s*` <b>\$\{unread\}<\/b>`\s*:\s*''\}/);
match(style,/\.public-site-shell \.floating-chat-shortcut \{[^}]*background: #df731f;[^}]*text-decoration: none/);

for(const asset of ['style.css?v=136','account.css?v=6','home.js?v=5','profile.js?v=25'])assert.ok(index.includes(asset),`${asset} is served`);

console.log('Home UX 2.0, shared heading, navbar badge alignment, Chat and cache-busting checks passed.');
