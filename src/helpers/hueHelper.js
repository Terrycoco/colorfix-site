
// Clamp to [0, 360) for display (e.g., -20 -> 340, 360 -> 0)
export function toDisplayHue(h) {
  const x = ((Number(h) % 360) + 360) % 360;
  return x === 360 ? 0 : x;
}

// Build wheel-ready min/max from user-entered display degrees.
// If the range wraps (min > max), make min negative so the wheel draws across 0°.
// Example: min=340, max=20 -> wheelMin=-20, wheelMax=20
export function toWheelRange(displayMin, displayMax) {
  const a = toDisplayHue(displayMin);
  const b = toDisplayHue(displayMax);
  const wraps = a > b;
  const wheelMin = wraps ? a - 360 : a; // becomes negative when wrapping
  const wheelMax = b;
  return { wheelMin, wheelMax, wraps };
}