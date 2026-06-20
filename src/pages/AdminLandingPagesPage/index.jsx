import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./admin-landing-pages.css";

const LIST_URL = `${API_FOLDER}/v2/admin/landing-pages/list.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/landing-pages/save.php`;
const INSTANCES_URL = `${API_FOLDER}/v2/admin/playlist-instances/list.php`;
const ASSETS_URL = `${API_FOLDER}/v2/admin/asset-library/list.php`;

const emptyForm = {
  id: "",
  slug: "",
  title: "",
  search_title: "",
  description: "",
  status: "draft",
  page_type: "playlist",
  primary_playlist_instance_id: "",
  featured_pin_asset_id: "",
  redirect_url: "",
};

export default function AdminLandingPagesPage() {
  const [items, setItems] = useState([]);
  const [instances, setInstances] = useState([]);
  const [assets, setAssets] = useState([]);
  const [form, setForm] = useState(emptyForm);
  const [q, setQ] = useState("");
  const [assetQ, setAssetQ] = useState("");
  const [selectedId, setSelectedId] = useState(null);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");

  const selected = useMemo(() => {
    return items.find((item) => Number(item.id) === Number(selectedId)) || null;
  }, [items, selectedId]);

  useEffect(() => {
    fetchItems();
    fetchInstances();
    fetchAssets("");
  }, []);

  useEffect(() => {
    if (selected) {
      setForm(fromItem(selected));
    }
  }, [selected]);

  async function fetchItems(nextQ = q) {
    setLoading(true);
    setError("");
    try {
      const params = new URLSearchParams({ _: String(Date.now()) });
      if (nextQ.trim()) params.set("q", nextQ.trim());
      const res = await fetch(`${LIST_URL}?${params.toString()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load landing pages");
      setItems(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load landing pages");
    } finally {
      setLoading(false);
    }
  }

  async function fetchInstances() {
    try {
      const res = await fetch(`${INSTANCES_URL}?active=1&_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load playlist instances");
      setInstances(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load playlist instances");
    }
  }

  async function fetchAssets(nextQ = assetQ) {
    try {
      const params = new URLSearchParams({ asset_kind: "image", limit: "80", _: String(Date.now()) });
      if (nextQ.trim()) params.set("q", nextQ.trim());
      const res = await fetch(`${ASSETS_URL}?${params.toString()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load assets");
      setAssets(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load assets");
    }
  }

  function newPage() {
    setSelectedId(null);
    setForm(emptyForm);
    setError("");
    setStatus("");
  }

  function update(field, value) {
    setForm((prev) => {
      const next = { ...prev, [field]: value };
      if (field === "title" && !prev.id && !prev.slug) {
        next.slug = slugify(value);
      }
      return next;
    });
  }

  async function save(event) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setStatus("");
    try {
      const payload = {
        ...form,
        id: form.id ? Number(form.id) : 0,
        primary_playlist_instance_id: form.primary_playlist_instance_id ? Number(form.primary_playlist_instance_id) : null,
        featured_pin_asset_id: form.featured_pin_asset_id ? Number(form.featured_pin_asset_id) : null,
      };
      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to save landing page");
      setStatus("Landing page saved.");
      setSelectedId(data.item?.id || null);
      await fetchItems("");
    } catch (err) {
      setError(err?.message || "Failed to save landing page");
    } finally {
      setSaving(false);
    }
  }

  async function copyUrl(url) {
    if (!url) return;
    await navigator.clipboard.writeText(url);
    setStatus("URL copied.");
  }

  const previewUrl = form.slug ? `${window.location.origin}/s/${encodeURIComponent(form.slug)}` : "";
  const pinterestUrl = previewUrl ? `${previewUrl}?src=pinterest` : "";

  return (
    <div className="admin-landing-pages">
      <header className="landadmin-header">
        <div>
          <h1>Landing Pages</h1>
          <p>Stable public URLs for Pinterest, YouTube, email, QR codes, and future publishing.</p>
        </div>
        <button type="button" className="landadmin-command landadmin-command--primary" onClick={newPage}>
          New Landing Page
        </button>
      </header>

      <div className="landadmin-toolbar">
        <label>
          Search
          <input value={q} onChange={(event) => setQ(event.target.value)} />
        </label>
        <button type="button" onClick={() => fetchItems(q)} disabled={loading}>Find</button>
        <button type="button" onClick={() => fetchItems("")} disabled={loading}>Refresh</button>
      </div>

      {error ? <div className="landadmin-status landadmin-status--error">{error}</div> : null}
      {status ? <div className="landadmin-status landadmin-status--ok">{status}</div> : null}

      <div className="landadmin-layout">
        <section className="landadmin-table-wrap">
          <table className="landadmin-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Status</th>
                <th>Type</th>
                <th>Slug</th>
                <th>Title</th>
                <th>Playlist</th>
                <th>Pin Asset</th>
                <th>Updated</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr
                  key={item.id}
                  className={Number(item.id) === Number(selectedId) ? "is-selected" : ""}
                  onClick={() => setSelectedId(item.id)}
                >
                  <td>{item.id}</td>
                  <td>{item.status}</td>
                  <td>{item.page_type}</td>
                  <td>/s/{item.slug}</td>
                  <td>{item.title}</td>
                  <td>{item.primary_playlist_instance_id ? `#${item.primary_playlist_instance_id} ${item.primary_playlist_display_title || item.primary_playlist_instance_name || ""}` : "-"}</td>
                  <td>{item.featured_pin_asset_id || "-"}</td>
                  <td>{item.updated_at || "-"}</td>
                </tr>
              ))}
              {!loading && items.length === 0 ? (
                <tr><td colSpan={8} className="landadmin-empty">No landing pages yet.</td></tr>
              ) : null}
            </tbody>
          </table>
        </section>

        <form className="landadmin-form" onSubmit={save}>
          <h2>{form.id ? `Edit Landing Page #${form.id}` : "Create Landing Page"}</h2>

          <label>
            Title
            <input value={form.title} onChange={(event) => update("title", event.target.value)} required />
          </label>

          <label>
            Slug
            <input value={form.slug} onChange={(event) => update("slug", slugify(event.target.value))} required />
          </label>

          <label>
            Search title
            <input value={form.search_title} onChange={(event) => update("search_title", event.target.value)} />
          </label>

          <div className="landadmin-row">
            <label>
              Status
              <select value={form.status} onChange={(event) => update("status", event.target.value)}>
                <option value="draft">draft</option>
                <option value="public">public</option>
                <option value="archived">archived</option>
              </select>
            </label>
            <label>
              Page type
              <select value={form.page_type} onChange={(event) => update("page_type", event.target.value)}>
                <option value="playlist">playlist</option>
                <option value="collection">collection</option>
                <option value="article">article</option>
                <option value="redirect">redirect</option>
              </select>
            </label>
          </div>

          <label>
            Primary playlist instance
            <select value={form.primary_playlist_instance_id} onChange={(event) => update("primary_playlist_instance_id", event.target.value)}>
              <option value="">None</option>
              {instances.map((instance) => (
                <option key={instance.playlist_instance_id} value={instance.playlist_instance_id}>
                  #{instance.playlist_instance_id} {instance.display_title || instance.instance_name || `Playlist ${instance.playlist_id}`}
                </option>
              ))}
            </select>
          </label>

          <label>
            Featured pin asset
            <select value={form.featured_pin_asset_id} onChange={(event) => update("featured_pin_asset_id", event.target.value)}>
              <option value="">None</option>
              {assets.map((asset) => (
                <option key={asset.asset_library_id} value={asset.asset_library_id}>
                  #{asset.asset_library_id} {asset.title || asset.rel_path || "Asset"}
                </option>
              ))}
            </select>
          </label>

          <div className="landadmin-asset-search">
            <label>
              Asset search
              <input value={assetQ} onChange={(event) => setAssetQ(event.target.value)} placeholder="Search generated pins" />
            </label>
            <button type="button" onClick={() => fetchAssets(assetQ)}>Find Assets</button>
          </div>

          <label>
            Redirect URL
            <input value={form.redirect_url} onChange={(event) => update("redirect_url", event.target.value)} />
          </label>

          <label>
            Description
            <textarea rows={5} value={form.description} onChange={(event) => update("description", event.target.value)} />
          </label>

          <div className="landadmin-preview">
            <div>
              <strong>Public URL</strong>
              <span>{previewUrl || "-"}</span>
            </div>
            <button type="button" onClick={() => copyUrl(previewUrl)} disabled={!previewUrl}>Copy</button>
          </div>
          <div className="landadmin-preview">
            <div>
              <strong>Pinterest URL</strong>
              <span>{pinterestUrl || "-"}</span>
            </div>
            <button type="button" onClick={() => copyUrl(pinterestUrl)} disabled={!pinterestUrl}>Copy</button>
          </div>

          <div className="landadmin-actions">
            {previewUrl ? <a href={previewUrl} target="_blank" rel="noreferrer">Preview</a> : null}
            <button type="submit" className="landadmin-command--primary" disabled={saving}>
              {saving ? "Saving..." : "Save Landing Page"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function fromItem(item) {
  return {
    id: item.id || "",
    slug: item.slug || "",
    title: item.title || "",
    search_title: item.search_title || "",
    description: item.description || "",
    status: item.status || "draft",
    page_type: item.page_type || "playlist",
    primary_playlist_instance_id: item.primary_playlist_instance_id || "",
    featured_pin_asset_id: item.featured_pin_asset_id || "",
    redirect_url: item.redirect_url || "",
  };
}

function slugify(value) {
  return String(value || "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 160);
}
