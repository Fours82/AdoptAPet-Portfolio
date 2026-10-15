const { match, doesNotMatch } = require('./source-assert');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const app=fs.readFileSync('public/assets/js/app.js','utf8');
const stateSource=app.match(/let renderedRoute\s*=[\s\S]*?routeTopResetPending\s*=\s*false;/)?.[0];
const routeSource=app.slice(app.indexOf('function routePath'),app.indexOf('function passwordResetTokenFromHash'));
const completionSource=app.slice(app.indexOf('function completeRouteNavigation'),app.indexOf('let activeConfirmation'));
assert.ok(stateSource&&routeSource&&completionSource,'router scroll helpers are present');

const calls=[];
const context={
  location:{hash:'#/'},
  window:{scrollTo:options=>calls.push(options)},
  URL,
};
vm.createContext(context);
vm.runInContext(`${routeSource};${stateSource};${completionSource};this.mark=markRouteTopReset;this.complete=completeRouteNavigation;this.pending=()=>routeTopResetPending;`,context);

context.mark('#/pets');
context.location.hash='#/pets';
context.complete({newURL:'http://adoptapet.test/#/pets'});
assert.equal(calls.length,1,'normal cross-route navigation resets once');
assert.equal(calls[0].top,0);
assert.equal(calls[0].left,0);
assert.equal(calls[0].behavior,'instant');

context.mark('#/pets');
context.complete({newURL:'http://adoptapet.test/#/pets'});
assert.equal(calls.length,1,'same-route actions do not reset scroll');

context.location.hash='#/news';
context.complete({newURL:'http://adoptapet.test/#/news'});
assert.equal(calls.length,1,'Back/Forward navigation is left to native history restoration');

context.mark('#/activity');
context.location.hash='#/login';
context.complete({newURL:'http://adoptapet.test/#/activity'});
assert.equal(calls.length,1,'an intermediate guarded route does not reset prematurely');
assert.equal(context.pending(),true,'the pending reset survives a guarded redirect');
context.complete({newURL:'http://adoptapet.test/#/login'});
assert.equal(calls.length,2,'the final guarded destination resets to the top');

match(app,/event\.button===0&&!event\.metaKey&&!event\.ctrlKey&&!event\.shiftKey&&!event\.altKey/,'only ordinary primary-link activation marks navigation');
match(app,/function paginationScroll\(selector\).*window\.scrollTo\(\{top:Math\.max\(0,top\),behavior:'instant'\}\)/,'results-area pagination scrolling remains independent');
match(app,/window\.addEventListener\('hashchange',\s*\(event\)\s*=>\s*\{\s*render\(\);\s*completeRouteNavigation\(event\);\s*\}\)/,'top reset runs after route rendering');

console.log('Central route top reset, same-route, history, guarded redirect, and pagination preservation checks passed.');
