import { API_FOLDER } from "@helpers/config";

const cache = new Map();

export function isAssetRef(value) {
  return typeof value === "string" && value.startsWith("asset:");
}

export function extractAssetId(value) {
  if (!isAssetRef(value)) return "";
  return value.slice("asset:".length).trim();
}

export function makeAssetRef(assetId) {
  const id = String(assetId || "").trim();
  return id ? `asset:${id}` : "";
}

export function makePhotoRef(photoLibraryId, url) {
  const id = String(photoLibraryId || "").trim();
  const safeUrl = String(url || "");
  if (!id) return safeUrl;
  return `photo:${id}|${safeUrl}`;
}

export function parsePhotoRef(value) {
  if (typeof value !== "string") return { photoId: "", url: "" };
  if (!value.startsWith("photo:")) return { photoId: "", url: value };
  const rest = value.slice("photo:".length);
  const splitAt = rest.indexOf("|");
  if (splitAt === -1) {
    return { photoId: rest.trim(), url: "" };
  }
  const photoId = rest.slice(0, splitAt).trim();
  const url = rest.slice(splitAt + 1).trim();
  return { photoId, url };
}

export async function fetchAssetUrl(assetId) {
  const id = String(assetId || "").trim();
  if (!id) return "";

  const cached = cache.get(id);
  if (typeof cached === "string") return cached;
  if (cached && typeof cached.then === "function") {
    return cached;
  }

  const req = fetch(`${API_FOLDER}/v2/photos/get.php?asset_id=${encodeURIComponent(id)}`)
    .then((res) => res.json())
    .then((data) => {
      const url =
        data?.rel_path ||
        data?.full_url ||
        data?.url ||
        data?.thumb_url ||
        data?.prepared_url ||
        data?.repaired_url ||
        data?.prepared_tiers?.medium ||
        data?.prepared_tiers?.light ||
        data?.prepared_tiers?.dark ||
        "";
      return url;
    })
    .catch(() => "");

  cache.set(id, req);
  const url = await req;
  cache.set(id, url);
  return url;
}
