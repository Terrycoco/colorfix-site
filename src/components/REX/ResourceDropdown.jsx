import { useEffect, useMemo, useState } from "react";
import { AdminField } from "@components/AdminLayout";
import { API_FOLDER } from "@helpers/config";

/**
 * Resource ID selector for REX destinations.
 * Fetches fresh options whenever resourceType changes.
 * API response: { ok: true, items: [{ id: number, title: string }] }
 */
export default function ResourceDropdown({
  resourceType,
  value = "",
  onChange,
  disabled = false,
  label = "Resource",
  endpoint = `${API_FOLDER}/v2/admin/rex/resource-options.php`,
}) {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    const controller = new AbortController();
    setItems([]);
    setError("");
    if (!resourceType) {
      setLoading(false);
      return () => controller.abort();
    }
    setLoading(true);
    const params = new URLSearchParams({ resource_type: resourceType });
    fetch(`${endpoint}?${params}`, {
      credentials: "include",
      signal: controller.signal,
      headers: { Accept: "application/json" },
    })
      .then(async (response) => {
        const data = await response.json();
        if (!response.ok || !data?.ok || !Array.isArray(data.items)) {
          throw new Error(data?.error || `Lookup failed (${response.status}).`);
        }
        return data.items;
      })
      .then((results) => {
        if (controller.signal.aborted) return;
        setItems(results
          .filter((item) => Number.isInteger(Number(item.id)) && Number(item.id) > 0)
          .map((item) => ({ id: String(item.id), title: String(item.title ?? "").trim() }))
          .sort((a, b) => a.title.localeCompare(b.title, undefined, { sensitivity: "base", numeric: true }) || Number(a.id) - Number(b.id)));
      })
      .catch((err) => {
        if (!controller.signal.aborted) setError(err.message || "Could not load resources.");
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [resourceType, endpoint]);

  const selected = String(value ?? "");
  const exists = useMemo(() => items.some((item) => item.id === selected), [items, selected]);

  return (
    <AdminField label={label}>
      <select
        className="admin-field__control"
        value={selected}
        disabled={disabled || !resourceType || loading || Boolean(error)}
        onChange={(event) => onChange?.(event.target.value)}
        aria-label={label}
      >
        <option value="">
          {!resourceType ? "Choose a resource type first..." : loading ? "Loading..." : error ? "Lookup unavailable" : "Choose a resource..."}
        </option>
        {!loading && !error && selected && !exists && (
          <option value={selected}>Unknown resource (ID {selected}) — select a replacement</option>
        )}
        {items.map((item) => (
          <option key={item.id} value={item.id}>{item.title || "Untitled"} ({item.id})</option>
        ))}
      </select>
      {error && <div role="alert">{error}</div>}
      {!loading && !error && resourceType && items.length === 0 && (
        <div>No resources found for this type.</div>
      )}
    </AdminField>
  );
}
