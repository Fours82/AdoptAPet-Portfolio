const { match, doesNotMatch, extractFunction } = require('./source-assert');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const app=fs.readFileSync('public/assets/js/app.js','utf8');
const usersSource=fs.readFileSync('public/assets/js/admin-users.js','utf8');
const helperNames=['paginationPageNumbers','paginationMarkup','paginatedItems'];
const helpers=helperNames.map(name=>extractFunction(app,name)).join('\n');
assert.ok(helperNames.every((name,index)=>helpers.includes(`function ${name}`)),`shared pagination helpers are available`);

const nodes={
  '#users-table':{innerHTML:'',isConnected:true},
  '#admin-user-pagination':{innerHTML:''},
  '#admin-user-results':{textContent:''},
  '#admin-user-clear':{hidden:true},
  '#user-search':{value:''},
  '#user-status-filter':{value:''},
};
const users=Array.from({length:15},(_,index)=>({
  id:index+1,username:`user${index+1}`,name:`User ${index+1}`,email:`user${index+1}@example.test`,
  location:'Kuala Lumpur',role:'registered_user',account_status:index<13?'active':'suspended',email_verified:true,created_at:'2026-09-22',
}));
let requested='',scrolled=[];
const context={
  state:{role:'Administrator',user:{id:99}},render:()=>{},setTimeout:()=>{},clearTimeout:()=>{},shell:markup=>markup,
  skeletonRows:()=>'',setSkeleton:()=>{},clearLoading:()=>{},
  esc:value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char])),
  date:value=>value,petStatusLabel:value=>value,
  $:selector=>nodes[selector]||null,window:{location:{href:'http://adoptapet.test/#/admin/users'}},URL,
  paginationScroll:selector=>scrolled.push(selector),
  fetch:async url=>{
    requested=String(url);
    const parsed=new URL(requested),query=(parsed.searchParams.get('q')||'').toLowerCase(),status=parsed.searchParams.get('status')||'';
    const filtered=users.filter(user=>(!status||user.account_status===status)&&(!query||`${user.username} ${user.name} ${user.email}`.toLowerCase().includes(query)));
    return {ok:true,json:async()=>({success:true,users:filtered})};
  },
};
vm.createContext(context);
vm.runInContext(helpers,context);
vm.runInContext(usersSource,context);

(async()=>{
  await context.loadAdminUsers();
  assert.equal(nodes['#admin-user-results'].textContent,'Showing 1–10 of 15 matching Registered Users');
  match(nodes['#admin-user-pagination'].innerHTML,/Previous[\s\S]*>1<\/[\s\S]*>2<\/[\s\S]*Next/);
  match(nodes['#admin-user-pagination'].innerHTML,/aria-current="page"/);
  context.changeAdminUserPage(2);
  assert.equal(nodes['#admin-user-results'].textContent,'Showing 11–15 of 15 matching Registered Users');
  assert.deepEqual(scrolled,['#admin-user-results']);

  nodes['#user-search'].value='user1';
  nodes['#user-status-filter'].value='active';
  await context.loadAdminUsers();
  match(requested,/q=user1/);
  match(requested,/status=active/);
  assert.equal(nodes['#admin-user-results'].textContent,'Showing 1–5 of 5 matching Registered Users');
  assert.equal(nodes['#admin-user-pagination'].innerHTML,'');
  assert.equal(nodes['#admin-user-clear'].hidden,false);
  console.log('Shared Admin numeric pagination, User Management paging, search, filter, reset, and metadata checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1});
