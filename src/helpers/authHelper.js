// utils/authHelper.js
// @helpers/authHelper.js
export function isAdmin() {
  try {
    if (typeof window === "undefined") return false;
    const v = window.localStorage.getItem("isTerry") || "";
    // Only these mean true; everything else is false
    return v === "1" || v.toLowerCase() === "true" || v.toLowerCase() === "yes";
  } catch {
    return false;
  }
}

export function setAdmin(flag) {
  try {
    if (typeof window === "undefined") return;
    if (flag) window.localStorage.setItem("isTerry", "1");
    else window.localStorage.removeItem("isTerry");
  } catch {}
}
