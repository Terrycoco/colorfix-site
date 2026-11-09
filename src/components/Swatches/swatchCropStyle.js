/**
 * Shared display-only mapping of PHOTOS' normalized crop rectangle.
 * Coordinates are fractions of the ORIGINAL, uncropped photograph.
 * Source images used with this mapping must preserve the original aspect ratio.
 * When no stored crop exists, CSS object-fit: cover supplies centered framing.
 */
export function hasSavedSwatchCrop({ cropX, cropY, cropWidth, cropHeight }) {
  const fields = [cropX, cropY, cropWidth, cropHeight];
  if (fields.some((value) => value === null || value === undefined || value === "")) return false;
  const [x, y, w, h] = fields.map(Number);
  const EPSILON = 0.000002;
  return [x, y, w, h].every(Number.isFinite) &&
    x >= -EPSILON && y >= -EPSILON && w > 0 && h > 0 &&
    x + w <= 1 + EPSILON && y + h <= 1 + EPSILON;
}

export function swatchCropStyle({ cropX, cropY, cropWidth, cropHeight }) {
  if (!hasSavedSwatchCrop({ cropX, cropY, cropWidth, cropHeight })) return undefined;
  const [x, y, w, h] = [cropX, cropY, cropWidth, cropHeight].map(Number);
  return {
    width: `${100 / w}%`,
    height: `${100 / h}%`,
    left: `${-100 * x / w}%`,
    top: `${-100 * y / h}%`,
    // Width/height already encode the actual source image's 5:4 crop rectangle.
    objectFit: "fill",
    maxWidth: "none",
    maxHeight: "none",
  };
}
