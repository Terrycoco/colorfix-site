import { useCallback, useContext } from "react";
import { getCurrentSourceParam } from "@helpers/sourceParam";
import { recordAnalyticsEvent } from "./analyticsClient";
import { AnalyticsContext } from "./AnalyticsProvider";
import { isAdmin, isAnalyticsTestMode } from "@helpers/authHelper";

export function useAnalytics() {
  const context = useContext(AnalyticsContext);
  const rex = context?.rex || null;

  const track = useCallback(
   async (eventKey, payload = {}, resource = {}) => {
      if (isAdmin() && !isAnalyticsTestMode()) return null;
      const event = {
        event_key: eventKey,
        is_test: isAnalyticsTestMode() ? 1 : 0,
        reservation_id: rex?.reservation_id ?? null,
        reservation_token: rex?.token ?? null,

        resolver_key: rex?.resolver_key ?? null,
        resource_type: resource.resource_type ?? rex?.resource_type ?? null,
        resource_id: resource.resource_id ?? rex?.resource_id ?? null,
        experience_key: resource.experience_key ?? rex?.context?.experience_key ?? null,

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