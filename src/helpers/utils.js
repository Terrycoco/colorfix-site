export function armClickShield(ms = 450) {
  window.__clickShieldUntil = Date.now() + ms;
}
export function isClickShielded() {
  return Date.now() < (window.__clickShieldUntil || 0);
}