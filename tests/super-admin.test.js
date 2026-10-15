const { match, doesNotMatch, extractFunction } = require('./source-assert');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const app=fs.readFileSync('public/assets/js/app.js','utf8');
const html=fs.readFileSync('public/index.php','utf8');
match(app,/function isAdminUiRole\(role\)/);
match(app,/function requireAdmin\(\)\{if\(!isAdminUiRole\(state.role\)\)/);
match(app,/apiRoleToUi\(result.user\?\.role\)/);
match(html,/assets\/js\/app\.js\?v=57/);
match(html,/assets\/js\/admin-users\.js\?v=13/);

const roleContext={};
vm.createContext(roleContext);
vm.runInContext(['isAdminUiRole','isAdminApiRole','apiRoleToUi','accountDisplayName'].map(name=>extractFunction(app,name)).join('\n'),roleContext);
assert.equal(roleContext.apiRoleToUi('registered_user'),'Registered User');
assert.equal(roleContext.apiRoleToUi('administrator'),'Administrator');
assert.equal(roleContext.apiRoleToUi('super_administrator'),'Super Administrator');
assert.equal(roleContext.accountDisplayName({role:'super_administrator',name:'Amina',username:'amina'}),'Amina');
for(const unknown of ['unexpected_role','toString','__proto__',null]){
  assert.equal(roleContext.apiRoleToUi(unknown),null);
  assert.equal(roleContext.isAdminApiRole(unknown),false);
  assert.equal(roleContext.isAdminUiRole(unknown),false);
}

const context={
  state:{role:'Administrator',user:{id:1}},
  render:()=>{},setTimeout:()=>{},shell:markup=>markup,
  skeletonRows:()=>'',
  esc:value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char])),
};
vm.createContext(context);
vm.runInContext(fs.readFileSync('public/assets/js/admin-users.js','utf8'),context);
const registered={id:2,username:'casey',role:'registered_user',account_status:'active',email_verified:true};
assert.equal(context.adminUserRoleActions(registered),'');
match(context.databaseAdminUsersPage(),/super-governance-panel[^>]*hidden/);

context.state.role='Super Administrator';
match(context.adminUserRoleActions(registered),/Promote to Admin/);
match(context.adminUserActions(registered),/Suspend[\s\S]*Deactivate[\s\S]*Promote to Admin/);
match(context.adminUserActions({...registered,account_status:'suspended'}),/Reactivate[\s\S]*Deactivate/);
match(context.databaseAdminUsersPage(),/Administrator roles/);
doesNotMatch(context.databaseAdminUsersPage(),/super-governance-panel[^>]*hidden/);
const activeAdministratorActions=context.privilegedUserActions({id:2,role:'administrator',account_status:'active',has_mfa:true,email_verified:true},1);
match(activeAdministratorActions,/Demote to Registered User[\s\S]*Deactivate[\s\S]*Promote to Super Administrator/);
match(activeAdministratorActions,/admin-warning-action[\s\S]*Demote to Registered User/);
match(activeAdministratorActions,/danger[\s\S]*Deactivate/);
match(activeAdministratorActions,/admin-positive-action[\s\S]*Promote to Super Administrator/);
match(context.privilegedUserActions({id:2,role:'administrator',account_status:'active',has_mfa:false,email_verified:false},1),/Demote to Registered User/);
doesNotMatch(context.privilegedUserActions({id:2,role:'administrator',account_status:'active',has_mfa:false,email_verified:true},1),/Promote to Super Administrator/);
doesNotMatch(context.privilegedUserActions({id:2,role:'administrator',account_status:'active',has_mfa:false,email_verified:false},1),/Promote to Super Administrator/);
match(context.privilegedUserActions({id:2,role:'super_administrator',account_status:'active'},1),/Last active Super Administrator/);
match(context.privilegedUserActions({id:2,role:'super_administrator',account_status:'active'},2),/Demote to Administrator/);
match(context.privilegedUserActions({id:1,role:'super_administrator',account_status:'active'},2),/Current account/);
match(context.privilegedUserActions({id:2,role:'administrator',account_status:'deactivated'},1),/Reactivate/);
assert.equal(context.privilegedRoleLabel('toString'),'Unknown role');
doesNotMatch(fs.readFileSync('public/assets/js/admin-users.js','utf8'),/\b(?:alert|confirm|prompt)\s*\(/);

console.log('Super Administrator role mapping, workspace gate, and restrained governance UI checks passed.');
