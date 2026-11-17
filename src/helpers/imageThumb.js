import { API_FOLDER } from "./config.js";

export function photoThumbUrl(photoLibraryId, width = 480, quality = 72, cacheKey = "", cropType = "swatch") {
  const id = Number(photoLibraryId || 0);
  if (!id) return "";
  const params = new URLSearchParams({
    id: String(id),
    w: String(width),
    q: String(quality),
  });
  if (cropType !== "swatch") params.set("crop_type", cropType);
  const version = extractThumbVersion(cacheKey);
  if (version) params.set("v", version);
  return `${API_FOLDER}/v2/image-thumb.php?${params.toString()}`;
}

function extractThumbVersion(value) {
  const raw = String(value || "").trim();
  if (!raw) return "";
  try {
    const parsed = new URL(raw, window.location.origin);
    return parsed.searchParams.get("v") || parsed.pathname;
  } catch {
    const match = raw.match(/[?&]v=([^&]+)/);
    return match ? decodeURIComponent(match[1]) : "";
  }
}

// Keep the complete PHOTOS-rendered crop visible inside differently shaped cards.
export function photoThumbStyle(source) {
  return String(source || "").includes("/v2/image-thumb.php?") ? { objectFit: "contain" } : undefined;
}

// Full-composition PHOTOS display image. A supplied capability is issued by the
// authorized PHP response; the browser never grants access or invents signatures.
export function photoDisplayUrl(photoLibraryId, originalUrl = "", deliveryUrl = "", width = 1920) {
  if (deliveryUrl) {
    const url = new URL(deliveryUrl, typeof window === "undefined" ? "https://colorfix.invalid" : window.location.origin);
    url.searchParams.set("w", String(width));
    url.searchParams.set("crop_type", "original");
    url.searchParams.set("fit", "inside");
    return `${url.pathname}${url.search}`;
  }
  const thumbnail = photoThumbUrl(photoLibraryId, width, 88, originalUrl, "original");
  return thumbnail ? `${thumbnail}&fit=inside` : originalUrl;
}

export function originalPhotoUrl(value = "") {
  return String(value).startsWith("photo:") ? String(value).split("|").slice(1).join("|") : value;
}

export function photoImageProps(photoLibraryId, original = "", { width = 320, cropType = "original", deliveryUrl = "", display = false } = {}) {
  original = originalPhotoUrl(original);
  const src = display || deliveryUrl
    ? photoDisplayUrl(photoLibraryId, original, deliveryUrl, width)
    : photoThumbUrl(photoLibraryId, width, 88, original, cropType) || original;
  return {
    src,
    decoding: "async",
    "data-photo-original": original || undefined,
    onError: (event) => {
      const img = event.currentTarget;
      if (!original || img.getAttribute("src") === original) return;
      img.removeAttribute("srcset");
      img.src = original;
    },
  };
}

export function slidePhotoUrl(item, width = 1920) {
  const value = item?.image_url || "";
  const embeddedId = String(value).startsWith("photo:") ? String(value).slice(6).split("|")[0] : "";
  return photoDisplayUrl(item?.photo_library_id || embeddedId, originalPhotoUrl(value), item?.display_image_url || "", width);
}

export function nextSlidePhotoUrls(slides, activeIndex, count = 2) {
  return [...new Set(slides.slice(activeIndex + 1, activeIndex + 1 + Math.min(2, Math.max(0, count)))
    .filter(item => !["hue-wheel", "brand-bumper"].includes(item?.type || item?.item_type))
    .map(item => slidePhotoUrl(item)).filter(Boolean))];
}

export function fallbackToOriginal(event, original = "") {
  const img = event.currentTarget;
  original = originalPhotoUrl(original);
  if (!original || img.getAttribute("src") === original) return;
  img.removeAttribute("srcset");
  img.closest("picture")?.querySelectorAll("source").forEach(source => source.removeAttribute("srcset"));
  img.src = original;
}
