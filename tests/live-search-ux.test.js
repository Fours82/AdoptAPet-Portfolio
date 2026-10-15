const { match, doesNotMatch } = require('./source-assert');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const read=file=>fs.readFileSync(file,'utf8');
const app=read('public/assets/js/app.js');
const pets=read('public/assets/js/pets.js');
const strays=read('public/assets/js/stray-reports.js');
const news=read('public/assets/js/news.js');
const community=read('public/assets/js/community.js');
const applications=read('public/assets/js/applications.js');
const chat=read('public/assets/js/chat-tickets.js');
const index=read('public/index.php');

const helperSource=app.slice(app.indexOf('const liveSearchTimers'),app.indexOf('function paginationPageNumbers('));
match(helperSource,/delay=300/);
match(helperSource,/clearTimeout\(timer\)/);
match(helperSource,/event\?\.preventDefault\(\)/);

let calls=0,prevented=0;
const context={Map,setTimeout,clearTimeout};
vm.createContext(context);
vm.runInContext(helperSource,context);
(async()=>{
  context.scheduleLiveSearch('test',()=>calls++);
  context.scheduleLiveSearch('test',()=>calls++);
  await new Promise(resolve=>setTimeout(resolve,340));
  assert.equal(calls,1,'rapid input collapses into one search');
  context.scheduleLiveSearch('enter',()=>calls++);
  context.runLiveSearchNow({key:'Enter',preventDefault:()=>prevented++},'enter',()=>calls++);
  assert.equal(calls,2,'Enter runs immediately and cancels the pending search');
  assert.equal(prevented,1);
  context.runLiveSearchNow({type:'submit',preventDefault:()=>prevented++},'submit',()=>calls++);
  assert.equal(calls,3,'form submission runs the search immediately');
  assert.equal(prevented,2,'form submission prevents native navigation');
  await new Promise(resolve=>setTimeout(resolve,320));
  assert.equal(calls,3,'the cancelled Enter timer does not run later');

match(pets,/oninput="schedulePublicPetSearch\(\)"/);
match(pets,/onsubmit="runPublicPetSearch\(event\)"/);
match(pets,/publicPetAbortController\?\.abort\(\)/);
match(pets,/criteria===publicPetAppliedCriteria\|\|criteria===publicPetRequestedCriteria/);
match(pets,/if\(publicPetHasLoaded\)results\.setAttribute\('aria-busy','true'\);else/);

for(const field of ['stray-search','stray-location'])match(strays,new RegExp(`id="${field}"[^>]*oninput="schedulePublicStraySearch\\(\\)"`));
for(const field of ['stray-animal','stray-urgency','stray-status'])match(strays,new RegExp(`id="${field}"[^>]*onchange="applyPublicStrayFilters\\(\\)"`));
match(strays,/publicStrayAbortController\?\.abort\(\)/);
match(strays,/criteria===publicStrayAppliedCriteria\|\|criteria===publicStrayRequestedCriteria/);

for(const [source,schedule,run] of [
  [news,'schedulePublicNewsSearch','runPublicNewsSearch'],
  [community,'scheduleCommunitySearch','runCommunitySearch'],
  [applications,'scheduleOwnApplicationSearch','runOwnApplicationSearch'],
  [chat,'scheduleUserTicketSearch','runUserTicketSearch'],
]){
  match(source,new RegExp(`oninput="${schedule}\\(\\)"`));
  match(source,new RegExp(`onkeydown="${run}\\(event\\)"`));
}

for(const asset of ['app.js?v=57','pets.js?v=36','stray-reports.js?v=21','applications.js?v=17','community.js?v=25','news.js?v=13','chat-tickets.js?v=15'])assert.ok(index.includes(asset),`${asset} is served`);

  console.log('Shared 300 ms live-search, Enter, cancellation, smooth loading, and asset checks passed.');
})().catch(error=>{console.error(error);process.exitCode=1});
