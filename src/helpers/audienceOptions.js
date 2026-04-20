import { API_FOLDER } from "@helpers/config";

const AUDIENCE_TYPES_URL = `${API_FOLDER}/v2/admin/audience-types/list.php`;

export const DEFAULT_AUDIENCE_OPTIONS = [
  { value: "any", label: "Any" },
  { value: "qr", label: "QR" },
  { value: "hoa", label: "HOA" },
  { value: "homeowner", label: "Homeowner" },
  { value: "contractor", label: "Contractor" },
  { value: "pinterest", label: "Pinterest" },
  { value: "admin", label: "Admin" },
];

export const DEFAULT_AUDIENCE_FILTER_OPTIONS = [
  { value: "all", label: "All audiences" },
  ...DEFAULT_AUDIENCE_OPTIONS,
];

export async function fetchAudienceOptions() {
  const res = await fetch(`${AUDIENCE_TYPES_URL}?_=${Date.now()}`, {
    credentials: "include",
  });
  const data = await res.json();
  if (!res.ok || !data?.ok) {
    throw new Error(data?.error || "Failed to load audiences");
  }

  const items = Array.isArray(data.items) ? data.items : [];
  return items.map((item) => ({
    value: String(item.key || "").trim(),
    label: String(item.label || item.key || "").trim(),
  })).filter((item) => item.value && item.label);
}
