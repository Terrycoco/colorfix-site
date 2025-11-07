// @data/paletteSearch.js
export async function runPaletteSearch(payload, { signal } = {}) {
  const url = '/api/v2/palette-search.php'; // adjust if your endpoint differs
  const res = await fetch(url, {
    method: 'POST',
    headers: {'Content-Type':'application/json','Accept':'application/json'},
    body: JSON.stringify(payload),
    signal,
  });
  const text = await res.text();
  try { return JSON.parse(text); }
  catch {
    return { ok:false, error:'Non-JSON response', status:res.status, body:text.slice(0,400) };
  }
}
