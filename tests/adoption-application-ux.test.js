const { match, doesNotMatch } = require('./source-assert');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const applications=fs.readFileSync('public/assets/js/applications.js','utf8');
const style=fs.readFileSync('public/assets/css/style.css','utf8');
const source=applications.slice(0,applications.indexOf('function applications()'));
const target={className:'panel',innerHTML:'',removeAttribute:()=>{}};
const reason={value:'',attributes:{},focused:false,setAttribute(name,value){this.attributes[name]=value},removeAttribute(name){delete this.attributes[name]},getAttribute(name){return this.attributes[name]??null},focus(){this.focused=true}};
const validation={textContent:''};
const submit={isConnected:true};
const notices=[],routes=[],busyStates=[],requests=[];
const pet={id:24,name:'Mimi',species:'cat',breed:'Persian',sex:'female',age_label:'5 months',adoption_status:'available',image_path:'uploads/pets/mimi.jpg'};
let response={ok:true,json:async()=>({success:true,pet})};
const escape=value=>String(value??'').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
const elements={'#application-form':target,'#app-reason':reason,'#application-reason-validation':validation,'#application-submit':submit};
const context={
  state:{role:'Registered User'},$:selector=>elements[selector]||null,shell:value=>value,skeletonList:()=>'<span>Loading</span>',
  fetch:async(url,options={})=>{requests.push({url:String(url),options});return response},window:{location:{href:'http://adoptapet.test/'}},URL,
  esc:escape,petStatusLabel:value=>String(value).replace(/^./,letter=>letter.toUpperCase()),uiIcon:name=>`<svg data-icon="${name}"></svg>`,
  clearLoading:()=>{},toast:message=>notices.push(message),go:route=>routes.push(route),setButtonBusy:(_button,state)=>busyStates.push(state),setTimeout:()=>0,
};
vm.createContext(context);
vm.runInContext(source,context);

(async()=>{
  await context.loadApplicationForm(24);
  const html=target.innerHTML;
  doesNotMatch(html,/page-hero|New beginnings for brighter days/);
  match(html,/class="standard-page-heading"><h1>Submit Adoption Application<\/h1>/);
  match(html,/Applying to adopt Mimi\. Please tell us about the home and care you can provide\./);
  match(html,/class="application-apply-layout"/);
  match(html,/src="uploads\/pets\/mimi\.jpg" alt="Mimi, pet selected for this application"/);
  for(const value of ['Mimi','Cat','Persian','5 months','Female','Available'])assert.ok(html.includes(value),`${value} is shown in the pet summary`);
  match(html,/for="app-reason">Why would you like to adopt Mimi\?/);
  match(html,/id="app-reason"[^>]*required[^>]*minlength="15"[^>]*maxlength="65535"[^>]*aria-describedby="application-reason-help application-reason-validation"/);
  match(html,/id="application-reason-validation"[^>]*role="alert"[^>]*aria-live="polite"/);
  match(html,/>Cancel<\/button><button[^>]*id="application-submit"[^>]*>Submit Application<\/button>/);
  match(html,/onclick="go\('\/pet\/24'\)"/);

  reason.value='too short';
  const requestCount=requests.length;
  await context.submitApplication(24);
  assert.equal(requests.length,requestCount,'invalid reason does not reach the API');
  assert.equal(reason.attributes['aria-invalid'],'true');
  assert.equal(reason.focused,true);
  match(validation.textContent,/at least 15 characters/);

  reason.value='We can provide a safe home and lifelong veterinary care.';
  response={ok:true,json:async()=>({success:true,message:'Application submitted.'})};
  await context.submitApplication(24);
  const submission=requests.at(-1);
  match(submission.url,/api\/adoption-applications\.php$/);
  assert.equal(submission.options.method,'POST');
  assert.equal(submission.options.credentials,'same-origin');
  assert.deepEqual(JSON.parse(submission.options.body),{pet_id:24,reason:reason.value});
  assert.equal(validation.textContent,'');
  assert.deepEqual(routes,['/applications']);
  assert.deepEqual(busyStates,[true,false]);

  response={ok:true,json:async()=>({success:true,pet:{id:24,name:'Mimi',adoption_status:'available',image_path:'',breed:'',species:'',age_label:'',sex:''}})};
  await context.loadApplicationForm(24);
  match(target.innerHTML,/class="application-apply-image application-apply-image-fallback" role="img" aria-label="Photo unavailable"/);
  match(target.innerHTML,/Applying to adopt Mimi\./);
  doesNotMatch(target.innerHTML,/<dt>(Species|Breed|Age|Sex)<\/dt>|undefined/);
  match(target.innerHTML,/<dt>Status<\/dt><dd>Available<\/dd>/);

  match(style,/\.application-apply-layout \{[^}]*grid-template-columns: minmax\(17rem,\.72fr\) minmax\(0,1\.28fr\)/);
  match(style,/@media \(max-width: 48rem\) \{[\s\S]*\.application-apply-layout \{ grid-template-columns: minmax\(0,1fr\)/);
  match(style,/@media \(max-width: 40rem\) \{[\s\S]*\.application-apply-actions \{ display: grid; grid-template-columns: minmax\(0,1fr\)/);
  match(style,/\.application-reason-field textarea:focus-visible \{[^}]*outline: 3px solid/);
  console.log('Submit Adoption Application UX and payload checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1});
