import { API_FOLDER } from "@helpers/config";

/**
 * Save nickname / "Terry says" / favorite flag for a palette
 * Admin-only endpoint
 */
export async function savePaletteMeta({ palette_id, nickname, terry_says, terry_fav }) {
  const url = `${API_FOLDER}/v2/admin/update-palette-meta.php?_${Date.now()}`;
  const resp = await fetch(url, {
    method: "POST",
    credentials: "include",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      palette_id,
      nickname,
      terry_says,
      terry_fav: terry_fav ? 1 : 0,
    }),
  });
  const json = await resp.json().catch(() => ({}));
  if (!resp.ok || !json.ok) throw new Error(json.error || `HTTP ${resp.status}`);
  return true;
}
