import test from 'node:test';
import assert from 'node:assert/strict';
import { positiveIds, groupTranslations, requestTranslation, translateColors } from '../../src/pages/PaletteTranslationPage/translatePalette.js';

const color = (id, brand) => ({ id, brand, name: `Color ${id}`, hex6: '123456' });
const pair = [
  { source: color(1, 'sw'), items: [{ color: color(12, 'bm') }, { color: color(13, 'behr') }] },
  { source: color(2, 'bm'), items: [{ color: color(21, 'sw') }, { color: color(23, 'behr') }] },
];

test('explicit color IDs are deduplicated and validated', () => {
  assert.deepEqual(positiveIds(['1', 2, '1', '', 0, -2, 'bad', 1.5]), [1, 2]);
});
test('complete brand palettes include original picks and preserve source order', () => {
  const grouped = groupTranslations(pair);
  assert.deepEqual(grouped.brands, ['behr', 'bm', 'sw']);
  assert.equal(grouped.src_count, 2);
  assert.equal(grouped.count, 6);
  assert.deepEqual(grouped.items.filter((row) => row.color.brand === 'sw').map((row) => row.color.id), [1, 21]);
  assert.deepEqual(grouped.items.filter((row) => row.color.brand === 'bm').map((row) => row.color.id), [12, 2]);
});
test('incomplete brands and duplicate matches are diagnosed rather than shown as full palettes', () => {
  const result = groupTranslations([
    { source: color(1, 'sw'), items: [{ color: color(10, 'bm') }, { color: color(20, 'behr') }, { color: color(30, 'true') }] },
    { source: color(2, 'sw'), items: [{ color: color(10, 'bm') }] },
  ]);
  assert.deepEqual(result.brands, ['sw']);
  assert.deepEqual(result.failures.map((failure) => failure.brand), ['behr', 'bm']);
});
test('requests use the working per-color endpoint, bound concurrency, and report progress', async () => {
  let active = 0; let peak = 0;
  const progress = [];
  const fetchImpl = async (url) => {
    const parsed = new URL(url);
    assert.equal(parsed.pathname, '/api/v2/translate.php');
    assert.deepEqual([...parsed.searchParams.keys()], ['source_id']);
    const id = Number(parsed.searchParams.get('source_id'));
    peak = Math.max(peak, ++active);
    await new Promise((resolve) => setTimeout(resolve, 5));
    active--;
    return new Response(JSON.stringify({ ok: true, source: color(id, 'sw'), items: [] }));
  };
  const result = await translateColors('https://fixture.test/api', [1, 2, 3], { fetchImpl, onProgress: (count) => progress.push(count) });
  assert.equal(peak, 2);
  assert.deepEqual(progress, [1, 2, 3]);
  assert.deepEqual(result.items.map((item) => item.color.id), [1, 2, 3]);
});
test('a stalled request times out instead of loading forever', async () => {
  const fetchImpl = (_url, { signal }) => new Promise((_resolve, reject) => {
    signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')), { once: true });
  });
  await assert.rejects(requestTranslation('https://fixture.test', { fetchImpl, timeoutMs: 10 }), /took too long/);
});
test('navigation cancels outstanding translation requests', async () => {
  const controller = new AbortController();
  const fetchImpl = (_url, { signal }) => new Promise((_resolve, reject) => {
    signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')), { once: true });
  });
  const pending = translateColors('https://fixture.test/api', [1, 2], { signal: controller.signal, fetchImpl });
  controller.abort();
  await assert.rejects(pending, { name: 'AbortError' });
});
test('HTTP, malformed JSON, and matching errors become visible failures', async () => {
  for (const [response, message] of [
    [new Response('Service unavailable', { status: 503 }), /HTTP 503/],
    [new Response('<html>error</html>'), /unreadable response/],
    [new Response(JSON.stringify({ ok: false, error: 'Missing color' })), /Missing color/],
  ]) {
    await assert.rejects(requestTranslation('https://fixture.test', { fetchImpl: async () => response }), message);
  }
});
