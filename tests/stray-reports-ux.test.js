const { match, doesNotMatch } = require('./source-assert');
const assert = require('node:assert/strict');
const fs = require('node:fs');

const source = fs.readFileSync('public/assets/js/stray-reports.js', 'utf8');
const pets = fs.readFileSync('public/assets/js/pets.js', 'utf8');
const news = fs.readFileSync('public/assets/js/news.js', 'utf8');
const community = fs.readFileSync('public/assets/js/community.js', 'utf8');
const style = fs.readFileSync('public/assets/css/style.css', 'utf8');
const index = fs.readFileSync('public/index.php', 'utf8');

// Catalogue structure and permissions.
match(source, /<header class="stray-page-heading"><h1>Stray Reports<\/h1>/);
doesNotMatch(source, /pageHero\('strays'/);
match(source, /state\.role\s*===\s*'Registered User'\s*\?\s*'<a class="btn stray-report-action" href="#\/report-stray">\+ Report a Stray<\/a>'/);
match(source, /state\.role\s*===\s*'Guest'\s*\?\s*'<a class="btn stray-report-action" href="#\/login">Log in to Report<\/a>'/);
match(source, /id="stray-advanced-filters" class="stray-advanced-filters" hidden/);
match(source, /function publicStrayActiveFilterCount\(\)/);
match(source, /function clearStrayFilters\(\).*\['stray-search','stray-location','stray-animal','stray-urgency','stray-status'\]/);
match(source, /return `<a class="stray-report-card" href="#\/stray\/\$\{report\.id\}"/);
match(source, /View details →/);

// Listing discovery controls share typography and the same outlined Filters component.
for (const listing of [pets, source, news, community]) match(listing, /listing-search-ui/);
match(pets, /mobile-pet-filter-toggle listing-filter-toggle/);
match(source, /listing-filter-toggle stray-filter-toggle/);
match(pets, /mobile-pet-filter-count listing-filter-count/);
match(source, /listing-filter-count stray-filter-count/);
match(source, /badge\.textContent=count\?`\(\$\{count\}\)`:''/);
doesNotMatch(source, /stray-filter-icon|stray-filter-chevron|>⌄</);
match(style, /\.public-site-shell \.listing-search-ui \.field\s*>\s*label,[\s\S]*?font-size: 1rem; font-weight: var\(--weight-bold\)/);
match(style, /\.public-site-shell \.listing-search-ui :is\(\.input,select\) \{[^}]*min-height: var\(--listing-control-height\)[^}]*font-size: var\(--listing-control-font-size\)/);
match(style, /\.public-site-shell \.listing-filter-toggle \{[^}]*border: 1px solid var\(--color-border-strong\)[^}]*border-radius: var\(--listing-control-radius\)/);
match(style, /\.public-site-shell \.listing-filter-toggle:hover \{[^}]*background: var\(--color-primary-100\)[^}]*text-decoration: none/);
match(style, /\.public-site-shell \.listing-filter-toggle:focus-visible \{[^}]*outline: 3px solid var\(--color-focus\)/);
match(style, /\.public-site-shell \.listing-search-ui \.listing-filter-toggle\[aria-expanded="true"\]/);
match(style, /\.public-site-shell :is\(\.standard-page-heading,\.stray-page-heading,\.news-heading\) \{[^}]*border-left: 3px solid #d87526/);
match(style, /\.public-site-shell :is\(\.standard-page-heading,\.stray-page-heading,\.news-heading\) h1,[\s\S]*?font-family: Georgia,[^}]*font-size: clamp\(2rem,3\.6vw,3\.1rem\)[^}]*font-weight: 700/);
match(style, /\.public-site-shell :is\(\.community-heading,\.notifications-heading\)\s*>\s*div:first-child \{[^}]*padding-left: 1\.2rem;[^}]*border-left: 3px solid #d87526/);
match(style, /\.public-site-shell :is\(\.news-search,\.community-search\) \{[^}]*padding: 1rem 1\.1rem;[^}]*border: 1px solid var\(--color-border\)/);
match(style, /\.public-site-shell \.news-ux,[\s\S]*?\.community-ux:not\(\.community-detail\) \{ max-width: 77\.5rem; \}/);
match(style, /\.pet-catalogue \.pet-title \{[^}]*font-size: 1rem;[^}]*font-weight: 700/);
match(style, /\.stray-card-heading strong \{[^}]*font-size: 1rem;[^}]*font-weight: 700/);
match(style, /:has\(:is\(\.news-ux,\.news-detail-ux,\.community-ux,\.notifications-ux\)\) \.floating-chat-shortcut \{ display: inline-flex; \}/);

// The report form remains one form and one final submit, with client-side steps.
for (const step of [1, 2, 3]) match(source, new RegExp(`data-stray-step="${step}"`));
assert.equal((source.match(/id="stray-submit"/g) || []).length, 1);
match(source, /function setStrayReportStep\(step\)/);
match(source, /if\(next===2\)setTimeout\(initStrayPickerMap,0\)/);
match(source, /function nextStrayReportStep\(\).*validateStrayReportStep\(strayReportFormStep\)/);
match(source, /function previousStrayReportStep\(\)/);
match(source, /function ensureStrayFinalCancel\(\)/);
match(source, /minlength="10" required/);
match(source, /id="report-description-count"[^>]*>0 \/ 5000/);
match(source, /id="report-condition-count"[^>]*>0 \/ 3000/);
match(source, /if\s*\(strayReportFormStep\s*<\s*3\)\s*\{\s*nextStrayReportStep\(\);\s*return;\s*\}/);
match(source, /appendEditedImageBatch\(form,'stray-images'\)/);

// Responsive catalogue/form rules and a shared, accessible Chat treatment.
match(style, /\.stray-report-grid \{[^}]*grid-template-columns: repeat\(3,minmax\(0,1fr\)\)/);
match(style, /@media \(max-width: 64rem\)[\s\S]*?\.stray-report-grid \{ grid-template-columns: repeat\(2,minmax\(0,1fr\)\)/);
match(style, /@media \(max-width: 42rem\)[\s\S]*?\.stray-discovery \.stray-search-row,\.stray-filter-grid,\.stray-report-grid \{ grid-template-columns: minmax\(0,1fr\)/);
match(style, /\.public-site-shell \.stray-report-card:focus-visible \{[^}]*outline: 3px solid/);
match(style, /\.public-site-shell \.floating-chat-shortcut \{[^}]*background: #df731f;[^}]*color: #fff;[^}]*text-decoration: none/);
match(style, /\.public-site-shell \.floating-chat-shortcut:hover,[\s\S]*?background: #c86218;[^}]*color: #fff;[^}]*text-decoration: none/);
match(style, /\.public-site-shell \.floating-chat-shortcut:focus-visible \{[^}]*outline: 3px solid/);
doesNotMatch(style, /:has\(\.stray-(?:catalogue|form)-ux\) \.floating-chat-shortcut/);
match(index, /style\.css\?v=136/);
match(index, /stray-reports\.js\?v=21/);

console.log('Stray Reports UX 2.0 structure, wizard, responsive layout and shared Chat checks passed.');
