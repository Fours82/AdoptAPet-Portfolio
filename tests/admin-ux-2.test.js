const { match, doesNotMatch, extractFunction } = require('./source-assert');
const assert=require('node:assert/strict');
const fs=require('node:fs');

const style=fs.readFileSync('public/assets/css/style.css','utf8');
const auth=fs.readFileSync('public/assets/css/auth.css','utf8');
const app=fs.readFileSync('public/assets/js/app.js','utf8');
const dashboards=fs.readFileSync('public/assets/js/dashboards.js','utf8');
const pets=fs.readFileSync('public/assets/js/pets.js','utf8');
const applications=fs.readFileSync('public/assets/js/applications.js','utf8');
const strays=fs.readFileSync('public/assets/js/stray-reports.js','utf8');
const users=fs.readFileSync('public/assets/js/admin-users.js','utf8');
const audit=fs.readFileSync('public/assets/js/admin-audit.js','utf8');
const news=fs.readFileSync('public/assets/js/news.js','utf8');
const community=fs.readFileSync('public/assets/js/community.js','utf8');
const chat=fs.readFileSync('public/assets/js/chat-tickets.js','utf8');
const notifications=fs.readFileSync('public/assets/js/notifications.js','utf8');
const account=fs.readFileSync('public/assets/js/account.js','utf8');
const index=fs.readFileSync('public/index.php','utf8');

for(const title of ['Administrator Dashboard','Pet Listing Management','Adoption Applications','Stray Report Management']){
  assert.ok([dashboards,pets,applications,strays].some(source=>source.includes(`<h1>${title}</h1>`)),`${title} heading remains present`);
}
for(const eyebrow of ['Administration overview','Shelter inventory','Application workflow','Rescue monitoring']){
  assert.ok([dashboards,pets,applications,strays].some(source=>source.toLowerCase().includes(eyebrow.toLowerCase())),`${eyebrow} eyebrow remains present`);
}

