import { API_FOLDER } from '@helpers/config';

export async function runAdvancedSearch(payload, { signal } = {}) {
  // ensure we hit v2
  const url = `${API_FOLDER}/v2/advanced-search.php`;

  const res = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    body: JSON.stringify(payload),
    signal,
  });

  // Read text first to avoid JSON.parse exploding on HTML error pages
  const text = await res.text();

  // Try to parse JSON either way (v2 handlers return JSON even on errors)
  try {
    const data = JSON.parse(text);
    return data; // { ok, total, count, items, _err? }
  } catch {
    // Non-JSON response (e.g., 404 HTML). Surface a helpful error.
    return {
      ok: false,
      error: `Non-JSON response from ${url}`,
      status: res.status,
      contentType: res.headers.get('content-type') || '',
      body: text.slice(0, 400),
    };
  }
}
