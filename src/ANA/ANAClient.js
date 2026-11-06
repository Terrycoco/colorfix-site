import { shouldIgnoreAnalytics, isAnalyticsTestMode, isInternalViewer } from "@helpers/authHelper";
import { API_FOLDER } from "@helpers/config";

const RECORD_URL = `${API_FOLDER}/v2/admin/ana/record-event.php`;

export async function recordANAEvent(event, endpoint = RECORD_URL) {
  // This also covers callers outside useANA, such as route REX entrances.
  if (shouldIgnoreAnalytics()) return { ok: true, event_id: 0 };
  const res = await fetch(endpoint, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify(isAnalyticsTestMode() ? { ...event, is_test: 1 } : event),
    keepalive: true,
  });

  const data = await res.json();
  isInternalViewer(); // Persist a server-recognized own-device cookie locally.

  if (!res.ok || !data?.ok) {
    throw new Error(data?.error || "Failed to record ANA event");
  }

  return data;
}