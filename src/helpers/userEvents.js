import { API_FOLDER } from "@helpers/config";
import { isAdmin } from "@helpers/authHelper";

const TRACK_URL = `${API_FOLDER}/v2/user-events/track.php`;
const SESSION_KEY = "cf_user_event_session_id";
const INTERNAL_VIEWER_KEY = "cf_internal_viewer";
const SOURCE_PARAM_KEY = "src";

function readCookie(name) {
  if (typeof document === "undefined") return "";
  const prefix = `${name}=`;
  return (
    document.cookie
      .split(";")
      .map((part) => part.trim())
      .find((part) => part.startsWith(prefix))
      ?.slice(prefix.length) || ""
  );
}

function setInternalViewerCookie() {
  if (typeof document === "undefined") return;
  const securePart = window.location.protocol === "https:" ? "; Secure" : "";
  document.cookie = `${INTERNAL_VIEWER_KEY}=1; Max-Age=31536000; path=/; SameSite=Lax${securePart}`;
}

function shouldMarkInternalFromUrl() {
  if (typeof window === "undefined") return false;
  try {
    const params = new URLSearchParams(window.location.search);
    const raw = params.get("cf_internal") || params.get("internal_viewer") || "";
    const value = raw.trim().toLowerCase();
    return value === "1" || value === "true" || value === "yes";
  } catch {
    return false;
  }
}

export function markInternalViewer() {
  if (typeof window === "undefined") return;
  try {
    window.localStorage.setItem(INTERNAL_VIEWER_KEY, "1");
  } catch {}
  setInternalViewerCookie();
}

export function isInternalViewer() {
  if (typeof window === "undefined") return false;
  if (isAdmin() || shouldMarkInternalFromUrl()) {
    markInternalViewer();
    return true;
  }
  try {
    const stored = window.localStorage.getItem(INTERNAL_VIEWER_KEY) || "";
    if (stored === "1" || stored.toLowerCase() === "true" || stored.toLowerCase() === "yes") {
      return true;
    }
  } catch {}
  return readCookie(INTERNAL_VIEWER_KEY) === "1";
}

function generateSessionId() {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }
  return `sess_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
}

export function getUserEventSessionId() {
  if (typeof window === "undefined") return "";
  try {
    const existing = window.localStorage.getItem(SESSION_KEY);
    if (existing) return existing;
    const next = generateSessionId();
    window.localStorage.setItem(SESSION_KEY, next);
    return next;
  } catch {
    return generateSessionId();
  }
}

export function isHireTerryCta(cta) {
  if (!cta) return false;
  const ctaId = Number(cta?.cta_id || 0);
  if (ctaId === 20) return true;

  const rawUrl =
    cta?.params?.url ||
    cta?.href ||
    cta?.url ||
    "";
  const normalizedUrl = String(rawUrl).trim().toLowerCase();
  if (normalizedUrl === "/hire-terry" || normalizedUrl.startsWith("/hire-terry?")) {
    return true;
  }

  return false;
}

export function getCtaOnclickEvent(cta) {
  const raw = cta?.onclick || cta?.onClick || cta?.click_event || "";
  return String(raw || "")
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9_-]+/g, "")
    .slice(0, 100);
}

export function trackCtaOnclickEvent({ cta, data, allowInternalTracking = true } = {}) {
  const eventType = getCtaOnclickEvent(cta);
  if (!eventType) return false;

  trackUserEvent({
    event_type: eventType,
    playlist_instance_id: Number(data?.playlist_instance_id || 0),
    playlist_id: Number(data?.playlist_id || 0) || null,
    cta_id: Number(cta?.cta_id || 0) || null,
    allow_internal_tracking: allowInternalTracking,
  });
  return true;
}

export function trackUserEvent(payload) {
  if (typeof window === "undefined") return;
  const internalViewer = isInternalViewer();
  if (internalViewer && !payload?.allow_internal_tracking) return;

  let source = payload?.source || "";
  if (!source) {
    try {
      source = new URLSearchParams(window.location.search).get(SOURCE_PARAM_KEY) || "";
    } catch {
      source = "";
    }
  }
  source = String(source || "")
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9_-]+/g, "")
    .slice(0, 100);

  const body = {
    ...payload,
    source: source || null,
    session_id: payload?.session_id || getUserEventSessionId(),
    referrer: payload?.referrer || document.referrer || "",
    user_agent: payload?.user_agent || navigator.userAgent || "",
    is_internal: payload?.is_internal ?? internalViewer,
  };

  const json = JSON.stringify(body);

  try {
    if (typeof navigator !== "undefined" && typeof navigator.sendBeacon === "function") {
      const blob = new Blob([json], { type: "application/json" });
      if (navigator.sendBeacon(TRACK_URL, blob)) {
        return;
      }
    }
  } catch {
    // fall through to fetch
  }

  fetch(TRACK_URL, {
    method: "POST",
    credentials: "include",
    headers: { "Content-Type": "application/json" },
    body: json,
    keepalive: true,
  }).catch(() => {});
}
