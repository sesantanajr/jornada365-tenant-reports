const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const core = path.join(__dirname, '..', 'wordpress', 'jornada365-tenant-portal', 'assets', 'js', 'core.js');
const context = { window: {}, document: { cookie: '', documentElement: { dataset: {} }, querySelector: () => null } };
vm.runInNewContext(fs.readFileSync(core, 'utf8'), context);
const { quoteCsvField } = context.window.J365;

const protectedValues = ['=1+2', '+1', '-42', '@SUM(A1)', '\ttext', '\rtext', '\ntext', '  =2+2', '\u200b=3', '＝1', '＋1', '－1', '＠1'];
for (const value of protectedValues) {
  assert.equal(quoteCsvField(value), '"' + "'" + value + '"');
}
assert.equal(quoteCsvField('Rótulo;"com aspas"'), '"Rótulo;""com aspas"""');
assert.equal(quoteCsvField(' +1;"coluna"'), '"' + "'" + ' +1;""coluna"""');
assert.equal(quoteCsvField('Nome com acento'), '"Nome com acento"');
assert.equal(quoteCsvField(-42), '"-42"');
assert.equal(quoteCsvField(0), '"0"');
console.log('CSV field escaping passed.');
