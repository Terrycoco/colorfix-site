export const PUBLISHING_CHANNELS = Object.freeze({
  pinterest: "pinterest",
  youtube: "youtube",
});

export const ASSET_STATUS = Object.freeze({
  draft: "draft",
  created: "created",
  staged: "staged",
  published: "published",
  failed: "failed",
});

export function normalizeAssetType(value) {
  return String(value || "")
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "");
}

export function createAssetResult({
  channelKey,
  assetType,
  assetPath = "",
  version = 1,
  title = "",
  description = "",
  trackingUrl = "",
  destinationUrl = "",
  metadata = {},
}) {
  const normalizedChannel = String(channelKey || "").trim();
  const normalizedType = normalizeAssetType(assetType);

  if (!normalizedChannel) {
    throw new Error("Asset result requires channelKey.");
  }
  if (!normalizedType) {
    throw new Error("Asset result requires assetType.");
  }

  return {
    ok: true,
    channelKey: normalizedChannel,
    assetType: normalizedType,
    assetPath: String(assetPath || ""),
    version: Number(version) > 0 ? Number(version) : 1,
    title: String(title || ""),
    description: String(description || ""),
    trackingUrl: String(trackingUrl || ""),
    destinationUrl: String(destinationUrl || ""),
    metadata: metadata && typeof metadata === "object" ? metadata : {},
  };
}

export function validateAssetResult(result) {
  const missing = [];
  if (!result || typeof result !== "object") missing.push("result");
  if (!result?.channelKey) missing.push("channelKey");
  if (!result?.assetType) missing.push("assetType");
  if (!result?.assetPath) missing.push("assetPath");

  return {
    ok: missing.length === 0,
    missing,
  };
}
