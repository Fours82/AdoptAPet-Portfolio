const { match, doesNotMatch } = require('./source-assert');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const profileSource=fs.readFileSync('public/assets/js/profile.js','utf8').replace(/\brender\(\);\s*$/,'');
const accountSource=fs.readFileSync('public/assets/js/account.js','utf8').replace(/\brender\(\);\s*$/,'');
const profileApi=fs.readFileSync('public/api/profile.php','utf8');
const emailApi=fs.readFileSync('public/api/email-change.php','utf8');
const elements=new Map(),profile={username:'casey',name:'Casey',email:'old@example.test',phone:'',location:'',preferred_pet_type:'',adoption_preferences:''};
let validEmail=true,profileSuccess=true,requests=[],modalCalls=[],messages=[];
const emailField={value:profile.email,checkValidity:()=>validEmail,focused:false,focus(){this.focused=true}};
const button={textContent:'Save changes',isConnected:true};
for(const [selector,element] of [
  ['#profile-username',{value:profile.username}],['#profile-name',{value:profile.name}],
  ['#profile-phone',{value:''}],['#profile-location',{value:''}],
  ['#profile-pet-type',{value:''}],['#profile-preferences',{value:''}],
  ['#profile-email',emailField],['#profile-save',button],
  ['#profile-email-message',{textContent:''}],['#profile-username-message',{textContent:''}],
  ['#account-personal-edit',{isConnected:true}],
])elements.set(selector,element);

class TestFormData {constructor(){this.fields=new Map()}append(key,value){this.fields.set(key,value)}}
const context=vm.createContext({
  $:selector=>elements.get(selector)||null,
  window:{location:{href:'http://adoptapet.test/'},addEventListener(){}},
  document:{body:{contains:()=>true}},
  state:{role:'Registered User',user:{email:profile.email}},URL,FormData:TestFormData,
  usernamePattern:/^[A-Za-z0-9_]{3,20}$/,
  currentHashRoute:()=>'/account/personal',setTimeout:()=>{},clearInterval:()=>{},
  render(){},esc:value=>String(value),passwordInput:()=>'',
  emailInput:(_,options)=>`<input value="${options.value||''}">`,
  fetch:async (url,options)=>{
    requests.push({url:String(url),options});
    return {ok:profileSuccess,json:async()=>profileSuccess?{success:true,message:'Saved.',profile:{...profile}}:{success:false,message:'Save failed.'}};
  },
  setButtonBusy:()=>true,appendEditedImage(){},clearEditedImageSelection(){},
  toast:message=>messages.push(message),
  updateCurrentUserProfile(data){context.state.user.email=data.email},
});
vm.runInContext(profileSource,context);
vm.runInContext(accountSource,context);
vm.runInContext(`authenticatedProfile=${JSON.stringify(profile)}`,context);
context.accountPersonalSaved=()=>{};
context.openEmailChangeModal=async (returnButton,draft)=>modalCalls.push({returnButton,draft});

async function run(){
  assert.equal(vm.runInContext('accountEmailDraftChanged()',context),'');
  await vm.runInContext('saveDatabaseProfile()',context);
  assert.equal(requests.length,1);
  assert.equal(modalCalls.length,0);
  assert.equal(button.textContent,'Save changes');
  assert.equal(context.state.user.email,profile.email);
  assert.equal(JSON.parse(requests[0].options.body.fields.get('payload')).email,undefined);

  emailField.value='NEXT@example.test';
  assert.equal(vm.runInContext('accountEmailDraftChanged()',context),'next@example.test');
  assert.equal(button.textContent,'Save changes and verify email');
  match(vm.runInContext("emailChangeStepOne('next@example.test')",context),/value="next@example\.test"/);
  await vm.runInContext('saveDatabaseProfile()',context);
  assert.equal(requests.length,2);
  assert.equal(JSON.parse(requests[1].options.body.fields.get('payload')).email,undefined);
  assert.equal(modalCalls.length,1);
  assert.equal(modalCalls[0].draft,'next@example.test');
  assert.equal(context.state.user.email,profile.email);

  validEmail=false;emailField.value='not-an-email';
  await vm.runInContext('saveDatabaseProfile()',context);
  assert.equal(requests.length,2);
  assert.equal(modalCalls.length,1);
  assert.equal(emailField.focused,true);
  match(elements.get('#profile-email-message').textContent,/valid email address/);

  validEmail=true;profileSuccess=false;emailField.value='another@example.test';
  await vm.runInContext('saveDatabaseProfile()',context);
  assert.equal(requests.length,3);
  assert.equal(modalCalls.length,1);
  match(messages.at(-1),/Save failed/);

  match(profileApi,/\$allowedFields = \['username', 'name', 'phone', 'location', 'preferred_pet_type', 'adoption_preferences', 'remove_profile_image'\]/);
  match(profileApi,/Only editable profile and adoption-preference fields can be updated/);
  for(const safeguard of ['requireValidCsrfToken()','emailChangeReauthenticationIsValid($user, $currentPassword)','password_verify($code,','securityRevokeOtherSessions($pdo,','securityRecordEvent($pdo, $userId, \'email_changed\')'])assert.ok(emailApi.includes(safeguard),safeguard);
  console.log('Account Edit email draft, unchanged save, secure transition, failure handling and backend boundary checks passed.');
}
run().catch(error=>{console.error(error);process.exitCode=1});
