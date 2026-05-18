import { API_FOLDER as API } from "@helpers/config";

export default async function fetchColorTriggerPhotos(id) {
  if (!id) return [];

  const url = `${API}/v2/colors/trigger-photos.php?id=${encodeURIComponent(id)}&_=${Date.now()}`;
  const res = await fetch(url, {
    headers: { Accept: "application/json" },
    cache: "no-store",
  });

  const raw = await res.text();
  if (!raw) {
    throw new Error(`Empty response (HTTP ${res.status}) from ${url}`);
  }

  let json;
  try {
    json = JSON.parse(raw);
  } catch (error) {
    const snippet = raw.slice(0, 400);
    console.error("Non-JSON response:", { status: res.status, raw: snippet });
    throw new Error(`Non-JSON response (HTTP ${res.status}): ${snippet}`);
  }

  if (!res.ok || !json.ok) {
    throw new Error(json?.error || `Failed to load trigger photos (HTTP ${res.status})`);
  }

  return Array.isArray(json.items) ? json.items : [];
}
