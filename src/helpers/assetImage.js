import { API_FOLDER } from "@helpers/config";

const cache = new Map();
const IMAGE_REFRESH_KEY = "cf_force_image_refresh";
const IMAGE_REFRESH_STAMP_KEY = "cf_force_image_refresh_stamp";

function normalizeRefreshFlag(value) {
  const raw = String(value ?? "").trim().toLowerCase();
  if (["1", "true", "yes", "on"].includes(raw)) return true;
  if (["0", "false", "no", "off"].includes(raw)) return false;
  return null;
}

export function getImageRefreshEnabled() {
  if (typeof window === "undefined") return false;
  try {
    const params = new URLSearchParams(window.location.search);
    const paramValue = normalizeRefreshFlag(params.get("refresh_images"));
    if (paramValue !== null) {
      window.localStorage.setItem(IMAGE_REFRESH_KEY, paramValue ? "1" : "0");
      if (paramValue) ensureImageRefreshStamp();
      return paramValue;
    }
    return window.localStorage.getItem(IMAGE_REFRESH_KEY) === "1";
  } catch {
    return false;
  }
}

export function ensureImageRefreshStamp() {
  if (typeof window === "undefined") return "";
  try {
    let stamp = window.sessionStorage.getItem(IMAGE_REFRESH_STAMP_KEY) || "";
    if (!stamp) {
      stamp = String(Date.now());
      window.sessionStorage.setItem(IMAGE_REFRESH_STAMP_KEY, stamp);
    }
    return stamp;
  } catch {
    return String(Date.now());
  }
}

export function bumpImageRefreshStamp() {
  if (typeof window === "undefined") return "";
  const stamp = String(Date.now());
  try {
    window.sessionStorage.setItem(IMAGE_REFRESH_STAMP_KEY, stamp);
  } catch {
    /* ignore */
  }
  return stamp;
}

export function setImageRefreshEnabled(enabled) {
  if (typeof window === "undefined") return false;
  const next = Boolean(enabled);
  try {
    window.localStorage.setItem(IMAGE_REFRESH_KEY, next ? "1" : "0");
    if (next) bumpImageRefreshStamp();
  } catch {
    /* ignore */
  }
  return next;
}

export function withImageRefresh(url, force = null) {
  const value = String(url || "").trim();
  if (!value) return value;
  const enabled = force == null ? getImageRefreshEnabled() : Boolean(force);
  if (!enabled) return value;
  const stamp = ensureImageRefreshStamp();
  const sep = value.includes("?") ? "&" : "?";
  return `${value}${sep}r=${stamp}`;
}

export function withImageVersion(url, updatedAt = null) {
  const value = String(url || "").trim();
  if (!value) return value;
  const rawUpdatedAt = String(updatedAt || "").trim();
  if (!rawUpdatedAt) return value;
  const stamp = Date.parse(rawUpdatedAt);
  if (!Number.isFinite(stamp)) return value;
  const sep = value.includes("?") ? "&" : "?";
  return `${value}${sep}v=${stamp}`;
}

export function buildImageUrl(url, updatedAt = null, force = null) {
  return withImageRefresh(withImageVersion(url, updatedAt), force);
}

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
