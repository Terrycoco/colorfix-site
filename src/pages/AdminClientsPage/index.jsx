import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./admin-clients.css";

const LIST_URL = `${API_FOLDER}/v2/admin/clients/list.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/clients/save.php`;
const DELETE_URL = `${API_FOLDER}/v2/admin/clients/delete.php`;

const EMPTY_FORM = {
  id: "",
  name: "",
  email: "",
  phone: "",
  notes: "",
  photo_count: 0,
  applied_palette_count: 0,
  share_count: 0,
};

function usageSummary(client) {
  const photoCount = Number(client?.photo_count || 0);
  const appliedCount = Number(client?.applied_palette_count || 0);
  const shareCount = Number(client?.share_count || 0);
  const parts = [];
  if (photoCount) parts.push(`${photoCount} photo${photoCount === 1 ? "" : "s"}`);
  if (appliedCount) parts.push(`${appliedCount} palette link${appliedCount === 1 ? "" : "s"}`);
  if (shareCount) parts.push(`${shareCount} share${shareCount === 1 ? "" : "s"}`);
  return parts.length ? parts.join(" • ") : "No linked records";
}

export default function AdminClientsPage() {
  const [query, setQuery] = useState("");
  const [items, setItems] = useState([]);
  const [selectedId, setSelectedId] = useState("");
  const [form, setForm] = useState(EMPTY_FORM);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  async function loadClients(preferredId = null) {
    setLoading(true);
    setError("");
    try {
      const params = new URLSearchParams();
      if (query.trim()) params.set("q", query.trim());
      params.set("limit", "500");
      params.set("_", String(Date.now()));
      const res = await fetch(`${LIST_URL}?${params.toString()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load clients");
      const nextItems = Array.isArray(data.items) ? data.items : [];
      setItems(nextItems);

      const targetId = preferredId ?? selectedId;
      const target = nextItems.find((item) => String(item.id) === String(targetId));
      if (target) {
        selectClient(target);
      } else if (!targetId && nextItems[0]) {
        selectClient(nextItems[0]);
      } else if (!target && targetId) {
        setSelectedId("");
        setForm(EMPTY_FORM);
      }
    } catch (err) {
      setError(err?.message || "Failed to load clients");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void loadClients();
  }, [query]);

  function selectClient(client) {
    const next = {
      id: String(client?.id || ""),
      name: client?.name || "",
      email: client?.email || "",
      phone: client?.phone || "",
      notes: client?.notes || "",
      photo_count: Number(client?.photo_count || 0),
      applied_palette_count: Number(client?.applied_palette_count || 0),
      share_count: Number(client?.share_count || 0),
    };
    setSelectedId(next.id);
    setForm(next);
    setError("");
    setNotice("");
  }

  function startNew() {
    setSelectedId("");
    setForm(EMPTY_FORM);
    setError("");
    setNotice("");
  }

  function updateField(key, value) {
    setForm((prev) => ({ ...prev, [key]: value }));
    setNotice("");
  }

  async function handleSave(event) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setNotice("");
    try {
      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          id: form.id ? Number(form.id) : null,
          name: form.name,
          email: form.email,
          phone: form.phone,
          notes: form.notes,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to save client");
      setNotice(form.id ? "Client updated." : "Client created.");
      await loadClients(String(data.client?.id || ""));
    } catch (err) {
      setError(err?.message || "Failed to save client");
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete() {
    if (!form.id) return;
    const summary = usageSummary(form);
    const confirmed = window.confirm(
      `Delete client "${form.name || form.email || `#${form.id}`}"?\n\n${summary}\n\nPhoto links will be cleared. Related palette/share links for this client will be removed.`
    );
    if (!confirmed) return;

    setDeleting(true);
    setError("");
    setNotice("");
    try {
      const res = await fetch(DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id: Number(form.id) }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to delete client");
      setNotice("Client deleted.");
      setSelectedId("");
      setForm(EMPTY_FORM);
      await loadClients();
    } catch (err) {
      setError(err?.message || "Failed to delete client");
    } finally {
      setDeleting(false);
    }
  }

  const selectedUsage = useMemo(() => usageSummary(form), [form]);

  return (
    <div className="admin-clients">
      <aside className="admin-clients__sidebar">
        <div className="admin-clients__sidebar-header">
          <div>
            <h1>Clients</h1>
            <p>Edit names, emails, and delete bad records safely.</p>
          </div>
          <button type="button" className="admin-clients__primary" onClick={startNew}>
            New Client
          </button>
        </div>

        <input
          className="admin-clients__search"
          type="search"
          placeholder="Search by name, email, or phone"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
        />

        <div className="admin-clients__list">
          {loading ? <div className="admin-clients__empty">Loading clients…</div> : null}
          {!loading && items.length === 0 ? <div className="admin-clients__empty">No clients found.</div> : null}
          {!loading &&
            items.map((client) => (
              <button
                key={client.id}
                type="button"
                className={`admin-clients__card ${String(client.id) === String(selectedId) ? "is-active" : ""}`}
                onClick={() => selectClient(client)}
              >
                <div className="admin-clients__card-title">{client.name || "Unnamed client"}</div>
                <div className="admin-clients__card-meta">{usageSummary(client)}</div>
              </button>
            ))}
        </div>
      </aside>

      <main className="admin-clients__main">
        <section className="admin-clients__panel">
          <div className="admin-clients__panel-header">
            <div>
              <h2>{form.id ? `Client #${form.id}` : "New Client"}</h2>
              <div className="admin-clients__panel-sub">{selectedUsage}</div>
            </div>
          </div>

          {error ? <div className="admin-clients__message admin-clients__message--error">{error}</div> : null}
          {notice ? <div className="admin-clients__message admin-clients__message--ok">{notice}</div> : null}

          <form className="admin-clients__form" onSubmit={handleSave}>
            <label>
              Name
              <input type="text" value={form.name} onChange={(e) => updateField("name", e.target.value)} />
            </label>
            <label>
              Email
              <input type="email" value={form.email} onChange={(e) => updateField("email", e.target.value)} />
            </label>
            <label>
              Phone
              <input type="text" value={form.phone} onChange={(e) => updateField("phone", e.target.value)} />
            </label>
            <label>
              Notes
              <textarea rows={6} value={form.notes} onChange={(e) => updateField("notes", e.target.value)} />
            </label>

            <div className="admin-clients__actions">
              <button type="submit" className="admin-clients__primary" disabled={saving}>
                {saving ? "Saving…" : form.id ? "Save Client" : "Create Client"}
              </button>
              {form.id ? (
                <button type="button" className="admin-clients__danger" onClick={handleDelete} disabled={deleting}>
                  {deleting ? "Deleting…" : "Delete Client"}
                </button>
              ) : null}
            </div>
          </form>
        </section>
      </main>
    </div>
  );
}
