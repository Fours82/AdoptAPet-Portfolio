const { match, doesNotMatch } = require('./source-assert');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const applications = fs.readFileSync(path.join(root, 'public/assets/js/applications.js'), 'utf8');
const pets = fs.readFileSync(path.join(root, 'public/assets/js/pets.js'), 'utf8');
const router = fs.readFileSync(path.join(root, 'public/assets/js/app.js'), 'utf8');
const index = fs.readFileSync(path.join(root, 'public/index.php'), 'utf8');

const ownViews = applications.split('function adminApplications()')[0];
match(ownViews, /pet\.adoption_status!==['"]available['"]/);
match(ownViews, /applications unavailable/i);
match(ownViews, /application\.admin_notes/);
match(ownViews, /event\.new_status==='rejected'\|\|event\.new_status==='approved'/);
match(ownViews, /application\.application_status==='approved'/);
match(ownViews, /application\.application_status==='pending'/);
match(applications, /application\.pet_adoption_status==='available'/);
match(applications, /Applicant-facing review note/);
match(applications, /do not include private staff information/);
match(pets, /pet\.adoption_status==='available'/);
doesNotMatch(pets, /<b>Characteristics<\/b>/);
match(pets, /pet\.images\|\|\[\]/);
match(router, /r\.startsWith\('\/pet\/'\)/);
match(router, /r\.startsWith\('\/apply\/'\)/);
match(router, /r\.startsWith\('\/application\/'\)/);
match(index, /pets\.js\?v=36/);
match(index, /applications\.js\?v=17/);

console.log('Adoption flow JavaScript checks passed.');
