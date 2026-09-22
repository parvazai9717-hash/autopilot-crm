const assert = require('assert');
const { resolveOwner } = require('./resolve');

const sampleRoster = [
  { id: 1, name: 'Ahmed Raza', email: 'ahmed@test.com' },
  { id: 2, name: 'Sarah Khan', email: 'sarah@test.com' },
  { id: 3, name: 'Ali Khan', email: 'ali.k@test.com' },
  { id: 4, name: 'Ali Raza', email: 'ali.r@test.com' },
  { id: 5, name: 'Bilal Sheikh', email: 'bilal@test.com' },
];

console.log('Running n8n resolveOwner unit tests...');

// 1. Exact match
{
  const res = resolveOwner('Sarah Khan', sampleRoster);
  assert.strictEqual(res.owner_id, 2);
  assert.strictEqual(res.owner_state, 'resolved');
  assert.strictEqual(res.owner_ambiguous, false);
  console.log('✓ Exact match test passed');
}

// 2. Ambiguous match (two Alis)
{
  const res = resolveOwner('Ali', sampleRoster);
  assert.strictEqual(res.owner_id, null);
  assert.strictEqual(res.owner_state, 'ambiguous');
  assert.strictEqual(res.owner_ambiguous, true);
  assert.strictEqual(res.candidates.length, 2);
  console.log('✓ Ambiguous match test passed');
}

// 3. Unmatched name (Defect F5 regression test)
{
  const res = resolveOwner('Dave from Marketing', sampleRoster);
  assert.strictEqual(res.owner_id, null);
  assert.strictEqual(res.owner_state, 'unmatched', 'F5 defect: unmatched name must produce owner_state=unmatched');
  assert.strictEqual(res.owner_name_raw, 'Dave from Marketing');
  console.log('✓ F5 unmatched name test passed');
}

// 4. Missing/empty name
{
  const res = resolveOwner('', sampleRoster);
  assert.strictEqual(res.owner_id, null);
  assert.strictEqual(res.owner_state, 'missing');
  assert.strictEqual(res.owner_ambiguous, false);
  console.log('✓ Missing name test passed');
}

console.log('\nAll n8n resolveOwner tests passed successfully!');
