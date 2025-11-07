import { API_FOLDER as API } from '@helpers/config';

// Fetch a COLOR (full row from `colors` table) for the detail page.
// Robust parsing: reads raw text first, then tries JSON, with clear errors.
export default async function fetchColorDetail(id, setCurrentColorDetail) {
  if (!id) return;

  const url = `${API}/v2/get-color.php?id=${encodeURIComponent(id)}&_=${Date.now()}`;
  const res = await fetch(url, {
    headers: { 'Accept': 'application/json' },
    cache: 'no-store'
  });

  const raw = await res.text(); // read raw first so we can debug
  if (!raw) {
    throw new Error(`Empty response (HTTP ${res.status}) from ${url}`);
  }

  let json;
  try {
    json = JSON.parse(raw);
  } catch (e) {
    // Surface the real payload (likely an HTML/PHP warning) to the console/error
    const snippet = raw.slice(0, 400);
    console.error('Non-JSON response:', { status: res.status, raw: snippet });
    throw new Error(`Non-JSON response (HTTP ${res.status}): ${snippet}`);
  }

  if (!res.ok || !json.ok || !json.color) {
    throw new Error(json?.error || `Failed to load COLOR (HTTP ${res.status})`);
  }

  setCurrentColorDetail(json.color);
}
