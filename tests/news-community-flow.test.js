const { match, doesNotMatch } = require('./source-assert');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const read=name=>fs.readFileSync(`public/assets/js/${name}.js`,'utf8');
const app=read('app'),news=read('news'),community=read('community');
const account=read('account'),pets=read('pets'),security=read('security');
const notifications=read('notifications'),index=fs.readFileSync('public/index.php','utf8');
const style=fs.readFileSync('public/assets/css/style.css','utf8');
const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
const context={
  render:()=>{},shell:value=>value,setTimeout:()=>0,document:{addEventListener:()=>{}},
  esc,date:value=>value,skeletonList:()=>'<span>Loading</span>',
  pageHero:()=>'',uiIcon:(_name,className='')=>`<svg class="ui-icon ${className}"></svg>`,
  state:{user:{profile_image_path:'uploads/profile/current.webp'}},
};
vm.createContext(context);
vm.runInContext(news,context);
vm.runInContext(community,context);

const article={id:17,title:'<unsafe>',body:'One\n<script>Two</script>',cover_image_path:null,published_at:'2026-09-14',author_name:'Shelter'};
const card=context.publicNewsHtml([article]);
match(card,/href="#\/news\/17"/);
match(card,/news-card-placeholder/);
doesNotMatch(card,/<script>/);
match(card,/&lt;unsafe&gt;/);
match(context.publicNewsHtml([]),/No News announcements yet/);
match(context.databaseNewsDetailPage(17),/Loading News announcement/);

