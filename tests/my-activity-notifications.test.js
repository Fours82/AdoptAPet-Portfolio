const { match, doesNotMatch } = require('./source-assert');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const app=fs.readFileSync('public/assets/js/app.js','utf8');
const applications=fs.readFileSync('public/assets/js/applications.js','utf8');
const profile=fs.readFileSync('public/assets/js/profile.js','utf8');
const notifications=fs.readFileSync('public/assets/js/notifications.js','utf8');
const account=fs.readFileSync('public/assets/js/account.js','utf8');
const index=fs.readFileSync('public/index.php','utf8');

for(const route of ['/applications','/application/','/activity','/notifications','/admin/notifications']){
  assert.ok(app.includes(route)||account.includes(route),`route ${route} is represented`);
}
match(account,/route\.startsWith\('\/account\/'\)/);
doesNotMatch(account,/notifications:'Notifications'/);
match(app,/r==='\/account\/notifications'/);
match(applications,/application\.application_status==='approved'/);
match(applications,/application\.status_history\|\|\[\]/);
match(applications,/event\.new_status==='rejected'\|\|event\.new_status==='approved'/);
match(applications,/api\/documents\.php\?type=adoption_certificate/);
match(applications,/paginationMarkup\(page\.page,page\.totalPages,'changeOwnApplicationPage'/);
match(applications,/No applications yet/);
match(applications,/Unable to load applications/);
match(profile,/history\.donations\|\|\[\]/);
match(profile,/history\.stray_reports\|\|\[\]/);
match(profile,/strayStatusLabel\(report\.report_status\)/);
match(profile,/href="#\/stray\/\$\{report\.id\}"/);
match(profile,/paginationMarkup\(strays\.page,strays\.totalPages/);
match(profile,/View your donation history and submitted stray reports\./);
doesNotMatch(profile,/pageHero\('activity'/);
const activityContext={window:{addEventListener:()=>{}},render:()=>{},setTimeout:()=>{},shell:html=>html,skeletonList:()=>'',esc:value=>String(value??''),date:value=>value,petStatusLabel:value=>value,strayStatusLabel:value=>value,strayUrgencyLabel:value=>value,strayReference:id=>`STR-${id}`,paginationMarkup:(page,total)=>`${page}/${total}`,paginatedItems:(items,page,size)=>({items:items.slice((page-1)*size,page*size),page,totalPages:Math.max(1,Math.ceil(items.length/size)),total:items.length,start:(page-1)*size,end:Math.min(page*size,items.length)})};
vm.createContext(activityContext);
vm.runInContext(profile,activityContext);
const donationMarkup=activityContext.profileDonationHistory([{id:7,currency:'MYR',amount:50,donation_status:'completed',transaction_reference:'DEV-SECRET',donation_date:'2026-09-12'}]);
match(donationMarkup,/<th>Amount<\/th><th>Date<\/th><th>Status<\/th><th>Document<\/th>/);
doesNotMatch(donationMarkup,/DEV-SECRET|<th>Reference<\/th>/);
match(donationMarkup,/api\/documents\.php\?type=donation_receipt&amp;id=7/);
assert.equal(activityContext.meaningfulStrayUpdate({submitted_at:'2026-09-14 08:00:00',updated_at:'2026-09-14 08:00:00'}),'—');
assert.equal(activityContext.meaningfulStrayUpdate({submitted_at:'2026-09-14 08:00:00',updated_at:'2026-09-15 09:00:00'}),'2026-09-15 09:00:00');
assert.equal(activityContext.meaningfulStrayUpdate({submitted_at:'2026-09-14 08:00:00',updated_at:'2026-09-14 09:00:00'}),'2026-09-14 09:00:00');
const strayMarkup=activityContext.profileStrayHistory([{id:44,animal_type:'other',location_text:'KL',urgency:'high',report_status:'acknowledged',submitted_at:'2026-09-14 08:00:00',updated_at:'2026-09-14 08:00:00'}]);
match(strayMarkup,/data-label="Latest update">—<\/td>/);
match(strayMarkup,/href="#\/stray\/44"/);
match(strayMarkup,/<th><span class="sr-only">Open report<\/span><\/th>/);
match(strayMarkup,/<a class="table-action" href="#\/stray\/44" aria-label="View Stray Report STR-44"><span aria-hidden="true">›<\/span><\/a>/);
doesNotMatch(strayMarkup,/table-action-icon|<span>View<\/span>/);
match(strayMarkup,/<tr tabindex="0" aria-label="Open Stray Report STR-44" onclick="openActivityStrayRow\(event\)" onkeydown="openActivityStrayRow\(event\)"/);
doesNotMatch(strayMarkup,/openNavigableCard|role="link"/);
let navigations=0,prevented=0;
const viewLink={click:()=>navigations++};
const row={querySelector:selector=>selector==='a.table-action'?viewLink:null,closest:()=>null};
const cell={closest:()=>null};
const linkTarget={closest:selector=>selector.includes('a')?viewLink:null};
activityContext.openActivityStrayRow({type:'click',currentTarget:row,target:cell});
assert.equal(navigations,1,'row click uses the existing View link');
activityContext.openActivityStrayRow({type:'click',currentTarget:row,target:linkTarget});
assert.equal(navigations,1,'View link click is not duplicated');
for(const key of ['Enter',' '])activityContext.openActivityStrayRow({type:'keydown',key,currentTarget:row,target:row,preventDefault:()=>prevented++});
assert.equal(navigations,3,'Enter and Space activate the row');
assert.equal(prevented,2);
activityContext.openActivityStrayRow({type:'keydown',key:'Enter',currentTarget:row,target:linkTarget,preventDefault:()=>prevented++});
activityContext.openActivityStrayRow({type:'keydown',key:'ArrowDown',currentTarget:row,target:row,preventDefault:()=>prevented++});
assert.equal(navigations,3,'bubbled link keys and other keys do not activate the row');
const sixteenDonations=Array.from({length:16},(_,index)=>({id:index+1,currency:'MYR',amount:25,donation_status:'completed',transaction_reference:`DEV-${index+1}`,donation_date:'2026-09-12'}));
const activityPageOne=activityContext.profileActivityContent({history:{donations:sixteenDonations,stray_reports:[]}});
match(activityPageOne,/Showing 1–10 of 16/);
match(activityPageOne,/1\/2/);
assert.equal((activityPageOne.match(/type=donation_receipt/g)||[]).length,10);
vm.runInContext('activityPages.donations=2',activityContext);
const activityPageTwo=activityContext.profileActivityContent({history:{donations:sixteenDonations,stray_reports:[]}});
match(activityPageTwo,/Showing 11–16 of 16/);
assert.equal((activityPageTwo.match(/type=donation_receipt/g)||[]).length,6);
doesNotMatch(account,/account-notification-summary/);
match(notifications,/api\/notifications\.php\?summary=1/);
match(notifications,/loadDatabaseNotifications\(true\)/);
match(notifications,/renderDatabaseNotifications\(currentNotificationFilter\(\),!background\)/);
match(notifications,/requestVersion!==notificationRequestVersion/);
match(notifications,/notificationRequestVersion\+\+/);
match(notifications,/state\.notificationUnread=Number\(result\.unread_count\)\|\|0/);
match(index,/notifications\.js\?v=18/);
match(index,/profile\.js\?v=25/);

const elements={
  '#notification-list':{innerHTML:''},
  '#notification-results-count':{textContent:''},
  '#notification-mark-all':{disabled:true},
  '#notification-pagination':{innerHTML:''},
  '.notification-filter-tabs .active':{dataset:{filter:'all'}},
};
const context={
  render:()=>{},setInterval:()=>0,$:selector=>elements[selector]||null,
  state:{notificationUnread:2,role:'Registered User'},
  uiIcon:()=>'',petStatusLabel:value=>value,date:value=>value,
  esc:value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char])),
  paginatedItems:(items,page,size)=>{
    const totalPages=Math.max(1,Math.ceil(items.length/size));
    const current=Math.min(Math.max(1,page),totalPages),start=(current-1)*size,end=Math.min(items.length,start+size);
    return {items:items.slice(start,end),page:current,totalPages,total:items.length,start,end};
  },
  paginationMarkup:(page,totalPages)=>`${page}/${totalPages}`,
  paginationScroll:()=>{},
};
vm.createContext(context);
vm.runInContext(notifications,context);
for(const good of ['#/application/31','#/stray/42','#/community','#/news/7','#/chat/4','#/admin/chat/5']){
  assert.equal(context.safeNotificationLink(good),good);
}
for(const bad of ['https://example.test','//example.test','#/news/7?next=https://example.test',"#/application/1'",'javascript:alert(1)']){
  assert.equal(context.safeNotificationLink(bad),null);
}

