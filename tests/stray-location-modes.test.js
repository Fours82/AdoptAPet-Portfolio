const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.join(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'public/assets/js/stray-reports.js'), 'utf8');
const proxy = fs.readFileSync(path.join(root, 'public/api/stray-geocode.php'), 'utf8');
const style = fs.readFileSync(path.join(root, 'public/assets/css/style.css'), 'utf8');

function response(status, payload) {
  return {
    ok: status >= 200 && status < 300,
    status,
    async json() { return payload; },
  };
}

function makeHarness(fetchHandler = async () => response(500, {success:false})) {
  const requests = [];
  const fields = {
    '#report-location': {value:'', focus(){}, checkValidity(){return this.value.trim() !== '';}, reportValidity(){}},
    '#report-latitude': {value:''},
    '#report-longitude': {value:''},
    '#stray-picker-map': {innerHTML:''},
    '#stray-map-status': {textContent:'', classList:{toggle(){}}},
    '#stray-geocode-feedback': {textContent:'', classList:{toggle(){}}},
    '#stray-geocode-results': {innerHTML:'', children:[]},
    '#use-current-location': {disabled:false, textContent:'Use My Current Location'},
    '#search-stray-location': {disabled:false, textContent:'Search Location'},
    '#stray-form-validation': {textContent:''},
    '#report-animal': {value:'dog'},
    '#report-breed': {value:''},
    '#report-colour': {value:'Brown'},
    '#report-size': {value:'medium'},
    '#report-sex': {value:'unknown'},
    '#report-condition-category': {value:'healthy'},
    '#report-urgency': {value:'medium'},
    '#report-description': {value:'Seen near the bus stop.', checkValidity(){return this.value.trim().length >= 10;}, focus(){}, reportValidity(){}},
    '#report-condition': {value:''},
    '#stray-submit': {disabled:false, textContent:'Submit Stray Report'},
  };
  const map = {
    center:null,
    marker:null,
    handlers:{},
    setView(point) { this.center=point; return this; },
    on(name, handler) { this.handlers[name]=handler; return this; },
    remove() {},
    invalidateSize() {},
    removeLayer(marker) { if (this.marker === marker) this.marker=null; },
  };
  let marker = null;
  const context = vm.createContext({
    petStatusLabel:value => String(value),
    window:{isSecureContext:true, location:{href:'https://adoptapet.test/'}},
    navigator:{},
    document:{querySelectorAll(){return []; }},
    URL,
    AbortController,
    setTimeout(){return 0;},
    $:selector => fields[selector] || null,
    esc:value => String(value),
    fetch:async (url, options) => {
      requests.push({url:String(url), options});
      return fetchHandler(String(url), options, requests);
    },
    setButtonBusy(button, busy, label) {
      if (!button) return;
      button.disabled = Boolean(busy);
      if (busy && label) button.textContent = label;
      if (!busy) button.textContent = button.originalText || button.textContent;
    },
    FormData:class {
      constructor(){this.fields=[];}
      append(name, value){this.fields.push([name, value]);}
    },
    appendEditedImageBatch(){},
    clearEditedImageBatch(){},
    toast(){},
    go(){},
  });
  context.L = {
    map() { return map; },
    tileLayer() { return {addTo(){return this;}}; },
    marker(point, options) {
      marker = {
        lat:point[0], lng:point[1], options, handlers:{},
        addTo(target){target.marker=this;return this;},
        on(name, handler){this.handlers[name]=handler;return this;},
        setLatLng(next){this.lat=next[0];this.lng=next[1];return this;},
        getLatLng(){return {lat:this.lat,lng:this.lng};},
      };
      return marker;
    },
  };
  vm.runInContext(source, context);
  context.testPickerMap = map;
  vm.runInContext('strayPickerMap = testPickerMap', context);
  return {context, fields, map, requests, get marker(){return marker;}};
}

function flushAsync() {
  return new Promise(resolve => setImmediate(resolve));
}

