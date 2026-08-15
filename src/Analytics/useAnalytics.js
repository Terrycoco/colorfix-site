import { useCallback, useContext } from "react";
import { getCurrentSourceParam } from "@helpers/sourceParam";
import { recordAnalyticsEvent } from "./analyticsClient";
import { AnalyticsContext } from "./AnalyticsProvider";

export function useAnalytics() {
  const context = useContext(AnalyticsContext);
  const rex = context?.rex || null;

  const track = useCallback(
    async (eventKey, payload = {}) => {
      const event = {
        event_key: eventKey,

        reservation_id: rex?.reservation_id ?? null,
        reservation_token: rex?.token ?? null,

        resolver_key: rex?.resolver_key ?? null,
        resource_type: rex?.resource_type ?? null,
        resource_id: rex?.resource_id ?? null,
        experience_key: rex?.context?.experience_key ?? null,

        src: getCurrentSourceParam() || null,
        viewer_id: context?.viewer_id ?? null,
        path: window.location.pathname,

        payload,
      };

      try {
        return await recordAnalyticsEvent(event);
      } catch (error) {
        console.error("Analytics event failed:", error);
        return null;
      }
    },
    [context, rex]
  );

  return { track };
}