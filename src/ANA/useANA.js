import { useCallback, useContext } from "react";
import { recordANAEvent } from "./ANAClient";
import { ANAContext } from "./ANAProvider";
import { isAdmin, isAnalyticsTestMode } from "@helpers/authHelper";

export function useANA() {
  const context = useContext(ANAContext);
  const rex = context?.rex || null;

  const track = useCallback(
    async (eventKey, payload = {}, resource = {}) => {
      if (isAdmin() && !isAnalyticsTestMode()) return null;

      const event = {
        event_key: eventKey,
        is_test: isAnalyticsTestMode() ? 1 : 0,

        reservation_id: rex?.reservation_id ?? null,
  

        resolver_key: rex?.resolver_key ?? null,
        resource_type:
          resource.resource_type ?? rex?.resource_type ?? null,
        resource_id:
          resource.resource_id ?? rex?.resource_id ?? null,
          experience_key:
          resource.experience_key ??
          rex?.experience_key ??
          null,

        src: context?.src ?? null,
        session_id: context?.session_id ?? null,

        referrer: context?.referrer ?? null,
        viewer_id: context?.viewer_id ?? null,
        path: window.location.pathname,

        payload,
      };

      try {
        return await recordANAEvent(event);
      } catch (error) {
        console.error("ANA event failed:", error);
        return null;
      }
    },
    [context, rex]
  );

  return { track };
}