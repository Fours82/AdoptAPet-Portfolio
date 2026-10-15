const { match, doesNotMatch, extractFunction } = require('./source-assert');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const app=fs.readFileSync('public/assets/js/app.js','utf8');
const community=fs.readFileSync('public/assets/js/community.js','utf8');
const paginationSource=extractFunction(app,'paginatedItems');
assert.ok(paginationSource,'shared pagination helper is available');

const search={value:''},feed={innerHTML:''},count={textContent:''},pagination={innerHTML:''},scrolled=[];
const elements={'#community-search':search,'#community-feed':feed,'#community-results-count':count,'#community-pagination':pagination};
const context={
  document:{addEventListener:()=>{}},render:()=>{},
  cancelLiveSearch:()=>{},scheduleLiveSearch:()=>{},runLiveSearchNow:()=>{},
  $:selector=>elements[selector]||null,
  esc:value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char])),
  date:value=>value,uiIcon:()=>'<svg></svg>',state:{user:{}},
  paginationMarkup:(page,total)=>`Page ${page} of ${total}`,
  paginationScroll:selector=>scrolled.push(selector),
};
vm.createContext(context);
vm.runInContext(paginationSource,context);
vm.runInContext(community,context);
assert.equal(vm.runInContext('communityPageSize',context),6);

const posts=Array.from({length:12},(_,index)=>({
  id:index+1,author_name:'alex',created_at:'2026-09-15',updated_at:'2026-09-15',
  content:index<7?'match':'other',images:[],comments:[],like_count:0,comment_count:0,
  liked_by_current_user:false,is_owned_by_current_user:false,
}));
vm.runInContext(`communityPostCache=${JSON.stringify(posts)}`,context);

context.filterCommunityFeed();
assert.equal(count.textContent,'Showing 1–6 of 12 Community Posts');
assert.equal((feed.innerHTML.match(/class="card post community-feed-post"/g)||[]).length,6);
match(pagination.innerHTML,/Page 1 of 2/);

context.changeCommunityPage(2);
assert.equal(count.textContent,'Showing 7–12 of 12 Community Posts');
match(feed.innerHTML,/id="community-post-7"/);
doesNotMatch(feed.innerHTML,/id="community-post-6"/);
assert.deepEqual(scrolled,['#community-results-count']);
context.filterCommunityFeed(false);
assert.equal(count.textContent,'Showing 7–12 of 12 Community Posts','background refresh keeps page state');
assert.equal(scrolled.length,1,'background refresh does not scroll');

search.value='match';
context.filterCommunityFeed();
assert.equal(count.textContent,'Showing 1–6 of 7 Community Posts · 12 total');
context.changeCommunityPage(2);
assert.equal(count.textContent,'Showing 7–7 of 7 Community Posts · 12 total');
match(feed.innerHTML,/id="community-post-7"/);
context.clearCommunitySearch();
assert.equal(search.value,'');
assert.equal(count.textContent,'Showing 1–6 of 12 Community Posts');
assert.equal(scrolled.length,2,'only explicit page changes scroll');

console.log('Community six-post pagination, result counts, search, Clear and scroll checks passed.');
