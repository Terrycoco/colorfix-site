import assert from 'node:assert/strict';
import { resolvePhotoPalette } from '../src/pages/AdminPlaylistsPage/photoPalette.js';
const linked = { id: 99, palette_hash: 'ingres-hash', nickname: 'Ingres palette' };
let calls = 0;
const fetcher = async (url, options) => {
  calls++;
  assert.equal(url, '/api/v2/admin/palettes/detail.php?id=99');
  assert.equal(options.credentials, 'include');
  return { ok: true, json: async () => ({ ok: true, item: linked }) };
};
assert.equal(await resolvePhotoPalette(null, [], '/api', fetcher), null);
assert.equal(await resolvePhotoPalette(99, [linked], '/api', fetcher), linked);
assert.equal(calls, 0);
assert.equal((await resolvePhotoPalette(99, [], '/api', fetcher)).palette_hash, 'ingres-hash');
assert.equal(calls, 1);
await assert.rejects(resolvePhotoPalette(99, [], '/api', async () => ({ ok: false, json: async () => ({ ok: false, error: 'Missing palette' }) })), /Missing palette/);
await assert.rejects(resolvePhotoPalette(99, [], '/api', async () => ({ ok: true, json: async () => ({ ok: true, item: { id: 99 } }) })), /Could not load/);
console.log('PASS: photo palette resolves by ID with empty options; cached, absent, and failed lookups handled');
