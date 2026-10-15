const { match, doesNotMatch } = require('./source-assert');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const source=fs.readFileSync('public/assets/js/notifications.js','utf8');
match(source,/const notificationPageSize=10/);
match(source,/skeletonList\(10,'Loading Notifications'\)/);
const navigated=[],scrolled=[],requests=[];
const elements={
  '#notification-list':{innerHTML:'',isConnected:true,removeAttribute:()=>{}},
  '#notification-results-count':{textContent:''},
  '#notification-mark-all':{disabled:false},
  '#notification-pagination':{innerHTML:''},
  '#notification-nav-button':{innerHTML:'',className:'active'},
  '.notification-filter-tabs .active':{dataset:{filter:'all'}},
};
const context={
  render:()=>{},setInterval:()=>0,$:selector=>elements[selector]||null,
  state:{role:'Registered User',notificationUnread:2},
  isAdminUiRole:()=>false,uiIcon:()=>'',date:value=>value,
  esc:value=>String(value??''),window:{location:{href:'http://adoptapet.test/#/notifications'}},URL,
  go:link=>navigated.push(link),toast:()=>{},
  paginationScroll:selector=>scrolled.push(selector),
  paginatedItems:(items,page,size)=>{
    const totalPages=Math.max(1,Math.ceil(items.length/size));
    const current=Math.min(Math.max(1,page),totalPages),start=(current-1)*size,end=Math.min(items.length,start+size);
    return {items:items.slice(start,end),page:current,totalPages,total:items.length,start,end};
  },
  paginationMarkup:(page,total)=>`${page}/${total}`,
  fetch:async(_url,options={})=>{
    requests.push(options);
    if(options.method==='PATCH')return {ok:true,json:async()=>({success:true,unread_count:options.body.includes('mark_all')?0:1,message:'Read'})};
    return {ok:true,json:async()=>({success:true,unread_count:context.state.notificationUnread,notifications:context.items})};
  },
};
vm.createContext(context);
vm.runInContext(source,context);
context.items=Array.from({length:15},(_,i)=>({id:i+1,notification_type:'application_status',message:`Notice ${i+1}`,link_url:`#/application/${i+1}`,is_read:i>1,created_at:'2026-09-14'}));
vm.runInContext('notificationCache=items;notificationPage=2',context);

(async()=>{
  context.renderDatabaseNotifications('all',false);
  assert.equal(elements['#notification-pagination'].innerHTML,'2/2');
  await context.loadDatabaseNotifications(true);
  assert.equal(elements['#notification-pagination'].innerHTML,'2/2','background refresh keeps the current page');
  assert.equal(scrolled.length,0,'background refresh does not scroll');
  assert.equal(requests[0].method,undefined,'background refresh only reads');

  vm.runInContext('notificationPage=1',context);
  await context.openDatabaseNotification(1,'#/application/1');
  assert.equal(requests[1].method,'PATCH','opening an unread notification marks exactly one read');
  assert.equal(JSON.parse(requests[1].body).id,1);
  assert.equal(context.state.notificationUnread,1);
  match(elements['#notification-nav-button'].innerHTML,/<b>1<\/b>/);
  assert.equal(elements['#notification-nav-button'].className,'active','unread-count updates preserve the active route class');
  assert.deepEqual(navigated,['#/application/1'],'exact stored destination is retained');
  assert.equal(scrolled.length,0);

  await context.readAllDatabaseNotifications();
  assert.equal(JSON.parse(requests[2].body).mark_all,true);
  assert.equal(context.state.notificationUnread,0);
  assert.equal(elements['#notification-mark-all'].disabled,true);
  assert.equal(elements['#notification-nav-button'].innerHTML,'Notifications');
  assert.equal(elements['#notification-nav-button'].className,'active','clearing the count preserves the active route class');
  assert.equal(context.items.every(item=>item.is_read),true);
  let release;
  const stale=JSON.parse(JSON.stringify(context.items));
  stale[0].is_read=false;
  context.fetch=async(_url,options={})=>options.method==='PATCH'
    ? {ok:true,json:async()=>({success:true,unread_count:0,message:'Read'})}
    : new Promise(resolve=>{release=()=>resolve({ok:true,json:async()=>({success:true,unread_count:1,notifications:stale})})});
  const pending=context.loadDatabaseNotifications(true);
  await context.readAllDatabaseNotifications();
  release();
  await pending;
  assert.equal(context.state.notificationUnread,0,'stale refresh cannot restore old count');
  assert.equal(context.items.every(item=>item.is_read),true,'stale refresh cannot revert newer read state');
  assert.equal(scrolled.length,0);
  console.log('Notifications page, read-one, mark-all, navbar count and background-refresh state checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1});
