import assert from 'node:assert/strict';
import test from 'node:test';
import '../assets/date-format.js';
const {parse, format, age} = globalThis.VoxDateFormat;
test('strict Turkish dates and calendar validity', () => {
  assert.equal(parse('29.02.2024'), '2024-02-29');
  assert.equal(parse(''), '');
  for (const value of ['29.02.2023','31.04.2026','13/09/2026','2026-09-13','1.9.2026',' 13.09.2026','00.01.2026']) assert.equal(parse(value), null, value);
  assert.equal(parse('13.09.2026 23:59', true), '2026-09-13T23:59');
  assert.equal(parse('13.09.2026 24:00', true), null);
  assert.equal(format('2026-09-13T14:30'), '13.09.2026 14:30');
});
test('age changes on birthday', () => {
  assert.equal(age('2000-09-14', new Date(2026,8,13)),25);
  assert.equal(age('2000-09-13', new Date(2026,8,13)),26);
  assert.equal(age('2027-01-01', new Date(2026,8,13)),null);
});

test('single digit day and month padding',()=>{assert.equal(VoxDateFormat.pad('1.2.2026'),'01.02.2026');assert.equal(VoxDateFormat.pad('1.12.2026'),'01.12.2026');assert.equal(parse(VoxDateFormat.pad('31.2.2026')),null);});

test('unknown birth date is accepted without calculating age', () => {
  assert.equal(VoxDateFormat.parseField('00.00.0000', false, 'birth_date'), '');
  assert.equal(age(VoxDateFormat.parseField('00.00.0000', false, 'birth_date')), null);
  for (const value of ['00.01.2000','01.00.2000','00.00.2000','01.01.0000','0.0.0000'])
    assert.equal(VoxDateFormat.parseField(value, false, 'birth_date'), null);
  assert.equal(VoxDateFormat.parseField('00.00.0000', false, 'record_date'), null);
  assert.equal(VoxDateFormat.parseField('29.02.2024', false, 'birth_date'), '2024-02-29');
});
