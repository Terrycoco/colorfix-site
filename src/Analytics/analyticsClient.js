import { API_FOLDER } from "@helpers/config";
import { recordANAEvent } from "@ANA/ANAClient";

const RECORD_URL = `${API_FOLDER}/v2/admin/analytics/record-event.php`;

export function recordAnalyticsEvent(event) {
  return recordANAEvent(event, RECORD_URL);
}
