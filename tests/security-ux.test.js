const { match, doesNotMatch } = require('./source-assert');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const nodes={
  '#security-events':{innerHTML:''},
  '#security-pagination':{innerHTML:''},
};
const context={
  state:{role:'Registered User'},
  isAdminUiRole:role=>role==='Administrator'||role==='Super Administrator',
  shell:markup=>markup,
  setTimeout:()=>{},
  esc:value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char])),
  $:selector=>nodes[selector]||null,
};
vm.createContext(context);
vm.runInContext(fs.readFileSync('public/assets/js/security.js','utf8'),context);

const page=context.securityPage();
match(page,/Active sessions/);
match(page,/Recent security activity/);
match(page,/Sign out all other sessions/);

const sample={id:4,device:'Chrome on Windows',ip:'192.0.2.x',created_at:'2026-09-13T01:00:00Z',last_active_at:'2026-09-13T01:01:00Z'};
const current=context.securitySessionMarkup({...sample,is_current:true});
match(current,/This device/);
doesNotMatch(current,/securitySignOutSession/);
doesNotMatch(current,/IP:|192\.0\.2\.x/);
const other=context.securitySessionMarkup({...sample,id:5,is_current:false});
match(other,/securitySignOutSession\(5,this\)/);
doesNotMatch(other,/IP:|192\.0\.2\.x/);

const events=Array.from({length:10},(_,index)=>({id:index+1,type:'login_success',occurred_at:'2026-09-13T01:00:00Z',device:'Chrome on Windows',ip:'192.0.2.x'}));
context.securityRenderEvents({events,page:2,total_pages:3});
assert.equal((nodes['#security-events'].innerHTML.match(/class="security-row"/g)||[]).length,10);
match(nodes['#security-pagination'].innerHTML,/Page 2 of 3/);
match(nodes['#security-pagination'].innerHTML,/Previous/);
match(nodes['#security-pagination'].innerHTML,/Next/);
doesNotMatch(nodes['#security-events'].innerHTML,/IP:|192\.0\.2\.x/);
context.state.role='Administrator';
match(context.securitySessionMarkup({...sample,is_current:true}),/IP: 192\.0\.2\.x/);
context.securityRenderEvents({events,page:1,total_pages:3});
match(nodes['#security-events'].innerHTML,/IP: 192\.0\.2\.x/);

console.log('Security page current-device, other-session action, and activity pagination checks passed.');
