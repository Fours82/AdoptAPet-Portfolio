const { match, doesNotMatch, extractFunction } = require('./source-assert');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const app=fs.readFileSync('public/assets/js/app.js','utf8');
const account=fs.readFileSync('public/assets/js/account.js','utf8');
const profile=fs.readFileSync('public/assets/js/profile.js','utf8');
const security=fs.readFileSync('public/assets/js/security.js','utf8');
const html=fs.readFileSync('public/index.php','utf8');
const styles=['style.css','auth.css','account.css'].map(file=>fs.readFileSync(`public/assets/css/${file}`,'utf8'));
match(app,/r==='\/account'\|\|r\.startsWith\('\/account\/'\)/);
match(app,/view=accountPage\(\)/);
match(app,/if\(r==='\/profile'\)\{if\(!requireUser\(\)\)return;go\('\/account\/personal'\)/);
match(app,/\['\/account','Account'\]/);
const registeredMenu=extractFunction(app,'registeredAccountControl');
match(registeredMenu,/Account.*My Applications.*My Activity.*Log out/);
doesNotMatch(registeredMenu,/Security|Change Password|#\/password|#\/account\/security/);
match(app,/function password\(\).*Back to Security/);
match(app,/class="change-password-back" href="#\/account\/security"/);
match(app,/\/password':\(\)=>requireUser\(\)&&password\(\)/);
for(const route of ['/profile','/security','/notifications','/admin/notifications','/activity','/applications','/admin/mfa']){
  assert.ok(app.includes(route),`compatibility route ${route}`);
}
match(html,/assets\/js\/account\.js\?v=6/);
match(html,/assets\/css\/account\.css\?v=6/);
match(html,/assets\/js\/profile\.js\?v=25/);
match(html,/assets\/css\/style\.css\?v=136/);
match(html,/assets\/css\/auth\.css\?v=7/);
for(const style of styles)doesNotMatch(style,/:hover[^{}]*\{[^}]*text-decoration(?:-line)?:\s*underline/);
match(styles[0],/:where\(a, button, input, select, textarea\):focus-visible/);
match(styles[2],/\.account-activity-list a:focus-visible \{ outline:/);
match(profile,/accountPersonalSaved\(authenticatedProfile\)/);
match(security,/function securitySectionContent\(\)/);
match(security,/currentHashRoute\(\)==='\/account\/security'/);

let currentRoute='/account';
const context={
  state:{role:'Registered User',user:{username:'casey'}},
  currentHashRoute:()=>currentRoute,
  shell:markup=>markup,render:()=>{},setTimeout:()=>{},
  isAdminUiRole:role=>role==='Administrator'||role==='Super Administrator',
  apiRoleToUi:role=>({registered_user:'Registered User',administrator:'Administrator',super_administrator:'Super Administrator'})[role]||null,
  accountAvatarMarkup:()=>'<span>A</span>',petStatusLabel:value=>value,
  date:()=> '14 Sep 2026',securitySectionContent:()=>'<div>SESSIONS_ACTIVITY</div>',
  imageUploadControl:()=>'<div>IMAGE_EDITOR_CONTROL</div>',
  esc:value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char])),
};
vm.createContext(context);
vm.runInContext(account,context);
const user={username:'casey',name:'Casey',email:'casey@example.test',role:'registered_user',account_status:'active',created_at:'2026-09-14',summary:{applications:2,stray_reports:1,completed_donations:3}};
const admin={...user,role:'administrator',summary:undefined};
const superAdmin={...user,role:'super_administrator',summary:undefined};
match(context.accountOverviewMarkup(user),/Applications/);
match(context.accountOverviewMarkup(user),/Completed Donations/);
doesNotMatch(context.accountOverviewMarkup(user),/account-shortcut|Personal Information · Security/);
match(context.accountOverviewMarkup(user),/href="#\/applications"/);
match(context.accountOverviewMarkup(user),/href="#\/activity"/);
for(const privileged of [admin,superAdmin]){
  const markup=context.accountOverviewMarkup(privileged);
  doesNotMatch(markup,/Applications|Completed Donations|Stray Reports/);
  match(markup,privileged.role==='administrator'?/Administrator/:/Super Administrator/);
}
const personal=context.accountPersonalView({...user,preferred_pet_type:'cat',adoption_preferences:'Quiet home'});
match(personal,/account-personal-edit/);
match(personal,/<output class="profile-email-value account-email-readonly">casey@example\.test<\/output>/);
doesNotMatch(personal,/Change Email|openEmailChangeModal/);
match(personal,/do not drive automated matching/);
match(personal,/Not added|Quiet home/);
const edit=context.accountPersonalEditForm({...user,preferred_pet_type:'',adoption_preferences:''});
match(edit,/IMAGE_EDITOR_CONTROL/);
match(edit,/saveProfile\(\)/);
match(edit,/accountCancelPersonalEdit\(\)/);
match(edit,/profile-email-value[^>]*required/);
doesNotMatch(edit,/openEmailChangeModal|Change Email<\/button>/);
match(edit,/accountEmailDraftChanged\(\)/);
const panel={innerHTML:'',querySelector:()=>({focus:()=>{}})};
context.$=selector=>selector==='#account-personal-panel'?panel:null;
context.authenticatedProfile={...user,preferred_pet_type:'',adoption_preferences:''};
context.clearEditedImageSelection=()=>{};
context.profileImageRemovalRequested=true;
context.accountStartPersonalEdit();
match(panel.innerHTML,/Save changes/);
context.accountCancelPersonalEdit();
match(panel.innerHTML,/account-personal-edit/);
assert.equal(context.profileImageRemovalRequested,false);
currentRoute='/account/security';
match(context.accountPage(),/SESSIONS_ACTIVITY/);
doesNotMatch(context.accountPage(),/Manage MFA/);
doesNotMatch(context.accountPage(),/View email settings/);
context.state.role='Administrator';
match(context.accountPage(),/Manage MFA/);
currentRoute='/account/notifications';
doesNotMatch(account,/notifications:'Notifications'/);
match(app,/r==='\/account\/notifications'/);
match(app,/go\(isAdminUiRole\(state\.role\)\?'\/admin\/notifications':'\/notifications'\)/);
context.state.role='Registered User';
doesNotMatch(account,/account-notification-summary/);
currentRoute='/account/account';
match(context.accountPage(),/Account/);
const status=context.accountStatusMarkup(user);
match(status,/Member since/);
match(status,/Sign out of your current AdoptAPet session/);
doesNotMatch(status,/account-identity|account-identity-avatar/);
doesNotMatch(account,/Connected Accounts|notification preference|self-deactivate|promote_user/);
console.log('Account Center route, role, personal view/edit, security reuse, and inbox checks passed.');
