import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { API_FOLDER, SHARE_FOLDER } from "@helpers/config";
import "./admin-playlist-instances.css";

const LIST_URL = `${API_FOLDER}/v2/admin/playlist-instances/list.php`;
const GET_URL = `${API_FOLDER}/v2/admin/playlist-instances/get.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/playlist-instances/save.php`;
const PLAYLISTS_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;

const emptyInstance = {
  playlist_instance_id: null,
  playlist_id: "",
  instance_name: "",
  instance_notes: "",
  intro_layout: "default",
  intro_title: "",
  intro_subtitle: "",
  intro_body: "",
  intro_image_url: "",
  cta_group_id: "",
  cta_context_key: "default",
  share_enabled: true,
  share_title: "",
  share_description: "",
  share_image_url: "",
  skip_intro_on_replay: true,
  hide_stars: false,
  is_active: true,
  created_from_instance: "",
};

function coerceBoolean(value) {
  return Boolean(value);
}

export default function AdminPlaylistInstancesPage() {
  const navigate = useNavigate();
  const [query, setQuery] = useState("");
  const [items, setItems] = useState([]);
  const [playlists, setPlaylists] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [activeId, setActiveId] = useState(null);
  const [form, setForm] = useState(emptyInstance);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState("");
  const [saveStatus, setSaveStatus] = useState("");

  useEffect(() => {
    fetchPlaylists();
  }, []);

  useEffect(() => {
    fetchInstances();
  }, []);

  useEffect(() => {
    if (!activeId) return;
    fetchInstance(activeId);
  }, [activeId]);


  async function fetchPlaylists() {
    try {
      const res = await fetch(`${PLAYLISTS_URL}?_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load playlists");
      setPlaylists(data.items || []);
    } catch (err) {
      // playlist list is optional for now
    }
  }

  async function fetchInstances() {
    setLoading(true);
    setError("");
    try {
      const qs = new URLSearchParams();
      if (query.trim()) qs.set("q", query.trim());
      qs.set("_", Date.now().toString());
      const res = await fetch(`${LIST_URL}?${qs.toString()}`, {
        credentials: "include",
      });
      const text = await res.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch (parseError) {
        throw new Error(`Unexpected response: ${text.slice(0, 200)}`);
      }
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load instances");
      setItems(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load instances");
    } finally {
      setLoading(false);
    }
  }

  async function fetchInstance(id) {
    setError("");
    try {
      const res = await fetch(`${GET_URL}?id=${id}&_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load instance");
      const next = {
        ...emptyInstance,
        ...data.item,
        share_enabled: coerceBoolean(data.item?.share_enabled),
        skip_intro_on_replay: coerceBoolean(data.item?.skip_intro_on_replay),
        hide_stars: coerceBoolean(data.item?.hide_stars),
        is_active: coerceBoolean(data.item?.is_active),
      };
      setForm(next);
      setSaveStatus("");
      setSaveError("");
    } catch (err) {
      setError(err?.message || "Failed to load instance");
    }
  }

  function updateForm(field, value) {
    setForm((prev) => ({ ...prev, [field]: value }));
    setSaveStatus("");
    setSaveError("");
  }

  function handleNew() {
    setActiveId(null);
    setForm(emptyInstance);
    setSaveStatus("");
    setSaveError("");
  }

  function buildShareUrl(id) {
    if (!id) return "";
    return `${SHARE_FOLDER}/playlist.php?id=${id}`;
  }

  function handleShare() {
    const id = activeId;
    if (!id) return;
    const url = buildShareUrl(id);
    if (navigator.share) {
      navigator.share({ title: "ColorFix Playlist", url }).catch(() => {});
      return;
    }
    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(url).catch(() => {});
    }
    const body = encodeURIComponent(url);
    window.location.href = `sms:&body=${body}`;
  }

  async function handleSave() {
    setSaving(true);
    setSaveError("");
    setSaveStatus("");
    try {
      const payload = {
        ...form,
        playlist_id: Number(form.playlist_id) || 0,
        cta_group_id: form.cta_group_id === "" ? null : Number(form.cta_group_id),
        created_from_instance: form.created_from_instance === "" ? null : Number(form.created_from_instance),
      };
      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Save failed");
      setSaveStatus("Saved");
      const newId = data.playlist_instance_id;
      setActiveId(newId);
      setForm((prev) => ({ ...prev, playlist_instance_id: newId }));
      fetchInstances();
    } catch (err) {
      setSaveError(err?.message || "Save failed");
    } finally {
      setSaving(false);
    }
  }

  const filteredItems = useMemo(() => {
    if (!query.trim()) return items;
    const needle = query.trim().toLowerCase();
    return (items || []).filter((item) => {
      const name = (item?.instance_name || "").toLowerCase();
      const id = String(item?.playlist_instance_id || "");
      return name.includes(needle) || id.includes(needle);
    });
  }, [items, query]);

  const playlistOptions = useMemo(() => {
    return playlists.map((row) => ({
      id: row.playlist_id,
      label: `${row.playlist_id} — ${row.title}`,
    }));
  }, [playlists]);

  return (
    <div className="admin-playlist-instances">
      <div className="playlist-panel list-panel">
        <div className="panel-header">
          <div className="panel-title">Playlist Instances</div>
          <div className="header-actions">
           
            <button type="button" className="primary-btn" onClick={handleNew}>
              New Instance
            </button>
          </div>
        </div>

        <div className="panel-controls">
          <input
            type="text"
            placeholder="Search by id or name"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
          <button type="button" onClick={fetchInstances}>
            Refresh
          </button>
        </div>

        {loading && <div className="panel-status">Loading…</div>}
        {error && <div className="panel-status error">{error}</div>}

        <div className="panel-list">
          {filteredItems.map((item) => (
            <button
              type="button"
              key={item.playlist_instance_id}
              className={`list-row ${activeId === item.playlist_instance_id ? "active" : ""}`}
              onClick={() => setActiveId(item.playlist_instance_id)}
            >
              <div className="row-title">
                {item.instance_name || "Untitled"}
              </div>
              <div className="row-meta">
                #{item.playlist_instance_id} • Playlist {item.playlist_id}
              </div>
            </button>
          ))}
        </div>

        <div className="mobile-share">
          <div className="mobile-share__title">Share Playlist Instance</div>
          {activeId ? (
            <div className="mobile-share__selected">
              {items.find((item) => item.playlist_instance_id === activeId)?.instance_name || "Untitled"}
            </div>
          ) : (
            <div className="mobile-share__selected">Select an instance to share</div>
          )}
          <button type="button" className="primary-btn" onClick={handleShare} disabled={!activeId}>
            Share via Text
          </button>
          {activeId && (
            <div className="mobile-share__hint">
              Link: {buildShareUrl(activeId)}
            </div>
          )}
        </div>
      </div>

      <div className="playlist-panel editor-panel">
        <div className="panel-header">
          <div className="panel-title">
            {form.playlist_instance_id ? `Instance #${form.playlist_instance_id}` : "New Instance"}
          </div>
          <div className="panel-actions">
             <button
              type="button"
              className="primary-btn"
              onClick={() => navigate("/admin/playlists/new")}
            >
              New Playlist
            </button>
            <button type="button" onClick={() => navigate(`/admin/player-preview/${form.playlist_instance_id || ""}`)}>
              Preview
            </button>
            <button type="button" className="primary-btn" onClick={handleSave} disabled={saving}>
              {saving ? "Saving..." : "Save"}
            </button>
          </div>
        </div>

        <div className="form-grid">
          <label>
            Instance name
            <input
              type="text"
              value={form.instance_name}
              onChange={(e) => updateForm("instance_name", e.target.value)}
            />
          </label>

          <label>
            Playlist
            <select
              value={form.playlist_id}
              onChange={(e) => updateForm("playlist_id", e.target.value)}
            >
              <option value="">Select playlist</option>
              {playlistOptions.map((opt) => (
                <option key={opt.id} value={opt.id}>
                  {opt.label}
                </option>
              ))}
            </select>
            {form.playlist_id && (
              <button
                type="button"
                className="link-btn"
                onClick={() => navigate(`/admin/playlists/${form.playlist_id}`)}
              >
                Edit playlist
              </button>
            )}
          </label>

          <label>
            Instance notes
            <textarea
              rows={3}
              value={form.instance_notes || ""}
              onChange={(e) => updateForm("instance_notes", e.target.value)}
            />
          </label>

        </div>

        <div className="form-grid">
          <label>
            CTA group id (optional)
            <input
              type="number"
              value={form.cta_group_id}
              onChange={(e) => updateForm("cta_group_id", e.target.value)}
            />
          </label>

          <label>
            CTA context key
            <input
              type="text"
              value={form.cta_context_key || ""}
              onChange={(e) => updateForm("cta_context_key", e.target.value)}
            />
          </label>
        </div>

        <div className="form-grid">
          <label className="checkbox-row">
            <input
              type="checkbox"
              checked={form.share_enabled}
              onChange={(e) => updateForm("share_enabled", e.target.checked)}
            />
            Share enabled
          </label>

          <label>
            Share title
            <input
              type="text"
              value={form.share_title || ""}
              onChange={(e) => updateForm("share_title", e.target.value)}
            />
          </label>

          <label>
            Share description
            <textarea
              rows={3}
              value={form.share_description || ""}
              onChange={(e) => updateForm("share_description", e.target.value)}
            />
          </label>

          <label>
            Share image URL
            <input
              type="text"
              value={form.share_image_url || ""}
              onChange={(e) => updateForm("share_image_url", e.target.value)}
            />
          </label>
        </div>

        <div className="form-grid">
          <label className="checkbox-row">
            <input
              type="checkbox"
              checked={form.skip_intro_on_replay}
              onChange={(e) => updateForm("skip_intro_on_replay", e.target.checked)}
            />
            Skip intro on replay
          </label>

          <label className="checkbox-row">
            <input
              type="checkbox"
              checked={form.hide_stars}
              onChange={(e) => updateForm("hide_stars", e.target.checked)}
            />
            Hide stars
          </label>

          <label className="checkbox-row">
            <input
              type="checkbox"
              checked={form.is_active}
              onChange={(e) => updateForm("is_active", e.target.checked)}
            />
            Active
          </label>

          <label>
            Created from instance (optional)
            <input
              type="number"
              value={form.created_from_instance}
              onChange={(e) => updateForm("created_from_instance", e.target.value)}
            />
          </label>
        </div>

        {saveStatus && <div className="panel-status success">{saveStatus}</div>}
        {saveError && <div className="panel-status error">{saveError}</div>}
      </div>
    </div>
  );
}
