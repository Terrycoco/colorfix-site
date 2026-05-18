import { API_FOLDER } from "./config";

export function photoThumbUrl(photoLibraryId, width = 480, quality = 72) {
  const id = Number(photoLibraryId || 0);
  if (!id) return "";
  const params = new URLSearchParams({
    id: String(id),
    w: String(width),
    q: String(quality),
  });
  return `${API_FOLDER}/v2/image-thumb.php?${params.toString()}`;
}
