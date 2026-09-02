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

      if (sessionStorage.getItem(storageKey) === "1") {
        tracked.current = true;
        return;
      }

      sessionStorage.setItem(storageKey, "1");
    }

    tracked.current = true;
    track(eventKey, payload, resource);
  }, [eventKey, payload, resource, oncePerSession, track]);

  return children;
}