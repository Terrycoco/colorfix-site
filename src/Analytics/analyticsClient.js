import { API_FOLDER } from "@helpers/config";

const RECORD_URL = `${API_FOLDER}/v2/admin/analytics/record-event.php`;

export async function recordAnalyticsEvent(event) {
  const res = await fetch(RECORD_URL, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify(event),
    keepalive: true,
  });

  const data = await res.json();

  if (!res.ok || !data?.ok) {
    throw new Error(data?.error || "Failed to record analytics event");
  }

  return data;
}