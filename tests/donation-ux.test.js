const { match, doesNotMatch } = require('./source-assert');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const donations=fs.readFileSync('public/assets/js/donations.js','utf8');
const style=fs.readFileSync('public/assets/css/style.css','utf8');
const index=fs.readFileSync('public/index.php','utf8');
const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
const context={
  render:()=>{},
  shell:value=>value,
  setTimeout:()=>0,
  clearTimeout:()=>{},
  clearInterval:()=>{},
  setInterval:()=>0,
  state:{role:'Registered User'},
  window:{
    addEventListener:()=>{},
    crypto:{randomUUID:()=> '12345678-1234-4123-8123-123456789abc'},
    matchMedia:()=>({matches:true}),
    location:{href:'http://localhost/AdoptAPet/'},
  },
  location:{hash:'#/donations'},
  document:{body:{classList:{toggle:()=>{},contains:()=>false}},querySelectorAll:()=>[],querySelector:()=>null},
  esc,
  date:value=>String(value).slice(0,10),
  money:value=>`RM ${Number(value).toFixed(2)}`,
  uiIcon:name=>`<svg data-icon="${name}"></svg>`,
  paginatedItems:(items,currentPage,pageSize)=>{
    const totalPages=Math.max(1,Math.ceil(items.length/pageSize));
    const page=Math.min(Math.max(1,currentPage),totalPages);
    const start=items.length?(page-1)*pageSize:0;
    const end=Math.min(start+pageSize,items.length);
    return {items:items.slice(start,end),page,pageSize,totalPages,start,end,total:items.length};
  },
  paginationMarkup:(page,totalPages)=>totalPages>1?`<nav class="catalog-pagination">Page ${page} of ${totalPages}</nav>`:'',
};
vm.createContext(context);
vm.runInContext(donations,context);

