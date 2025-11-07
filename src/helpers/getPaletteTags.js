import { API_FOLDER } from "@helpers/config";

/**
 * Fetch tags for a given palette_id.
 * Returns string[] (sorted on server).
 */
export async function getPaletteTags(paletteId) {
  if (!paletteId || Number.isNaN(Number(paletteId))) {
    throw new Error("paletteId required");
  }

  const url = `${API_FOLDER}/v2/get-palette-tags.php?palette_id=${encodeURIComponent(
    paletteId
  )}`;

  const resp = await fetch(url, {
    credentials: "include",
    headers: { Accept: "application/json" },
    cache: "no-store",
  });

  // Try to parse JSON even on non-200 to surface server error message
  let json = {};
  try {
    json = await resp.json();
  } catch {
    /* ignore */
  }

  if (!resp.ok || json.ok === false) {
    const msg = json?.error || `Failed to load tags (HTTP ${resp.status})`;
    throw new Error(msg);
  }

  return Array.isArray(json.tags) ? json.tags : [];
}
