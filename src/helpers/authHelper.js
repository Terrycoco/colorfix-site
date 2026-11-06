// UI state only. Every protected server request independently checks the session.
let verifiedAdmin = false;

export function isAdmin() {
  return verifiedAdmin;
}

export function setAdmin(flag) {
  verifiedAdmin = flag === true;
  if (verifiedAdmin) markInternalViewer();
}

export function clearLegacyAdminState() {
  if (typeof window === 'undefined') return;
  try {
    for (const key of ['isAdmin', 'isTerry', 'cf_user', 'cf_logged_in', 'cf_device_token']) {
      localStorage.removeItem(key);
    }
  } catch {}
  for (const name of ['cf_admin', 'cf_admin_global', 'cf_device_token']) {
    document.cookie = `${name}=; Max-Age=0; path=/; SameSite=Lax`;
    if (window.location.hostname.endsWith('.terrymarr.com')) {
      document.cookie = `${name}=; Max-Age=0; path=/; domain=.terrymarr.com; SameSite=Lax`;
    }
  }
}

export function isAnalyticsTestMode() {
  try {
    if (typeof window === "undefined") return false;

    const v = window.localStorage.getItem("analyticsTestMode") || "";

    return (
      v === "1" ||
      v.toLowerCase() === "true" ||
      v.toLowerCase() === "yes"
    );
  } catch {
    return false;
  }
}

// Analytics preference only: never a credential or permission to use admin APIs.
const INTERNAL_VIEWER_KEY = "cf_internal_viewer";
const truthy = value => ["1", "true", "yes"].includes(String(value || "").trim().toLowerCase());

export function markInternalViewer() {
  if (typeof window === "undefined") return;
  try { window.localStorage.setItem(INTERNAL_VIEWER_KEY, "1"); } catch {}
  const secure = window.location.protocol === "https:" ? "; Secure" : "";
  try { document.cookie = `${INTERNAL_VIEWER_KEY}=1; Max-Age=31536000; path=/; SameSite=Lax${secure}`; } catch {}
}

export function isInternalViewer() {
  if (typeof window === "undefined") return false;
  let marked = verifiedAdmin;
  try {
    const params = new URLSearchParams(window.location.search);
    marked ||= truthy(params.get("cf_internal")) || truthy(params.get("internal_viewer"));
  } catch {}
  try { marked ||= truthy(window.localStorage.getItem(INTERNAL_VIEWER_KEY)); } catch {}
  try {
    marked ||= document.cookie.split(";").some(cookie => {
      const [name, ...value] = cookie.trim().split("=");
      return name === INTERNAL_VIEWER_KEY && truthy(value.join("="));
    });
  } catch {}
  if (marked) markInternalViewer(); // Keep the cookie and local preference in sync.
  return marked;
}

export function shouldIgnoreAnalytics() {
  return isInternalViewer() && !isAnalyticsTestMode();
}