assert.doesNotMatch(source, /Which location are you reporting\?|I'm at the location|I'm reporting another location/);
assert.doesNotMatch(source, /stray-reverse-suggestion|Use this address|report-location-confirmed|stray-location-mode/);
assert.doesNotMatch(source, /setStrayLocationMode|invalidateStrayLocationConfirmation/);
assert.match(source, /id="use-current-location"[^>]*onclick="useCurrentStrayLocation\(\)"[^>]*>Use My Current Location/);
assert.match(source, /class="stray-map-heading"[\s\S]*?<button id="use-current-location"/);
assert.doesNotMatch(style, /stray-location-mode|stray-address-suggestion|stray-location-confirmation/);
assert.match(proxy, /function strayGeocodeReverseLookup\(/);
assert.match(proxy, /ADOPTAPET_GEOCODER_REVERSE_URL/);

(async () => {
  const gps = makeHarness(async (url, options) => {
    if (url.endsWith('stray-geocode.php'))
      return response(200, {
        success:true,
        address_suggestion:'Ampang Point, Ampang, Selangor',
        latitude:55,
        longitude:34,
      });
    if (url.endsWith('stray-reports.php'))
      return response(200, {success:true, message:'Saved', report:{id:42}});
    return response(500, {success:false});
  });
  gps.fields['#report-location'].value = 'Existing manually entered address';
  gps.context.navigator.geolocation = {
    getCurrentPosition(success) { success({coords:{latitude:3.15, longitude:101.7}}); },
  };
  vm.runInContext('useCurrentStrayLocation()', gps.context);
  await flushAsync();
  assert.equal(gps.marker.lat, 3.15);
  assert.equal(gps.marker.lng, 101.7);
  assert.equal(gps.map.center[0], 3.15);
  assert.equal(gps.map.center[1], 101.7);
  assert.equal(gps.fields['#report-latitude'].value, '3.1500000');
  assert.equal(gps.fields['#report-longitude'].value, '101.7000000');
  assert.equal(gps.fields['#report-location'].value, 'Ampang Point, Ampang, Selangor');
  const reverseRequest = gps.requests[0];
  assert.deepEqual(JSON.parse(reverseRequest.options.body), {
    action:'reverse', latitude:3.15, longitude:101.7,
  });
  assert.doesNotMatch(reverseRequest.options.body, /reporter|description/);

  gps.fields['#report-description'].value = 'The stray was seen beside the road.';
  vm.runInContext('strayReportFormStep = 3', gps.context);
  await vm.runInContext('submitStrayReport({preventDefault(){}})', gps.context);
  const reportRequest = gps.requests.find(request => request.url.endsWith('stray-reports.php'));
  const payload = JSON.parse(reportRequest.options.body.fields.find(([name]) => name === 'payload')[1]);
  assert.equal(payload.location_text, 'Ampang Point, Ampang, Selangor');
  assert.equal(payload.latitude, '3.1500000');
  assert.equal(payload.longitude, '101.7000000');

  const mapClick = makeHarness();
  mapClick.fields['#report-location'].value = 'User-entered landmark';
  vm.runInContext('initStrayPickerMap()', mapClick.context);
  mapClick.map.handlers.click({latlng:{lat:3.21, lng:101.67}});
  assert.equal(mapClick.fields['#report-location'].value, 'User-entered landmark');
  assert.equal(mapClick.fields['#report-latitude'].value, '3.2100000');
  assert.equal(mapClick.fields['#report-longitude'].value, '101.6700000');

  const reverseFailure = makeHarness(async () => response(502, {success:false, message:'Address lookup is temporarily unavailable.'}));
  reverseFailure.context.navigator.geolocation = {
    getCurrentPosition(success) { success({coords:{latitude:3.2, longitude:101.6}}); },
  };
  vm.runInContext('useCurrentStrayLocation()', reverseFailure.context);
  await flushAsync();
  assert.equal(reverseFailure.fields['#report-latitude'].value, '3.2000000');
  assert.equal(reverseFailure.fields['#report-longitude'].value, '101.6000000');
  assert.match(reverseFailure.fields['#stray-map-status'].textContent, /manually/);
  reverseFailure.fields['#report-location'].value = 'Manually entered nearby landmark';
  vm.runInContext('invalidateStrayLocationSelection()', reverseFailure.context);
  assert.equal(reverseFailure.fields['#report-latitude'].value, '3.2000000');
  assert.equal(reverseFailure.fields['#report-longitude'].value, '101.6000000');
  assert.equal(vm.runInContext('validateStrayReportStep(2)', reverseFailure.context), true);

  const rateLimited = makeHarness(async () => response(429, {success:false, message:'Please wait a moment.'}));
  vm.runInContext('setStrayCoordinates(3.2, 101.6)', rateLimited.context);
  await vm.runInContext('requestStrayAddressSuggestion(3.2, 101.6)', rateLimited.context);
  assert.match(rateLimited.fields['#stray-map-status'].textContent, /Please wait/);

  const noAddress = makeHarness(async () => response(200, {success:true, address_suggestion:null}));
  noAddress.fields['#report-location'].value = 'Manual label before lookup';
  vm.runInContext('setStrayCoordinates(3.2, 101.6)', noAddress.context);
  await vm.runInContext('requestStrayAddressSuggestion(3.2, 101.6)', noAddress.context);
  assert.match(noAddress.fields['#stray-map-status'].textContent, /No readable address/);
  assert.equal(noAddress.fields['#report-location'].value, 'Manual label before lookup');
  assert.equal(noAddress.fields['#report-latitude'].value, '3.2000000');
  assert.equal(noAddress.fields['#report-longitude'].value, '101.6000000');

  const invalidCoordinates = makeHarness();
  await vm.runInContext('requestStrayAddressSuggestion(91, 181)', invalidCoordinates.context);
  assert.equal(invalidCoordinates.requests.length, 0);
  assert.match(invalidCoordinates.fields['#stray-map-status'].textContent, /valid map location/);

  const denied = makeHarness();
  denied.context.navigator.geolocation = {
    getCurrentPosition(success, failure) { failure({code:1}); },
  };
  vm.runInContext('useCurrentStrayLocation()', denied.context);
  assert.match(denied.fields['#stray-map-status'].textContent, /permission was denied/);
  assert.equal(denied.requests.length, 0);

  const forwardSearch = makeHarness(async () => response(200, {success:true, results:[{
    display_name:'Matched place, Ampang, Selangor', latitude:3.2185868, longitude:101.6736929,
  }]}));
  forwardSearch.fields['#report-location'].value = 'Ampang Point, Ampang';
  await vm.runInContext('searchStrayLocation()', forwardSearch.context);
  const selected = {attributes:{}, setAttribute(name,value){this.attributes[name]=value;}};
  forwardSearch.fields['#stray-geocode-results'].children = [{querySelector:() => selected}];
  vm.runInContext('selectStrayGeocodeResult(0)', forwardSearch.context);
  assert.equal(forwardSearch.fields['#report-location'].value, 'Matched place, Ampang, Selangor');
  assert.equal(selected.attributes['aria-pressed'], 'true');
  assert.equal(forwardSearch.fields['#report-latitude'].value, '3.2185868');
  assert.equal(forwardSearch.fields['#report-longitude'].value, '101.6736929');
  assert.equal(forwardSearch.marker.lat, 3.2185868);
  assert.equal(forwardSearch.marker.lng, 101.6736929);
  assert.equal(forwardSearch.map.center[0], 3.2185868);
  assert.equal(forwardSearch.map.center[1], 101.6736929);
  forwardSearch.marker.lat = 3.219;
  forwardSearch.marker.lng = 101.674;
  forwardSearch.marker.handlers.dragend({target:forwardSearch.marker});
  assert.equal(forwardSearch.fields['#report-location'].value, 'Matched place, Ampang, Selangor');
  assert.equal(forwardSearch.fields['#report-latitude'].value, '3.2190000');
  assert.equal(forwardSearch.fields['#report-longitude'].value, '101.6740000');

  const validation = makeHarness();
  assert.equal(vm.runInContext('validateStrayReportStep(2)', validation.context), false);
  validation.fields['#report-location'].value = 'A valid landmark';
  assert.equal(vm.runInContext('validateStrayReportStep(2)', validation.context), false);
  vm.runInContext('setStrayCoordinates(3.2, 101.6)', validation.context);
  assert.equal(vm.runInContext('validateStrayReportStep(2)', validation.context), true);

  console.log('Unified stray location GPS, geocoding, map, submission, and validation checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
