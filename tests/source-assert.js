const assert = require('node:assert/strict');

// Source checks should tolerate formatting while still checking the same code and CSS tokens.
function sourceViews(value) {
  if (typeof value !== 'string') return [value];
  const singleLine = value.replace(/\s+/g, ' ');
  const compactCommas = singleLine.replace(/,\s+/g, ',');
  const compact = value.replace(/\s+/g, '');
  const keywordSpaced = compact.replace(/\b(const|let|function|return|throw|new)(?=[A-Za-z_$])/g, '$1 ');
  const withoutTrailingCommas = keywordSpaced.replace(/,([}\]])/g, '$1').replace(/,\)/g, ')');
  return [value, singleLine, compactCommas, compact, keywordSpaced, withoutTrailingCommas];
}

function matches(view, expression) {
  expression.lastIndex = 0;
  return expression.test(view);
}

function match(value, expression, message) {
  if (sourceViews(value).some((view) => matches(view, expression))) return;
  assert.match(value, expression, message);
}

function doesNotMatch(value, expression, message) {
  assert.doesNotMatch(value, expression, message);
}

function extractFunction(source, name) {
  const start = source.search(new RegExp(`^(?:async )?function ${name}\\(`, 'm'));
  if (start < 0) return '';
  const end = source.indexOf('\n}', start);
  return end < 0 ? '' : source.slice(start, end + 2);
}

module.exports = { match, doesNotMatch, extractFunction };
