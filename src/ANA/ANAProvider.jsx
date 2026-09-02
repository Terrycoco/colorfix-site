import { createContext, useMemo } from "react";
import { getCurrentSourceParam } from "@helpers/sourceParam";

const ANA_SOURCE_KEY = "ana_src";
const ANA_SESSION_KEY = "ana_session_id";
const ANA_REFERRER_KEY = "ana_referrer";

export const ANAContext = createContext({
  rex: null,
  viewer_id: null,
  src: null,
  session_id: null,
  referrer: null,
});


export function resolveSessionId() {
  if (typeof window === "undefined") return null;

  try {
    const existing = sessionStorage.getItem(ANA_SESSION_KEY);
    if (existing) return existing;

    const next =
      typeof crypto !== "undefined" && typeof crypto.randomUUID === "function"
        ? crypto.randomUUID()
        : `ana_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;

    sessionStorage.setItem(ANA_SESSION_KEY, next);

    return next;
  } catch {
    return null;
  }
}

function resolveSource() {
  if (typeof window === "undefined") return null;

  const currentSource = getCurrentSourceParam();

  if (currentSource) {
    try {
      sessionStorage.setItem(ANA_SOURCE_KEY, currentSource);
    } catch {
      // Ignore storage failures.
    }

    return currentSource;
  }

  try {
    return sessionStorage.getItem(ANA_SOURCE_KEY) || null;
  } catch {
    return null;
  }
}

function resolveReferrer() {
  if (typeof window === "undefined") return null;

  try {
    const existing = sessionStorage.getItem(ANA_REFERRER_KEY);
    if (existing !== null) {
      return existing || null;
    }

    const referrer = String(document.referrer || "").trim();

    sessionStorage.setItem(ANA_REFERRER_KEY, referrer);

    return referrer || null;
  } catch {
    return String(document.referrer || "").trim() || null;
  }
}

export default function ANAProvider({
  rex = null,
  viewerId = null,
  children,
}) {
  const src = resolveSource();
  const sessionId = resolveSessionId();
  const referrer = resolveReferrer();

  const value = useMemo(
    () => ({
      rex,
      viewer_id: viewerId,
      src,
      session_id: sessionId,
      referrer: referrer,
    }),
    [rex, viewerId, src, sessionId, referrer]
  );

  return (
    <ANAContext.Provider value={value}>
      {children}
    </ANAContext.Provider>
  );
}