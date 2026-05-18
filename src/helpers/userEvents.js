import { API_FOLDER } from "@helpers/config";
import { isAdmin } from "@helpers/authHelper";

const TRACK_URL = `${API_FOLDER}/v2/user-events/track.php`;
const SESSION_KEY = "cf_user_event_session_id";
const SOURCE_PARAM_KEY = "src";

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

export function trackUserEvent(payload) {
  if (typeof window === "undefined") return;
  if (isAdmin() && !payload?.allow_internal_tracking) return;

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
    is_internal: payload?.is_internal ?? isAdmin(),
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
