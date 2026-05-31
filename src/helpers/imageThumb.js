import { API_FOLDER } from "./config";

export function photoThumbUrl(photoLibraryId, width = 480, quality = 72, cacheKey = "") {
  const id = Number(photoLibraryId || 0);
  if (!id) return "";
  const params = new URLSearchParams({
    id: String(id),
    w: String(width),
    q: String(quality),
  });
  const version = extractThumbVersion(cacheKey);
  if (version) params.set("v", version);
  return `${API_FOLDER}/v2/image-thumb.php?${params.toString()}`;
}

function extractThumbVersion(value) {
  const raw = String(value || "").trim();
  if (!raw) return "";
  try {
    const parsed = new URL(raw, window.location.origin);
    return parsed.searchParams.get("v") || "";
  } catch {
    const match = raw.match(/[?&]v=([^&]+)/);
    return match ? decodeURIComponent(match[1]) : "";
  }
}
