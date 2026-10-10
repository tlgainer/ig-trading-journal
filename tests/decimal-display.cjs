/* Display-only rounding must preserve decimal-string precision and existing unbounded callers. */
'use strict';
const fs = require('node:fs'), vm = require('node:vm'), assert = require('node:assert/strict');
const context = { window: {} }; vm.runInNewContext(fs.readFileSync('assets/collection.js', 'utf8'), context);
const display = context.window.tgitDisplayDecimal;
for (const [value, minimum, maximum, expected] of [
 ['1078.378215555154',2,2,'1078.38'], ['7.837821555515',2,2,'7.84'],
 ['0.012048918609554792',0,8,'0.01204892'], ['-181.818181818182',2,2,'-181.82'],
 ['99999999999999999999.995',2,2,'100000000000000000000.00'],
 ['0.000000000000000001',0,8,'<0.00000001'], ['-0.000000000000000001',0,8,'>-0.00000001'],
 ['-0.000000000000000000',2,2,'0.00'], ['30.000000000000000000',0,8,'30'],
 ['0.012048918609554792',0,null,'0.012048918609554792']
]) assert.equal(display(value,minimum,maximum),expected);
console.log('10 decimal display checks passed.');
