/**
 * Shared Admin record-search conventions.
 *
 * - A whole number ("133") or hash-prefixed whole number ("#133") means an
 *   EXACT record-ID lookup.
 * - Other input is a case-insensitive substring search of configured text fields.
 * - Empty input matches all records.
 *
 * These helpers are also safe for server-backed lists: parseAdminSearch() tells
 * the page what to send to its department-owned search endpoint.
 */

export function parseAdminSearch(value) {
  const raw = String(value ?? "").trim();
  if (!raw) return { type: "all", value: "" };

  const match = /^#?([0-9]+)$/.exec(raw);
  if (match) {
    // Compare strings rather than JS Numbers so large DB IDs are not rounded.
    const id = match[1].replace(/^0+(?=\d)/, "");
    return { type: "id", value: id };
  }

  return { type: "text", value: raw };
}

function readField(record, path) {
  if (typeof path === "function") return path(record);
  if (typeof path !== "string" || !path) return undefined;
  return path.split(".").reduce((value, key) => value?.[key], record);
}

/** Exact ID matching on a record already loaded by its owning page. */
export function matchesAdminSearch(record, query, {
  idField = "id",
  textFields = ["name"],
} = {}) {
  const parsed = typeof query === "object" && query?.type
    ? query
    : parseAdminSearch(query);

  if (parsed.type === "all") return true;

  if (parsed.type === "id") {
    const id = readField(record, idField);
    if (id == null || id === "") return false;
    const normalized = String(id).trim().replace(/^0+(?=\d)/, "");
    return normalized === parsed.value;
  }

  const needle = String(parsed.value).toLocaleLowerCase();
  return textFields.some((field) => {
    const value = readField(record, field);
    return value != null && String(value).toLocaleLowerCase().includes(needle);
  });
}

export function filterAdminRecords(records, query, options) {
  if (!Array.isArray(records)) return [];
  const parsed = parseAdminSearch(query);
  return records.filter((record) => matchesAdminSearch(record, parsed, options));
}
