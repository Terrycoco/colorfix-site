// utils/authHelper.js
// @helpers/authHelper.js
export function isAdmin() {
  try {
    if (typeof window === "undefined") return false;
    const v = window.localStorage.getItem("isTerry") || "";
    const cookieAdmin = document.cookie
      .split(";")
      .map((part) => part.trim())
      .find((part) => part.startsWith("cf_admin="));
    const cookieAdminGlobal = document.cookie
      .split(";")
      .map((part) => part.trim())
      .find((part) => part.startsWith("cf_admin_global="));
    const cookieValue = cookieAdmin ? cookieAdmin.split("=").slice(1).join("=") : "";
    const cookieGlobalValue = cookieAdminGlobal ? cookieAdminGlobal.split("=").slice(1).join("=") : "";
    // Only these mean true; everything else is false
    if (v === "1" || v.toLowerCase() === "true" || v.toLowerCase() === "yes") return true;
    if (cookieValue === "1" || cookieValue.toLowerCase() === "true") return true;
    return cookieGlobalValue === "1" || cookieGlobalValue.toLowerCase() === "true";
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
