const { match, doesNotMatch } = require('./source-assert');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const read=path=>fs.readFileSync(path,'utf8');
const chat=read('public/assets/js/chat-tickets.js');
const style=read('public/assets/css/style.css');
const index=read('public/index.php');

// The Registered User view uses the shared unboxed page heading and keeps every existing action.
match(chat,/class="live-chat-ux"/);
match(chat,/class="section-head page-title live-chat-page-header"/);
match(chat,/>\+ New Conversation<\/button>/);
match(chat,/onclick="closeUserTicket\(\)"/);
match(chat,/onclick="sendChat\(false\)"/);
match(chat,/Only one ticket can remain open at a time/);
doesNotMatch(chat,/subject\.select\(\)/);
match(chat,/subject\.setSelectionRange\(end,end\)/);
match(chat,/modal\.hidden=true;\$\('\.live-chat-new-action'\)\?\.focus\(\)/);

// Search, empty, open, and closed states remain explicit and data-driven.
match(chat,/oninput="scheduleUserTicketSearch\(\)"/);
assert.ok(chat.includes('oninput="filterTicketHistory(${admin})"'));
match(chat,/No support tickets yet\. Start a new conversation when you need help\./);
match(chat,/No conversation selected\./);
match(chat,/This ticket is closed — read-only history\./);
match(chat,/ticketStatus\(conversation\.status\)/);

// Polling stays at five seconds, while background rendering preserves a reader's scroll position.
match(chat,/setInterval\(\(\)\s*=>\s*\{\s*if\s*\(location\.hash\.startsWith\('#\/chat'\)\)\s*loadUserTickets\(true\);\s*\},\s*5000\)/);
match(chat,/wasNearLatest/);
match(chat,/if\s*\(!background\s*\|\|\s*wasNearLatest\)\s*target\.scrollTop\s*=\s*target\.scrollHeight;\s*else\s*target\.scrollTop\s*=\s*previousScrollTop/);
match(chat,/filterTicketHistory\(false,background\)/);

// The workspace is a 30/70 desktop split, stacks at tablet width, and avoids dashed empty boxes.
match(style,/\.live-chat-ux \.user-chat-workspace \{[\s\S]*?grid-template-columns: minmax\(17rem,3fr\) minmax\(0,7fr\)/);
match(style,/@media \(max-width: 56rem\) \{[\s\S]*?\.live-chat-ux \.user-chat-workspace \{ grid-template-columns: minmax\(0,1fr\)/);
match(style,/@media \(max-width: 40rem\) \{[\s\S]*?\.live-chat-ux \.user-chat-composer-row \{ grid-template-columns: minmax\(0,1fr\)/);
match(style,/\.live-chat-ux \.empty \{[\s\S]*?border: 0;[\s\S]*?background: transparent/);
match(style,/\.live-chat-page-header::before \{[\s\S]*?background: #d87526/);
match(style,/\.live-chat-page-header h1 \{[\s\S]*?font-family: Georgia,[\s\S]*?font-size: clamp\(2rem,3\.6vw,3\.1rem\)[\s\S]*?font-weight: 700/);
match(style,/\.live-chat-ux \.chat-ticket-row:hover,[\s\S]*?text-decoration: none/);
match(style,/\.live-chat-ux\s*>\s*\.modal-overlay \{ background: rgba\(61,\s*48,\s*39,\s*\.42\); \}/);

const modal={hidden:true};
let subjectFocusCount=0,triggerFocusCount=0,selection=null;
const subject={value:'',focus:()=>subjectFocusCount++,setSelectionRange:(start,end)=>{selection=[start,end]}};
const trigger={focus:()=>triggerFocusCount++};
const modalContext={$:selector=>selector==='#new-ticket-modal'?modal:selector==='#new-ticket-subject'?subject:selector==='.live-chat-new-action'?trigger:null};
vm.createContext(modalContext);
vm.runInContext(chat.slice(chat.indexOf('function createUserTicket'),chat.indexOf('async function submitUserTicket')),modalContext);
modalContext.createUserTicket();
assert.equal(modal.hidden,false);
assert.equal(subject.value,'Shelter Support');
assert.equal(subjectFocusCount,1);
assert.deepEqual(selection,[subject.value.length,subject.value.length]);
modalContext.closeUserTicketModal();
assert.equal(modal.hidden,true);
assert.equal(triggerFocusCount,1);
modalContext.closeUserTicketModal();
assert.equal(triggerFocusCount,1,'closing an already hidden modal does not steal focus');

for(const asset of ['style.css?v=136','chat-tickets.js?v=15'])assert.ok(index.includes(asset),`${asset} is served`);

console.log('Live Chat UX 2.0 structure, states, polling-scroll preservation, responsive layout, and cache-busting checks passed.');