doesNotMatch(donations,/pageHero\('donation-board'/);
doesNotMatch(donations,/pageHero\('donate'/);
match(context.databaseDonationsPage(),/standard-page-heading donation-page-heading/);
match(context.databaseDonationsPage(),/<h1>Donation Board<\/h1>/);
const donatePage=context.databaseDonatePage();
match(donatePage,/<h1>Make a Donation<\/h1>/);
match(donatePage,/id="donation-progress"[\s\S]*id="donation-flow"/);

const records=Array.from({length:12},(_,index)=>({
  donor_display_name:index===0?'Anonymous Supporter':`Supporter ${index+1}`,
  donated_at:`2026-09-${String(21-index).padStart(2,'0')}`,
  amount:index+1,
  private_email:`private-${index}@example.test`,
  transaction_reference:`DEV-SECRET-${index}`,
}));
const result={summary:{donation_total:78,contribution_count:12,currency:'MYR'},donations:records};
const pageOne=context.donationBoardContent(result);
match(pageOne,/Community Contributions/);
match(pageOne,/donation-total-currency">RM<\/span><span class="donation-total-value">78\.00<\/span>/);
match(pageOne,/12 completed contributions recorded/);
match(pageOne,/href="#\/donate">Make a Donation<\/a>/);
match(pageOne,/Showing 1–10 of 12 contributions/);
assert.equal((pageOne.match(/<li>/g)||[]).length,10);
match(pageOne,/Anonymous Supporter/);
doesNotMatch(pageOne,/private-0@example\.test|DEV-SECRET/);
for(const [amount,formatted] of [[0,'0.00'],[25,'25.00'],[1250.5,'1,250.50']]){
  const total=context.donationTotalMarkup(amount,'MYR');
  match(total,/donation-total-currency">RM<\/span>/);
  assert.ok(total.includes(`donation-total-value">${formatted}</span>`));
}
vm.runInContext('donationBoardPage=2',context);
const pageTwo=context.donationBoardContent(result);
match(pageTwo,/Showing 11–12 of 12 contributions/);
assert.equal((pageTwo.match(/<li>/g)||[]).length,2);

const details=context.donationDetailsMarkup();
for(const amount of ['RM10','RM25','RM50','RM100'])assert.ok(details.includes(amount));
for(const method of ['Card','Online Banking','E-Wallet'])assert.ok(details.includes(method));
match(details,/Show my donation publicly as Anonymous Supporter/);
match(context.donationProgress(1),/aria-current="step"[^>]*><span aria-hidden="true">1/);
match(context.donationProgress(1),/class="stray-form-progress donation-progress"/);
match(context.donationProgress(2),/class="is-complete"[^>]*><span aria-hidden="true"><\/span>/);
match(context.donationProgress(2),/class="is-current" aria-current="step"[^>]*><span aria-hidden="true">2/);
match(context.donationSuggestedAmounts('25.00'),/data-amount="25" aria-pressed="true"/);

const pageClasses=new Set();
const elements={
  '.donation-ux':{classList:{toggle:(name,enabled)=>enabled?pageClasses.add(name):pageClasses.delete(name),contains:name=>pageClasses.has(name)}},
  '#donate-amount':{value:'25.00',focus:()=>{}},
  '#donate-name':{value:'Taylor'},
  '#donate-anonymous':{checked:true},
  '#donation-validation':{textContent:''},
  '#donation-progress':{innerHTML:'',hidden:false},
  '#donation-flow':{innerHTML:'',scrollIntoView:()=>{}},
};
context.$=selector=>elements[selector]||null;
context.document.querySelector=selector=>selector==='input[name="demo-payment-method"]:checked'?{value:'e_wallet'}:null;
context.reviewDonation({preventDefault:()=>{}});
const reviewed=JSON.parse(vm.runInContext('JSON.stringify(donationDraft)',context));
assert.deepEqual(reviewed.paymentMethod,'e_wallet');
assert.deepEqual(reviewed.amount,'25.00');
assert.equal(reviewed.isAnonymous,true);
match(elements['#donation-flow'].innerHTML,/Review donation/);
match(elements['#donation-flow'].innerHTML,/Anonymous Supporter/);
doesNotMatch(elements['#donation-flow'].innerHTML,/Review &amp; Payment/);
match(elements['#donation-progress'].innerHTML,/Review &amp; Payment/);
assert.equal(pageClasses.has('is-payment-subflow'),false);
context.renderDemoCardProvider();
assert.equal(pageClasses.has('is-payment-subflow'),true);
match(elements['#donation-flow'].innerHTML,/Card Payment/);
doesNotMatch(elements['#donation-flow'].innerHTML,/Make a Donation|Donation progress/);
context.renderDonationReview();
assert.equal(pageClasses.has('is-payment-subflow'),false);
context.renderDemoBankSelection();
assert.equal(pageClasses.has('is-payment-subflow'),true);
match(elements['#donation-flow'].innerHTML,/Online Banking/);
doesNotMatch(elements['#donation-flow'].innerHTML,/Make a Donation|Donation progress/);
context.renderDonationReview();
assert.equal(pageClasses.has('is-payment-subflow'),false);
context.renderDemoWalletSelection();
assert.equal(pageClasses.has('is-payment-subflow'),true);
match(elements['#donation-flow'].innerHTML,/E-Wallet/);
doesNotMatch(elements['#donation-flow'].innerHTML,/Make a Donation|Donation progress/);
context.renderDonationReview();
assert.equal(pageClasses.has('is-payment-subflow'),false);
context.editDonationDetails();
match(elements['#donation-flow'].innerHTML,/value="25\.00"/);
match(elements['#donation-flow'].innerHTML,/value="Taylor"/);
match(elements['#donation-flow'].innerHTML,/id="donate-anonymous" type="checkbox" checked/);
elements['#donate-amount'].value='0.10';
elements['#donation-validation'].textContent='';
context.reviewDonation({preventDefault:()=>{}});
match(elements['#donation-validation'].textContent,/MYR 1\.00 to MYR 99,999,999\.99/);

for(const rule of [
  '.donation-board-ux,',
  '.public-site-shell .donation-public-summary',
  '.public-site-shell .donation-public-summary .donation-total-amount',
  '.contribution-section-head',
  '.public-site-shell .contribution-list li',
  '.donation-progress ol',
  '.donation-ux.is-payment-subflow .donation-page-heading',
  '.public-site-shell .donation-ux .demo-payment-option:has(input:checked)',
  '@media (max-width: 40rem)',
])assert.ok(style.includes(rule),`${rule} is styled`);
match(style,/\.contribution-section-head\s*>\s*\.pagination-summary \{[\s\S]*text-align: right;/);
match(style,/\.contribution-list li \{[\s\S]*grid-template-columns: 2\.25rem minmax\(0,1fr\) auto/);
match(style,/\.donation-total-amount \{[\s\S]*display: inline-flex;[\s\S]*align-items: baseline;[\s\S]*line-height: 1;/);
match(style,/\.donation-summary-copy \{[\s\S]*display: flex;[\s\S]*align-items: flex-start;[\s\S]*flex-direction: column;/);
match(style,/\.donation-public-summary \.eyebrow \{[\s\S]*display: block;/);
match(style,/\.stray-form-progress li \{[^}]*justify-items: center/);
match(style,/\.donation-review dd \{[^}]*overflow-wrap: anywhere/);
match(style,/\.public-site-shell a:hover,[\s\S]*text-decoration: none;/);
assert.ok(index.includes('style.css?v=136'));
assert.ok(index.includes('donations.js?v=26'));

console.log('Donation Board and two-step donation UX structure, privacy, pagination, and responsive styling checks passed.');
