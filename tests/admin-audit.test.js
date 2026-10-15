const { match, doesNotMatch } = require('./source-assert');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const app=fs.readFileSync('public/assets/js/app.js','utf8');
const html=fs.readFileSync('public/index.php','utf8');
match(app,/\['\/admin\/audit','Audit Log'\]/);
match(app,/'\/admin\/audit':adminAuditPage/);
match(html,/assets\/js\/admin-audit\.js\?v=5/);
match(html,/assets\/css\/admin-audit\.css\?v=6/);

const nodes={
  '#admin-audit-rows':{innerHTML:'',isConnected:true},
  '#admin-audit-results':{textContent:''},
  '#admin-audit-pagination':{innerHTML:'',isConnected:true},
  '#admin-audit-module':{value:''},
};
let route='/admin/audit',response={success:true,entries:[{
  created_at:'2026-09-13 08:00:00',actor:'@admin',action:'Pet listing created',
  module:'Pet Listings',target:'Milo',target_id:'Pet #7',details:'Created pet listing Milo.',
}],page:1,total_pages:2,total:25};
let requested='',scrolled=[];
const context={
  setTimeout:()=>{},shell:markup=>markup,esc:value=>String(value??'').replace(/[&<>"']/g,char=>({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'
  }[char])),
  $:selector=>nodes[selector]||null,currentHashRoute:()=>route,
  paginationMarkup:(page,total,onChange,label)=>total<=1?'':`<nav class="catalog-pagination" aria-label="${label}"><button ${page===1?'disabled':''}>Previous</button>${Array.from({length:total},(_,index)=>`<button class="pagination-page" ${index+1===page?'aria-current="page"':''} onclick="${onChange}(${index+1})">${index+1}</button>`).join('')}<button ${page===total?'disabled':''}>Next</button></nav>`,
  paginationScroll:selector=>scrolled.push(selector),
  window:{location:{href:'http://adoptapet.test/#/admin/audit'}},URL,
  fetch:async url=>{requested=String(url);return {ok:true,json:async()=>response}},
};
vm.createContext(context);
vm.runInContext(fs.readFileSync('public/assets/js/admin-audit.js','utf8'),context);
match(context.adminAuditPage(),/Audit Log/);
match(context.adminAuditPage(),/All modules/);
doesNotMatch(context.adminAuditPage(),/<th>IP<\/th>/);
match(context.adminAuditPage(),/colspan="6"/);
doesNotMatch(context.adminAuditPage(),/Clear Logs|Delete Logs/);
match(context.adminAuditRow({...response.entries[0],actor:'<script>'}),/&lt;script&gt;/);
match(context.adminAuditRow({...response.entries[0],target:'<img src=x>',target_id:'Pet #7'}),/&lt;img src=x&gt;/);
match(context.adminAuditRow(response.entries[0]),/<small>Pet #7<\/small>/);
doesNotMatch(context.adminAuditRow({...response.entries[0],target:'Pet #7'}),/<small>/);
doesNotMatch(context.adminAuditRow({...response.entries[0],ip_address:'127.0.0.1'}),/127\.0\.0\.1/);
match(context.adminAuditRow({...response.entries[0],target:'Jalan Ampang, Kuala Lumpur',target_id:'Stray Report #38'}),/Jalan Ampang, Kuala Lumpur<small>Stray Report #38<\/small>/);
match(context.adminAuditRow({...response.entries[0],target:'<img src=x>',target_id:'Stray Report #38'}),/&lt;img src=x&gt;<small>Stray Report #38<\/small>/);

(async()=>{
  await context.adminAuditLoad(1);
  match(nodes['#admin-audit-rows'].innerHTML,/Pet listing created/);
  assert.equal(nodes['#admin-audit-results'].textContent,'Showing 1–20 of 25 recorded actions');
  match(nodes['#admin-audit-pagination'].innerHTML,/Previous[\s\S]*>1<\/[\s\S]*>2<\/[\s\S]*Next/);
  match(nodes['#admin-audit-pagination'].innerHTML,/aria-current="page"/);
  doesNotMatch(nodes['#admin-audit-pagination'].innerHTML,/Page 1 of 2/);
  response={...response,page:2,entries:[response.entries[0]]};
  await context.changeAdminAuditPage(2);
  match(requested,/page=2/);
  assert.equal(nodes['#admin-audit-results'].textContent,'Showing 21–25 of 25 recorded actions');
  assert.deepEqual(scrolled,['#admin-audit-results']);
  nodes['#admin-audit-module'].value='pet';
  response={success:true,entries:[],page:1,total_pages:1,total:0};
  await context.adminAuditLoad(1);
  match(requested,/entity=pet/);
  match(nodes['#admin-audit-rows'].innerHTML,/No Audit Log entries/);
  assert.equal(nodes['#admin-audit-results'].textContent,'0 recorded actions');
  assert.equal(nodes['#admin-audit-pagination'].innerHTML,'');
  console.log('Administrator Audit Log route, navigation, escaped UI, filters, pagination, and empty-state checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1});
