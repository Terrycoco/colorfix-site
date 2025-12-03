export const overlayPresetConfig = {
  baseBuckets: {
    darkMax: 45,
    lightMin: 88,
  },
  targetBuckets: {
    darkMax: 45,
    lightMin: 88,
  },
  grid: {
    dark: {
      dark: { mode: "overlay", opacity: 0.15 },
      medium: { mode: "softlight", opacity: 0.4 },
      light: { mode: "overlay", opacity: 0.35 },
    },
    medium: {
      dark: { mode: "multiply", opacity: 0.35 },
      medium: { mode: "softlight", opacity: 0.35 },
      light: { mode: "softlight", opacity: 0.3 },
    },
    light: {
      dark: { mode: "multiply", opacity: 0.45 },
      medium: { mode: "overlay", opacity: 0.3 },
      light: { mode: "softlight", opacity: 0.3 },
    },
  },
};

export function bucketForLightness(value, buckets = overlayPresetConfig.baseBuckets) {
  if (value == null || Number.isNaN(value)) return "medium";
  if (value < buckets.darkMax) return "dark";
  if (value >= buckets.lightMin) return "light";
  return "medium";
}

export function getPresetForBuckets(baseBucket, targetBucket) {
  if (!baseBucket || !targetBucket) return null;
  return overlayPresetConfig.grid?.[baseBucket]?.[targetBucket] || null;
}

export function presetForLightness(baseLightness, targetLightness) {
  const baseBucket = bucketForLightness(baseLightness, overlayPresetConfig.baseBuckets);
  const targetBucket = bucketForLightness(targetLightness, overlayPresetConfig.targetBuckets);
  return getPresetForBuckets(baseBucket, targetBucket);
}
