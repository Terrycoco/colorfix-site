import {roundTo} from '@helpers/numbers';

const WARMTH_CENTER = 60.0; // Start with Yellow as warmest



export function calcHueFromRGB(r, g, b) {
  // Ensure inputs are numbers in the correct range
  if (
    typeof r !== 'number' || typeof g !== 'number' || typeof b !== 'number' ||
    r < 0 || r > 255 || g < 0 || g > 255 || b < 0 || b > 255
  ) {
    return null;
  }

  const rNorm = r / 255;
  const gNorm = g / 255;
  const bNorm = b / 255;

  const max = Math.max(rNorm, gNorm, bNorm);
  const min = Math.min(rNorm, gNorm, bNorm);
  const delta = max - min;

  let hue;

  if (delta === 0) {
    hue = 0;
  } else if (max === rNorm) {
    hue = ((gNorm - bNorm) / delta) % 6;
  } else if (max === gNorm) {
    hue = (bNorm - rNorm) / delta + 2;
  } else {
    hue = (rNorm - gNorm) / delta + 4;
  }

  hue = roundTo(hue * 60, 2);
  if (hue < 0) hue += 360;

  return hue;
}

function getDistanceToWarmthCenter(hue, center = WARMTH_CENTER) {
  if (hue == null) return Infinity;
  const delta = Math.abs(hue - center);
  return Math.min(delta, 360 - delta); // handles wraparound
}

export function getWarmerColor(hueA, hueB) {
  const distA = getDistanceToWarmthCenter(hueA);
  const distB = getDistanceToWarmthCenter(hueB);

  if (distA === distB) return 'Equal';
  return distA < distB ? 'A' : 'B';
}