const post={id:29,author_name:'alex',created_at:'2026-09-14',updated_at:'2026-09-14',content:'<img src=x>',images:[{file_path:'uploads/community/a.webp'}],comments:[],like_count:0,comment_count:0,liked_by_current_user:false,is_owned_by_current_user:false};
const postHtml=context.communityPostHtml(post);
match(postHtml,/href="#\/community\/29"/);
match(postHtml,/uploads\/community\/a.webp/);
doesNotMatch(postHtml,/<img src=x>/);
match(postHtml,/&lt;img src=x&gt;/);
match(context.communityPostDetail('29'),/id="community-detail-post"/);
match(context.communityPostDetail('bad'),/Community Post unavailable/);
match(context.communityGallery({...post,images:[],image_path:null}),/^$/);
const textOnly=context.communityPostHtml({...post,images:[],image_path:null,content:'Text only'});
match(textOnly,/Text only/);
doesNotMatch(textOnly,/community-gallery|No image|placeholder/);
for(let count=1;count<=4;count++){
  const gallery=context.communityGallery({...post,images:Array.from({length:count},(_,i)=>({file_path:`image-${i+1}.webp`}))});
  match(gallery,new RegExp(`community-gallery--${count}`));
  assert.equal((gallery.match(/<img /g)||[]).length,count);
}
doesNotMatch(postHtml,/View post|community-comments-29|comment-29" class="input"/);
match(postHtml,/onclick="openCommunityComments\(29\)"/);
match(postHtml,/onclick="likePost\(29,false\)"/);
match(postHtml,/community-comment-icon/);
match(postHtml,/community-heart-svg/);
match(context.communityPostHtml({...post,is_owned_by_current_user:true}),/uploads\/profile\/current.webp/);
doesNotMatch(postHtml,/uploads\/profile\/current.webp/);
match(postHtml,/class="avatar" aria-hidden="true"><span>A<\/span><\/div>/);
const detailHtml=context.communityDetailPostHtml({...post,comments:[{id:3,author_name:'sam',content:'Hello',created_at:'today',is_owned_by_current_user:false}]});
match(detailHtml,/id="community-comments-29"/);
match(detailHtml,/id="comment-29"/);
match(detailHtml,/Hello/);
match(detailHtml,/community-comment-icon/);
match(detailHtml,/community-comment-main/);
match(context.communityCommentHtml(29,{id:3,author_name:'alex',content:'One\nTwo',created_at:'today',is_owned_by_current_user:true}),/uploads\/profile\/current.webp/);
match(context.communityCommentHtml(29,{id:4,author_name:'sam',content:'One\nTwo',created_at:'today',is_owned_by_current_user:false}),/community-comment-text">One\nTwo<\/span>/);
match(style,/\.community-feed-post \.community-gallery \{[^}]*aspect-ratio: 4 \/ 3/);
match(style,/\.community-ux:not\(\.community-detail\) \{ max-width: 77\.5rem; \}/);
match(style,/\.news-ux :is\(\.news-search,#news-results-count,#news-feed,#news-pagination\) \{ width: min\(100%,72rem\); margin-inline: auto; \}/);
match(style,/\.community-ux:not\(\.community-detail\) :is\(\.community-search,#community-results-count,\.community-feed,#community-pagination\) \{ width: min\(100%,64rem\); margin-inline: auto; \}/);
match(style,/\.community-search-row \{ display: grid; grid-template-columns: minmax\(0,1fr\) auto auto/);
match(community,/community-search-row[^`]*community-create-action[^`]*openCommunityComposer\(\)/);
match(style,/\.community-ux \.community-feed \{[^}]*grid-template-columns: minmax\(0,1fr\)/);
match(style,/\.community-feed-post \.community-post-body\s*>\s*p \{ max-width: 48rem; \}/);
const listingMetaStyle=style.slice(style.indexOf('/* Shared public listing metadata'));
for(const selector of ['#results-count','#stray-count','#news-results-count','#community-results-count','#notification-results-count','#donation-contributions .pagination-summary'])assert.ok(listingMetaStyle.includes(selector),`${selector} uses the shared listing metadata rule`);
for(const declaration of ['font-family: var(--font-sans)','font-size: .9rem','font-weight: 700','color: var(--color-slate-950)','margin-block: .9rem .7rem','text-align: left'])assert.ok(listingMetaStyle.includes(declaration),`${declaration} is standardized`);
match(style,/\.community-ux \.community-like-action\.is-liked \.community-heart-svg \{ fill: #b64040/);
const likeAttributes={},likeClasses=new Set(),likeCopy={textContent:''};
const likeButton={classList:{toggle:(name,enabled)=>enabled?likeClasses.add(name):likeClasses.delete(name)},setAttribute:(name,value)=>{likeAttributes[name]=value},querySelector:selector=>selector==='.community-like-label'?likeCopy:null};
context.$=selector=>selector==='#community-like-29'?likeButton:null;
vm.runInContext(`communityPostCache=[${JSON.stringify(post)}]`,context);
context.updateCommunityLikeState(29,true,3);
assert.ok(likeClasses.has('is-liked'));
assert.equal(likeAttributes['aria-pressed'],'true');
assert.equal(likeCopy.textContent,'3 likes');
assert.equal(vm.runInContext('communityPostCache[0].like_count',context),3);
context.updateCommunityLikeState(29,false,2);
assert.ok(!likeClasses.has('is-liked'));
assert.equal(likeAttributes['aria-pressed'],'false');
assert.equal(likeCopy.textContent,'2 likes');
match(context.communityCommentHtml(29,{id:3,author_name:'sam',content:'Safe',created_at:'today',is_owned_by_current_user:true}),/Delete/);
doesNotMatch(context.communityCommentHtml(29,{id:3,author_name:'sam',content:'Safe',created_at:'today',is_owned_by_current_user:false}),/>Delete</);

match(app,/r\.startsWith\('\/community\/'\).*requireUser\(\)&&communityPostDetail/);
match(app,/r\.startsWith\('\/news\/'\).*databaseNewsDetailPage/);
match(community,/api\/community\.php.*post_id/);
match(community,/currentHashRoute\(\)\.startsWith\('\/community\/'\)\)go\('\/community'\)/);
match(community,/updateCommunityLikeState\(postId,Boolean\(result\.liked\),result\.like_count\)/);
const likeHandler=community.slice(community.indexOf('async function toggleDatabaseCommunityLike'),community.indexOf('function updateCommunityLikeState'));
doesNotMatch(likeHandler,/loadCommunityFeed\(/);
match(notifications,/function safeNotificationLink\(link\)/); // exact detail links are exercised in the inbox regression test
match(app,/r==='\/account\/notifications'/);
match(app,/go\(isAdminUiRole\(state\.role\)\?'\/admin\/notifications':'\/notifications'\)/);
doesNotMatch(account,/notifications:'Notifications'/);
doesNotMatch(account,/account-notification-summary/);

match(app,/function paginationScroll\(selector\)/);
match(app,/window\.scrollTo\(\{top:Math\.max\(0,top\),behavior:'instant'\}\)/);
match(pets,/loadPublicPets\(target,true\)/);
match(pets,/if\(scrollAfter&&results\?\.isConnected\)paginationScroll\('#results-count'\)/);
match(security,/securityLoadActivity\(\$\{securityActivityPage\+1\},true\)/);
match(security,/if\(scrollAfter\)paginationScroll\('#security-events-heading'\)/);
for(const file of ['news','community','stray-reports','applications','profile','notifications','donations']){
  match(read(file),/paginationScroll\(/,`${file} has user-triggered pagination return`);
}
for(const asset of ['app.js?v=57','community.js?v=25','style.css?v=136','notifications.js?v=18','account.js?v=6','pets.js?v=36','security.js?v=6']){
  assert.ok(index.includes(asset),`${asset} cache version is served`);
}

console.log('News and Community route/render/privacy, pagination and Account Center checks passed.');
