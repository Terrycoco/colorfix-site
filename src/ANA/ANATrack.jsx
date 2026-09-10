import { useEffect, useRef } from "react";
import { useANA } from "./useANA";
import { resolveSessionId } from "./ANAProvider";

export default function ANATrack({
  eventKey = "visit",
  payload = {},
  resource = {},
  oncePerSession = false,
  children,
}) {
  const { track } = useANA();
  const tracked = useRef(false);

  useEffect(() => {
    if (tracked.current) return;

    if (oncePerSession) {
      const sessionId = resolveSessionId();

      const resourceType = resource?.resource_type ?? "";
      const resourceId = resource?.resource_id ?? "";

      const storageKey = [
        "ana_once",
        sessionId,
        eventKey,
        resourceType,
        resourceId,
      ].join(":");

      /*
       * sessionStorage is an optimization for deduping, not a requirement
       * for rendering or recording ANA events.
       *
       * Some mobile/private/browser contexts may throw on storage access.
       * In that case, continue with tracking rather than letting analytics
       * break the page.
       */
      try {
        if (typeof window !== "undefined" && window.sessionStorage) {
          if (window.sessionStorage.getItem(storageKey) === "1") {
            tracked.current = true;
            return;
          }

          window.sessionStorage.setItem(storageKey, "1");
        }
      } catch (error) {
        console.warn("ANA once-per-session storage unavailable:", error);
      }
    }

    tracked.current = true;
    track(eventKey, payload, resource);
  }, [eventKey, payload, resource, oncePerSession, track]);

  return children;
}