const inbox=Array.from({length:15},(_,index)=>({
  id:15-index,notification_type:'application_status',message:`Notice ${15-index}`,
  link_url:`#/application/${15-index}`,is_read:index>1,created_at:`2026-09-14 10:${String(59-index).padStart(2,'0')}:00`,
}));
context.inbox=inbox;
vm.runInContext('notificationCache=inbox;notificationPage=1',context);
context.renderDatabaseNotifications('all');
assert.equal((elements['#notification-list'].innerHTML.match(/class="notification notification-card/g)||[]).length,10);
assert.equal(elements['#notification-pagination'].innerHTML,'1/2');
context.changeNotificationPage(2);
assert.equal((elements['#notification-list'].innerHTML.match(/class="notification notification-card/g)||[]).length,5);
assert.equal(elements['#notification-pagination'].innerHTML,'2/2');
context.renderDatabaseNotifications('all',false);
assert.equal(elements['#notification-pagination'].innerHTML,'2/2','background rendering preserves the page');
context.renderDatabaseNotifications('unread');
assert.equal((elements['#notification-list'].innerHTML.match(/class="notification notification-card/g)||[]).length,2);
assert.equal(elements['#notification-mark-all'].disabled,false);
const known=context.databaseNotificationHtml({id:1,notification_type:'stray_report_status',message:'Report received.',link_url:'#/stray/44',is_read:false,created_at:'2026-09-14'});
match(known,/notification-stray unread/);
match(known,/Stray Report/);
match(known,/notification-arrow/);
doesNotMatch(known,/· Unread/);
const unknown=context.databaseNotificationHtml({id:2,notification_type:'legacy_custom',message:'Existing notice.',link_url:'https://invalid.test',is_read:true,created_at:'2026-09-14'});
match(unknown,/notification-general/);
match(unknown,/General/);
doesNotMatch(unknown,/notification-arrow/);

console.log('My Applications, My Activity and Notifications JavaScript checks passed.');