for(const destination of ['/admin','/admin/pets','/admin/strays','/admin/applications','/admin/users','/admin/analytics','/admin/audit','/admin/news','/admin/community','/admin/chat','/admin/notifications']){
  assert.ok(app.includes(`['${destination}'`),`${destination} stays in the role-aware Admin sidebar`);
}
const adminSidebarItems=app.match(/function sidebar\(admin=false\).*?x=admin\?\[([\s\S]*?)\]:\[/)?.[1]||'';
doesNotMatch(adminSidebarItems,/\['\/account','Account'\]|\['\/admin\/password','Change Password'\]/,'personal Account links are not duplicated in the Admin sidebar');
const adminAccountMenu=extractFunction(app,'administratorAccountControl');
match(adminAccountMenu,/state\.user\?\.name/);
match(adminAccountMenu,/state\.user\?\.email/);
match(adminAccountMenu,/<a role="menuitem" href="#\/account">Account<\/a>/);
doesNotMatch(adminAccountMenu,/Two-factor authentication|Security activity and sessions|Change Password|onclick="logout\(\)"/);
match(app,/Notifications\$\{unread\s*\?\s*` <b>\$\{unread\}<\/b>`\s*:\s*''\}/,'zero unread count does not render a badge');

match(style,/\/\* Admin UX 2\.0: compact, information-first management workspace\. \*\//);
match(style,/\.admin-workspace-shell \.admin-page-heading \.page-title h1 \{[\s\S]*font-size: clamp\(2rem,3vw,2\.45rem\)/);
match(style,/\.admin-workspace-shell :is\(\.input,textarea\)::placeholder \{[\s\S]*color: #75685f;[\s\S]*opacity: 1/);
match(style,/\.admin-workspace-shell \.badge \{[\s\S]*min-height: 1\.55rem;[\s\S]*font-size: \.69rem/);
match(style,/@media \(max-width: 48rem\) \{[\s\S]*Mobile management rows retain table semantics while reflowing visibly/);
match(style,/\.admin-workspace-shell \.admin-pet-table tbody td:nth-child\(6\)::before \{ content: "Actions"; \}/);
match(style,/\.admin-workspace-shell \.admin-application-table tbody td:nth-child\(5\)::before \{ content: "Review"; \}/);
match(style,/@media \(max-width: 30rem\) \{[\s\S]*\.admin-stray-card \{ grid-template-columns: minmax\(0,1fr\); \}/);
match(style,/\.admin-workspace-shell :is\(a,button\):hover \{ text-decoration: none; \}/);
match(app,/function beginAdminModal\(\)/);
match(app,/function handleAdminModalKeydown\(event\)/);
match(style,/body:has\(> \.modal-overlay:not\(\[hidden\]\)\)[\s\S]*overflow: hidden/);
match(style,/body:has\(\.admin-workspace-shell\)>\.confirmation-overlay/);
match(style,/body:has\(\.admin-workspace-shell\)>\.confirmation-overlay \.btn:not\(\.outline\)/);
match(style,/\.confirmation-positive \.confirmation-icon \{[^}]*background: #e1f3e8;[^}]*color: #11633f;/);
match(style,/\.confirmation-danger \.confirmation-icon \{[^}]*background: #fbe2df;[^}]*color: #a43737;/);
match(style,/\.admin-workspace-shell \.table td\[colspan\] \{\s*width: auto;\s*text-align: center;/);

match(strays,/class="panel admin-stray-card admin-stray-record" role="button" tabindex="0"/);
match(strays,/function activateAdminStrayRecord\(event,\s*id\)[\s\S]*event\.key\s*!==\s*['"]Enter['"][\s\S]*event\.key\s*!==\s*['"] ['"]/);
const strayRecord=strays.slice(strays.indexOf('function adminStrayCard'),strays.indexOf('async function loadAdminStrayReports'));
doesNotMatch(strayRecord,/<button[^>]*>View and Update<\/button>/);
match(strayRecord,/admin-record-cue/);

match(applications,/class="admin-application-record/);
match(applications,/function activateAdminApplicationRow\(event,id\)/);
const applicationRows=applications.slice(applications.indexOf('function filterAdminApplications'),applications.indexOf('function changeAdminApplicationPage'));
doesNotMatch(applicationRows,/<button[^>]*>.*(?:Review Application|View Details).*<\/button>/s);

const defaultNewsPage=news.slice(news.indexOf('function databaseAdminNewsPage'),news.indexOf('function newsEditorMarkup'));
match(defaultNewsPage,/Create Announcement/);
doesNotMatch(defaultNewsPage,/id="news-title"|id="news-body"/);
match(news,/function newsEditorMarkup\(item=null\)/);
match(news,/function openNewsEditor\(id=null\)/);
match(news,/data-admin-modal/);
match(news,/newsCoverUploadControl\(newsExistingCoverPath\)/);
match(community,/class="panel admin-community-post"/);
match(community,/admin-community-media-count/);
match(users,/data-label="User"/);
match(users,/Suspend[\s\S]*Deactivate[\s\S]*adminUserRoleActions\(user\)/);
match(users,/Promote to Admin/);
doesNotMatch(users,/Promote to Administrator<\/button>/);
match(style,/\.admin-workspace-shell \.admin-sidebar a \{[\s\S]*align-items: center;[\s\S]*justify-content: flex-start;[\s\S]*line-height: 1\.2;[\s\S]*text-align: left/);
match(style,/\.admin-workspace-shell \.admin-sidebar a::before \{ left: \.4rem; \}/);
match(style,/\.admin-workspace-shell \.admin-user-table-panel \.admin-user-actions \{[^}]*flex-wrap: nowrap/);
match(style,/@media \(max-width: 60rem\) \{[\s\S]*\.admin-workspace-shell \.admin-user-table-panel \.admin-user-actions \{ flex-wrap: wrap; \}/);
match(style,/@media \(max-width: 30rem\) \{[\s\S]*\.admin-workspace-shell \.admin-user-actions \{ display: grid; grid-template-columns: minmax\(0,1fr\); \}/);
match(style,/@media \(max-width: 60rem\) \{[\s\S]*\.admin-workspace-shell \.admin-user-table \{ min-width: 0; \}/);
match(style,/\.super-governance-panel \.admin-user-table th:nth-child\(4\) \{ width: 47%; \}/);
match(style,/:is\(#admin-user-pagination,#admin-audit-pagination\) \.catalog-pagination \{[\s\S]*margin: 0;[\s\S]*padding: 1rem \.75rem;[\s\S]*border-top:/);
match(audit,/data-label="Details"/);
match(audit,/id="admin-audit-results"[^>]*aria-live="polite"/);
match(audit,/paginationMarkup\(\s*adminAuditPageNumber,\s*pages,\s*'changeAdminAuditPage',\s*'Audit Log pages',?\s*\)/);
doesNotMatch(audit,/Page \$\{adminAuditPageNumber\} of \$\{pages\}/);
match(chat,/placeholder="Ticket ID, user, or email"/);
match(notifications,/System notifications/);
match(notifications,/admin-notifications-heading/);
match(account,/shell\([\s\S]*isAdminUiRole\(state\.role\)\)/);
match(app,/route\.startsWith\('\/account\/'\)/);

match(auth,/\.mfa-card \.code-input::placeholder \{[\s\S]*color: #75675d;[\s\S]*opacity: 1;[\s\S]*letter-spacing: \.08em/);
match(auth,/\.mfa-card \.code-input \{[\s\S]*max-width: 15rem;[\s\S]*color: #4b2f1e/);
assert.ok(index.includes('style.css?v=136'),'changed Admin stylesheet cache key is served');
assert.ok(index.includes('auth.css?v=7'),'changed MFA stylesheet cache key is served');
for(const asset of ['admin-audit.css?v=6','app.js?v=57','admin-audit.js?v=5','pets.js?v=36','stray-reports.js?v=21','applications.js?v=17','admin-users.js?v=13','community.js?v=25','notifications.js?v=18','account.js?v=6','news.js?v=13','chat-tickets.js?v=15'])assert.ok(index.includes(asset),`${asset} is served`);

console.log('Admin UX 2.0 hierarchy, contrast, responsive reflow, navigation, badge, and MFA styling checks passed.');
