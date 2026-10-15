const { match, doesNotMatch } = require('./source-assert');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const community=fs.readFileSync('public/assets/js/community.js','utf8');
const notifications=fs.readFileSync('public/assets/js/notifications.js','utf8');
const app=fs.readFileSync('public/assets/js/app.js','utf8');
const index=fs.readFileSync('public/index.php','utf8');
const scheduled=[],scrolled=[],navigated=[];
let route='/notifications',missing=false;
const target={isConnected:true,innerHTML:'',removeAttribute:()=>{}};
const post={id:29,author_name:'alex',created_at:'today',content:'Example',images:[],comments:[
  {id:3,author_name:'sam',content:'Comment',created_at:'today',is_owned_by_current_user:false},
],like_count:1,comment_count:1,liked_by_current_user:false,is_owned_by_current_user:false};
const context={
  document:{addEventListener:()=>{}},render:()=>{},setInterval:()=>0,
  setTimeout:fn=>{scheduled.push(fn);return 0},
  shell:value=>value,skeletonList:()=>'<span>Loading</span>',
  $:selector=>selector==='#community-detail-post'?target:null,
  esc:value=>String(value??''),date:value=>value,
  uiIcon:()=>'<svg class="ui-icon community-heart-svg"></svg>',
  currentHashRoute:()=>route,paginationScroll:selector=>scrolled.push(selector),
  go:link=>{navigated.push(link);route=link.startsWith('#')?link.slice(1):link},
  window:{location:{href:'http://adoptapet.test/#/notifications'}},URL,
  fetch:async()=>({ok:!missing,json:async()=>missing?{success:false,message:'Community Post not found.'}:{success:true,post}}),
};
vm.createContext(context);
vm.runInContext(community,context);
vm.runInContext(notifications,context);

async function visit(type,link){
  route='/notifications';
  vm.runInContext(`notificationCache=[{id:1,notification_type:${JSON.stringify(type)},link_url:${JSON.stringify(link)},is_read:true}]`,context);
  await context.openDatabaseNotification(1,link);
  const html=context.communityPostDetail(link.split('/').pop());
  match(html,/community-detail-post/);
  await scheduled.shift()();
}

(async()=>{
  await visit('community_like','#/community/29');
  assert.equal(navigated.at(-1),'#/community/29');
  match(target.innerHTML,/id="community-post-29"/);
  assert.deepEqual(scrolled,['#community-post-29'],'Like returns to the top of the related post');

  await visit('community_comment','#/community/29');
  match(target.innerHTML,/id="community-comments-29"/);
  assert.deepEqual(scrolled,['#community-post-29','#community-comments-29'],'Comment scroll occurs after post rendering');
  await context.loadCommunityPostDetail('29');
  assert.equal(scrolled.length,2,'background or ordinary detail refresh does not scroll again');

  route='/community';
  context.openCommunityComments(29);
  assert.equal(navigated.at(-1),'/community/29','feed count opens the exact detail route');
  context.communityPostDetail('29');
  await scheduled.shift()();
  assert.deepEqual(scrolled,['#community-post-29','#community-comments-29','#community-comments-29'],'feed count scrolls after rendering once');
  await context.loadCommunityPostDetail('29');
  assert.equal(scrolled.length,3,'ordinary refresh does not repeat feed count scroll');

  missing=true;
  await visit('community_comment','#/community/29');
  match(target.innerHTML,/Community Post unavailable/);
  assert.equal(scrolled.length,3,'missing/moderated post does not scroll');
  match(notifications,/communityType==='community_comment'/);
  match(app,/r\.startsWith\('\/community\/'\).*requireUser\(\)&&communityPostDetail/);
  match(index,/community\.js\?v=25/);
  match(index,/notifications\.js\?v=18/);
  console.log('Community Like/Comment destination, one-time scroll and unavailable-state checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1});
