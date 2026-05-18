import { useCallback, useEffect, useRef, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./client-picker-modal.css";

const LIST_URL = `${API_FOLDER}/v2/admin/clients/list.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/clients/save.php`;

function displayClientListName(client) {
  const firstName = String(client?.first_name || "").trim();
  const lastName = String(client?.last_name || "").trim();
  if (lastName && firstName) return `${lastName}, ${firstName}`;
  if (lastName) return lastName;
  if (firstName) return firstName;
  return String(client?.name || "").trim();
}

export default function ClientPickerModal({
  open = false,
  title = "Pick Client",
  onClose,
  onPick,
}) {
  const [query, setQuery] = useState("");
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [createForm, setCreateForm] = useState({ name: "", email: "", phone: "" });
  const [saving, setSaving] = useState(false);
  const inputRef = useRef(null);

  const runSearch = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const params = new URLSearchParams();
      if (query.trim()) params.set("q", query.trim());
      params.set("limit", "100");
      params.set("_", String(Date.now()));
      const res = await fetch(`${LIST_URL}?${params.toString()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Search failed");
      setItems(Array.isArray(data.items) ? data.items : []);
    } catch (err) {
      setItems([]);
      setError(err?.message || "Search failed");
    } finally {
      setLoading(false);
    }
  }, [query]);

  useEffect(() => {
    if (!open) {
      setItems([]);
      setError("");
      setLoading(false);
      setCreateForm({ name: "", email: "", phone: "" });
      return;
    }
    const t = setTimeout(() => inputRef.current?.focus(), 0);
    void runSearch();
    return () => clearTimeout(t);
  }, [open, runSearch]);

  async function handleCreate(event) {
    event.preventDefault();
    if (!createForm.email.trim()) {
      setError("Email is required.");
      return;
    }
    setSaving(true);
    setError("");
    try {
      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          name: createForm.name,
          email: createForm.email,
          phone: createForm.phone,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to save client");
      if (onPick) onPick(data.client);
    } catch (err) {
      setError(err?.message || "Failed to save client");
    } finally {
      setSaving(false);
    }
  }

  if (!open) return null;

  return (
    <div className="cpm-overlay" role="dialog" aria-modal="true">
      <div className="cpm-backdrop" onClick={onClose} />
      <div className="cpm-panel">
        <div className="cpm-header">
          <div className="cpm-title">{title}</div>
          <button type="button" className="cpm-close" onClick={onClose}>
            Close
          </button>
        </div>

        <form
          className="cpm-search"
          onSubmit={(e) => {
            e.preventDefault();
            runSearch();
          }}
        >
          <label className="cpm-label">Search existing clients</label>
          <div className="cpm-search-row">
            <input
              ref={inputRef}
              type="text"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Name, email, or phone"
            />
            <button type="submit" className="cpm-search-btn">
              Search
            </button>
          </div>
        </form>

        {loading && <div className="cpm-status">Loading…</div>}
        {error && <div className="cpm-status error">{error}</div>}

        <div className="cpm-results">
          {items.map((item) => (
            <button
              key={item.id}
              type="button"
              className="cpm-card"
              onClick={() => onPick && onPick(item)}
            >
              <div className="cpm-card-name">{displayClientListName(item) || "Unnamed client"}</div>
              <div className="cpm-card-meta">
                {[`#${item.id}`, item.email, item.phone].filter(Boolean).join(" · ")}
              </div>
            </button>
          ))}
          {!loading && !error && items.length === 0 ? (
            <div className="cpm-status">No clients matched.</div>
          ) : null}
        </div>

        <div className="cpm-divider">Or create a new client</div>

        <form className="cpm-create" onSubmit={handleCreate}>
          <label>
            Name
            <input
              type="text"
              value={createForm.name}
              onChange={(e) => setCreateForm((prev) => ({ ...prev, name: e.target.value }))}
              placeholder="Client name"
            />
          </label>
          <label>
            Email
            <input
              type="email"
              value={createForm.email}
              onChange={(e) => setCreateForm((prev) => ({ ...prev, email: e.target.value }))}
              placeholder="client@example.com"
            />
          </label>
          <label>
            Phone
            <input
              type="text"
              value={createForm.phone}
              onChange={(e) => setCreateForm((prev) => ({ ...prev, phone: e.target.value }))}
              placeholder="Optional"
            />
          </label>
          <div className="cpm-actions">
            <button type="submit" className="cpm-primary" disabled={saving}>
              {saving ? "Saving…" : "Save Client"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
