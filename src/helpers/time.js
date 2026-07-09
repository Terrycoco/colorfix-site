export const APP_TIME_ZONE = "America/Los_Angeles";

function hasExplicitZone(value) {
  return /(?:z|[+-]\d{2}:?\d{2})$/i.test(String(value || "").trim());
}

export function parseDateTime(value, { sourceTimeZone = "utc" } = {}) {
  const text = String(value || "").trim();
  if (!text) return null;

  const normalized = text.includes("T") ? text : text.replace(" ", "T");
  const input = hasExplicitZone(normalized) || sourceTimeZone === "local"
    ? normalized
    : `${normalized}Z`;
  const date = new Date(input);
  return Number.isNaN(date.getTime()) ? null : date;
}

export function dateTimeSortValue(value, options = {}) {
  return parseDateTime(value, options)?.getTime() || 0;
}

export function formatDateTime(value, {
  sourceTimeZone = "utc",
  timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone || APP_TIME_ZONE,
  includeTimeZone = true,
  fallback = "-",
} = {}) {
  const date = parseDateTime(value, { sourceTimeZone });
  if (!date) return String(value || "").trim() || fallback;

  const formatted = new Intl.DateTimeFormat([], {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
    timeZone,
    timeZoneName: includeTimeZone ? "short" : undefined,
  }).format(date);

  return formatted;
}
