import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./admin-url-reservations.css";

const TYPES_URL = `${API_FOLDER}/v2/admin/url-reservations/types.php`;
const CREATE_URL = `${API_FOLDER}/v2/admin/url-reservations/create.php`;
const LIST_URL = `${API_FOLDER}/v2/admin/url-reservations/list.php`;
const REVOKE_URL = `${API_FOLDER}/v2/admin/url-reservations/revoke.php`;

const emptyForm = {
  type_key: "project_experience",
  resource_id: "",
  experience_key: "concept",
  source_key: "",
  label: "",
  og_title: "",
  og_description: "",
  og_image_url: "",
  expires_at: "",
  params: "",
};

const experienceOptions = ["concept", "client", "painter", "public"];

function statusFor(item) {
  if (item?.is_revoked) return "revoked";
  if (item?.is_expired) return "expired";
  if (!item?.is_active) return "inactive";
  return "active";
}

function formatDate(value) {
  if (!value) return "";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
}

export default function AdminUrlReservationsPage() {
  const [types, setTypes] = useState([]);
  const [items, setItems] = useState([]);
  const [form, setForm] = useState(emptyForm);
  const [created, setCreated] = useState(null);
  const [loading, setLoading] = useState(false);
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");

  const activeType = useMemo(
    () => types.find((type) => type.type_key === form.type_key) || null,
    [form.type_key, types]
  );

  useEffect(() => {
    loadTypes();
    loadReservations();
  }, []);

  function update(field, value) {
    setForm((prev) => ({ ...prev, [field]: value }));
  }

  async function loadTypes() {
    try {
      const res = await fetch(`${TYPES_URL}?_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load reservation types");
      setTypes(Array.isArray(data.items) ? data.items : []);
    } catch (err) {
      setError(err?.message || "Failed to load reservation types");
    }
  }

  async function loadReservations() {
    setLoading(true);
    setError("");
    try {
      const res = await fetch(`${LIST_URL}?limit=100&_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load reservations");
      setItems(Array.isArray(data.items) ? data.items : []);
    } catch (err) {
      setError(err?.message || "Failed to load reservations");
    } finally {
      setLoading(false);
    }
  }

  async function createReservation() {
    setLoading(true);
    setStatus("");
    setError("");
    setCreated(null);
    try {
      let params = {};
      if (form.params.trim()) {
        params = JSON.parse(form.params);
      }
      const payload = {
        ...form,
        resource_id: Number(form.resource_id || 0),
        params,
      };
      const res = await fetch(CREATE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to create reservation");
      setCreated(data);
      setStatus("Reservation created.");
      await loadReservations();
    } catch (err) {
      setError(err?.message || "Failed to create reservation");
    } finally {
      setLoading(false);
    }
  }

  async function copyUrl(url) {
    if (!url) return;
    try {
      await navigator.clipboard.writeText(url);
      setStatus("URL copied.");
    } catch {
      setError("Could not copy URL.");
    }
  }

  async function revoke(id) {
    if (!id) return;
    setLoading(true);
    setError("");
    setStatus("");
    try {
      const res = await fetch(REVOKE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to revoke reservation");
      setStatus("Reservation revoked.");
      await loadReservations();
    } catch (err) {
      setError(err?.message || "Failed to revoke reservation");
    } finally {
      setLoading(false);
    }
  }

  return (
    <main className="admin-url-reservations">
      <aside className="admin-url-reservations__sidebar">
        <h1>URL Reservations</h1>
        <p>Standalone test panel for opaque ColorFix reservation links.</p>

        <label>
          <span>Reservation Type</span>
          <select value={form.type_key} onChange={(event) => update("type_key", event.target.value)}>
            {types.map((type) => (
              <option key={type.type_key} value={type.type_key}>
                {type.label}
              </option>
            ))}
          </select>
        </label>
        {activeType ? (
          <div className="admin-url-reservations__meta">
            {activeType.resolver_key} / {activeType.delivery_mode}
          </div>
        ) : null}

        <label>
          <span>Resource ID</span>
          <input value={form.resource_id} onChange={(event) => update("resource_id", event.target.value)} inputMode="numeric" />
        </label>
        <label>
          <span>Experience</span>
          <select value={form.experience_key} onChange={(event) => update("experience_key", event.target.value)}>
            {experienceOptions.map((key) => (
              <option key={key} value={key}>{key}</option>
            ))}
          </select>
        </label>
        <label>
          <span>Source</span>
          <input value={form.source_key} onChange={(event) => update("source_key", event.target.value)} placeholder="email, pinterest, youtube" />
        </label>
        <label>
          <span>Label</span>
          <input value={form.label} onChange={(event) => update("label", event.target.value)} />
        </label>
        <label>
          <span>OG Title</span>
          <input value={form.og_title} onChange={(event) => update("og_title", event.target.value)} />
        </label>
        <label>
          <span>OG Description</span>
          <textarea rows={3} value={form.og_description} onChange={(event) => update("og_description", event.target.value)} />
        </label>
        <label>
          <span>OG Image URL</span>
          <input value={form.og_image_url} onChange={(event) => update("og_image_url", event.target.value)} />
        </label>
        <label>
          <span>Expiration</span>
          <input type="datetime-local" value={form.expires_at} onChange={(event) => update("expires_at", event.target.value)} />
        </label>
        <label>
          <span>Params JSON</span>
          <textarea rows={3} value={form.params} onChange={(event) => update("params", event.target.value)} placeholder='{"return_to":"/admin/projects"}' />
        </label>

        <div className="admin-url-reservations__actions">
          <button type="button" onClick={createReservation} disabled={loading}>Create Reservation</button>
          <button type="button" onClick={loadReservations} disabled={loading}>List Reservations</button>
        </div>
        {created?.public_url ? (
          <div className="admin-url-reservations__created">
            <input readOnly value={created.public_url} onFocus={(event) => event.target.select()} />
            <button type="button" onClick={() => copyUrl(created.public_url)}>Copy URL</button>
          </div>
        ) : null}
        {status ? <div className="admin-url-reservations__status">{status}</div> : null}
        {error ? <div className="admin-url-reservations__status admin-url-reservations__status--error">{error}</div> : null}
      </aside>

      <section className="admin-url-reservations__main">
        <div className="admin-url-reservations__head">
          <h2>Reservations</h2>
          <span>{loading ? "Loading..." : `${items.length} shown`}</span>
        </div>
        <div className="admin-url-reservations__table">
          <div className="admin-url-reservations__row admin-url-reservations__row--head">
            <span>Type</span>
            <span>Resource</span>
            <span>Experience</span>
            <span>Source</span>
            <span>Status</span>
            <span>URL</span>
            <span>Actions</span>
          </div>
          {items.length === 0 ? (
            <div className="admin-url-reservations__empty">No reservations yet.</div>
          ) : items.map((item) => (
            <div key={item.id} className="admin-url-reservations__row">
              <span>{item.type_key}<small>{item.label || item.type_label}</small></span>
              <span>#{item.resource_id}</span>
              <span>{item.experience_key || ""}</span>
              <span>{item.source_key || ""}</span>
              <span className={`admin-url-reservations__badge is-${statusFor(item)}`}>{statusFor(item)}</span>
              <span>
                <input readOnly value={item.public_url || ""} onFocus={(event) => event.target.select()} />
                <small>{formatDate(item.created_at)}</small>
              </span>
              <span className="admin-url-reservations__row-actions">
                <button type="button" onClick={() => copyUrl(item.public_url)}>Copy</button>
                <button type="button" onClick={() => revoke(item.id)} disabled={item.is_revoked}>Revoke</button>
              </span>
            </div>
          ))}
        </div>
      </section>
    </main>
  );
}
