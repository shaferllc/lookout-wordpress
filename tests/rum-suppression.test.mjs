// Verifies the browser RUM error-suppression key matches the server byte-for-byte by extracting and
// running the ACTUAL normSupp/suppKey functions from the inline snippet (so the test can't drift).
// Run: node packages/lookout-wordpress/tests/rum-suppression.test.mjs

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const dir = path.dirname(fileURLToPath(import.meta.url));
const src = readFileSync(path.join(dir, '../includes/class-lookout-rum.php'), 'utf8');

const start = src.indexOf('function normSupp');
const end = src.indexOf('function reportError');
if (start < 0 || end <= start) {
  console.error('FAIL — could not find the suppression functions in the RUM snippet');
  process.exit(1);
}

const { suppKey } = new Function(src.slice(start, end) + '; return { normSupp, suppKey };')();
const key = (cls, msg) => new Promise((res) => suppKey(cls, msg, res));

// Expected values cross-checked against App\Support\ErrorSuppressionKey::compute (the server recipe).
const cases = [
  ['App\\Exceptions\\Boom', 'User 4212 not found', '39ef0cde2ebd1a398fdb287a1c103895'],
  ['JavaScriptError', "Cannot read properties of undefined (reading 'foo') at line 1234", '3bbbab96464cfd6eb98c3a7bd888aa75'],
  ['X', 'HTTP 404 Not Found: GET /a/01ktb3fn7n0me349kth61ydqy6.png', 'a9d54db0a29bb6fdb4b456678e2341d6'],
  ['Foo', '  Weird\n  WHITESPACE 0xFF and 99 times ', '894ab3aeca7e48202c95e645151d4cd9'],
];

let failures = 0;
for (const [cls, msg, expected] of cases) {
  const got = await key(cls, msg);
  const ok = got === expected;
  console.log(`${ok ? 'PASS' : 'FAIL'} — ${cls}`);
  if (!ok) {
    console.log(`   got ${got}, expected ${expected}`);
    failures++;
  }
}

// Two storage 404s differing only in a long id collapse to one key.
const a = await key('JavaScriptError', 'Failed to load /assets/01ktb3fn7n0me349kth61ydqy6rvqos.js');
const b = await key('JavaScriptError', 'Failed to load /assets/02abckj9zz8x71239aaa00bbb1zzzzz.js');
console.log(`${a === b ? 'PASS' : 'FAIL'} — volatile tokens collapse occurrences`);
if (a !== b) failures++;

console.log(failures === 0 ? '\nOK' : `\n${failures} FAILED`);
process.exit(failures === 0 ? 0 : 1);
