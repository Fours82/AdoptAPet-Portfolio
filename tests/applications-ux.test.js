const { match, doesNotMatch } = require('./source-assert');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const source=fs.readFileSync('public/assets/js/applications.js','utf8');
const target={innerHTML:'',className:''};
const escape=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
let record;
const context={render:()=>{},state:{role:'Registered User'},$:()=>target,window:{location:{href:'http://localhost/AdoptAPet/public/'}},URL,fetch:async()=>({ok:true,json:async()=>({success:true,application:record})}),esc:escape,date:value=>String(value).slice(0,10),petStatusLabel:value=>String(value).replace(/^./,char=>char.toUpperCase()),uiIcon:()=>'<svg></svg>',petDetailIcon:()=>'<svg></svg>'};
vm.createContext(context);
vm.runInContext(source,context);

const base={id:7,pet_name:'Milo',pet_species:'dog',pet_breed:'Golden Retriever',pet_location:'Kuala Lumpur',pet_image_path:null,reason:'A safe home for <Milo> & friends.',submitted_at:'2026-09-14 10:00:00',last_updated_at:'2026-09-14 10:00:00',status_history:[{old_status:null,new_status:'pending',changed_at:'2026-09-14 10:00:00'}]};
async function detail(status,overrides={}){record={...base,application_status:status,...overrides};await context.loadOwnApplicationDetail(7);return target.innerHTML}

(async()=>{
  let list=context.applicationCard({...base,application_status:'approved'});
  match(list,/href="#\/application\/7"/);
  match(list,/Application #7/);
  doesNotMatch(list,/documents\.php/,'certificate stays on the detail page');
  match(list,/Photo unavailable/);
  let html=await detail('pending');
  match(html,/A safe home for &lt;Milo&gt; &amp; friends/);
  doesNotMatch(html,/<Milo>/);
  match(html,/Cancel Application/);
  match(html,/Chat with Shelter/);
  doesNotMatch(html,/Last updated|Download Adoption Certificate|<h3>Applicant/);
  assert.equal((html.match(/<li>/g)||[]).length,1,'no fabricated under-review event');

  html=await detail('approved',{admin_notes:'Approved for adoption.',last_updated_at:'2026-09-16 09:00:00',status_history:[...base.status_history,{old_status:'pending',new_status:'approved',changed_at:'2026-09-16 09:00:00',notes:'Approved for adoption.'}]});
  match(html,/Download Adoption Certificate/);
  match(html,/Last updated/);
  doesNotMatch(html,/Cancel Application/);
  assert.equal((html.match(/Approved for adoption\./g)||[]).length,1,'review note shown once');

  html=await detail('rejected',{status_history:[...base.status_history,{old_status:'pending',new_status:'rejected',changed_at:'2026-09-16 09:00:00',notes:'Another application for this pet has been approved.'}]});
  match(html,/Another application for this pet has been approved/);
  doesNotMatch(html,/Cancel Application|Download Adoption Certificate/);

  html=await detail('rejected');
  match(html,/No review reason was provided/);
  html=await detail('cancelled',{status_history:[...base.status_history,{old_status:'pending',new_status:'cancelled',changed_at:'2026-09-16 09:00:00',notes:'Cancelled by applicant.'}]});
  doesNotMatch(html,/Cancel Application|Download Adoption Certificate|Chat with Shelter/);
  console.log('My Applications UX status and escaping checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1});
