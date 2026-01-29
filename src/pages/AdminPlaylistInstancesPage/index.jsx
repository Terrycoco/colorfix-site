import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { API_FOLDER, SHARE_FOLDER } from "@helpers/config";
import "./admin-playlist-instances.css";

const LIST_URL = `${API_FOLDER}/v2/admin/playlist-instances/list.php`;
const GET_URL = `${API_FOLDER}/v2/admin/playlist-instances/get.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/playlist-instances/save.php`;
const PLAYLISTS_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;
const CTA_GROUPS_URL = `${API_FOLDER}/v2/admin/cta-groups/list.php`;
const CTA_GROUP_ITEMS_URL = `${API_FOLDER}/v2/admin/cta-group-items/list.php`;

const AUDIENCE_OPTIONS = [
  { value: "any", label: "Any" },
  { value: "hoa", label: "HOA" },
  { value: "homeowner", label: "Homeowner" },
  { value: "contractor", label: "Contractor" },
  { value: "admin", label: "Admin" },
];

const emptyInstance = {
  playlist_instance_id: null,
  playlist_id: "",
  instance_name: "",
  display_title: "",
  instance_notes: "",
  intro_layout: "default",
  intro_title: "",
  intro_subtitle: "",
  intro_body: "",
  intro_image_url: "",
  cta_group_id: "",
  palette_viewer_cta_group_id: "",
  demo_enabled: false,
  audience: "any",
  cta_context_key: "default",
  cta_overrides: {},
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
  const [ctaGroups, setCtaGroups] = useState([]);
  const [ctaGroupItems, setCtaGroupItems] = useState([]);
  const [ctaOverrides, setCtaOverrides] = useState({});
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [activeId, setActiveId] = useState(null);
  const [form, setForm] = useState(emptyInstance);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState("");
  const [saveStatus, setSaveStatus] = useState("");

  useEffect(() => {
    fetchPlaylists();
    fetchCtaGroups();
  }, []);

  useEffect(() => {
    fetchInstances();
  }, []);

  useEffect(() => {
    if (!activeId) return;
    fetchInstance(activeId);
  }, [activeId]);

  useEffect(() => {
    const groupId = form.cta_group_id;
    if (!groupId) {
      setCtaGroupItems([]);
      return;
    }
    fetchCtaGroupItems(groupId);
  }, [form.cta_group_id]);

  useEffect(() => {
    if (!ctaGroupItems.length) return;
    setCtaOverrides((prev) => {
      const allowed = new Set(ctaGroupItems.map((item) => String(item.cta_id)));
      const next = {};
      Object.entries(prev || {}).forEach(([key, value]) => {
        if (allowed.has(String(key))) next[key] = value;
      });
      return next;
    });
  }, [ctaGroupItems]);


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

  async function fetchCtaGroups() {
    try {
      const res = await fetch(`${CTA_GROUPS_URL}?_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load CTA groups");
      setCtaGroups(data.items || []);
    } catch (err) {
      // optional convenience list
    }
  }

  async function fetchCtaGroupItems(groupId) {
    try {
      const res = await fetch(`${CTA_GROUP_ITEMS_URL}?group_id=${groupId}&_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load CTA group items");
      setCtaGroupItems(data.items || []);
    } catch (err) {
      setCtaGroupItems([]);
    }
  }

  function parseOverrides(raw) {
    if (!raw) return {};
    if (typeof raw === "object") return raw;
    try {
      const decoded = JSON.parse(raw);
      return decoded && typeof decoded === "object" ? decoded : {};
    } catch {
      return {};
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
        audience: data.item?.audience || "any",
        cta_context_key: data.item?.cta_context_key || "default",
        share_enabled: coerceBoolean(data.item?.share_enabled),
        skip_intro_on_replay: coerceBoolean(data.item?.skip_intro_on_replay),
        hide_stars: coerceBoolean(data.item?.hide_stars),
        demo_enabled: coerceBoolean(data.item?.demo_enabled),
        is_active: coerceBoolean(data.item?.is_active),
      };
      setForm(next);
      setCtaOverrides(parseOverrides(data.item?.cta_overrides));
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

  function updateOverride(ctaId, key, value) {
    setCtaOverrides((prev) => {
      const next = { ...prev };
      const base = next[ctaId] && typeof next[ctaId] === "object" ? { ...next[ctaId] } : {};
      if (value === "" || value === null || value === undefined) {
        delete base[key];
      } else {
        base[key] = value;
      }
      if (Object.keys(base).length === 0) {
        delete next[ctaId];
      } else {
        next[ctaId] = base;
      }
      return next;
    });
    setSaveStatus("");
    setSaveError("");
  }

  function handleNew() {
    setActiveId(null);
    setForm(emptyInstance);
    setCtaOverrides({});
    setCtaGroupItems([]);
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
        palette_viewer_cta_group_id:
          form.palette_viewer_cta_group_id === "" ? null : Number(form.palette_viewer_cta_group_id),
        demo_enabled: Boolean(form.demo_enabled),
        cta_overrides: ctaOverrides,
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
    const sorted = [...(items || [])].sort((a, b) => {
      const aLabel = String(a?.instance_name || a?.display_title || a?.playlist_instance_id || "").toLowerCase();
      const bLabel = String(b?.instance_name || b?.display_title || b?.playlist_instance_id || "").toLowerCase();
      return aLabel.localeCompare(bLabel);
    });
    if (!query.trim()) return sorted;
    const needle = query.trim().toLowerCase();
    return sorted.filter((item) => {
      const name = (item?.instance_name || "").toLowerCase();
      const displayTitle = (item?.display_title || "").toLowerCase();
      const id = String(item?.playlist_instance_id || "");
      return name.includes(needle) || displayTitle.includes(needle) || id.includes(needle);
    });
  }, [items, query]);

  const playlistOptions = useMemo(() => {
    return playlists.map((row) => ({
      id: row.playlist_id,
      label: `${row.playlist_id} — ${row.title}`,
    }));
  }, [playlists]);

  const ctaGroupOptions = useMemo(() => {
    return ctaGroups.map((row) => ({
      id: row.id,
      label: `${row.label} (${row.key})`,
      audience: (row.audience || "").toLowerCase(),
    }));
  }, [ctaGroups]);

  function filterCtaGroupsByAudience(options, audience) {
    const target = String(audience || "").toLowerCase();
    if (!target || target === "any" || target === "all") return options;
    return options.filter((opt) => {
      if (!opt.audience) return true;
      if (opt.audience === "any") return true;
      return opt.audience === target;
    });
  }

  const filteredCtaGroupOptions = useMemo(
    () => filterCtaGroupsByAudience(ctaGroupOptions, form.audience),
    [ctaGroupOptions, form.audience]
  );

  const parsedGroupItems = useMemo(() => {
    return ctaGroupItems.map((item) => {
      let params = {};
      if (item?.params) {
        try {
          params = typeof item.params === "string" ? JSON.parse(item.params) : item.params;
        } catch {
          params = {};
        }
      }
      const overrides = ctaOverrides[item.cta_id] || {};
      const mergedParams = { ...(params || {}), ...(overrides || {}) };
      return {
        ...item,
        baseParams: params || {},
        overrideParams: overrides,
        mergedParams,
      };
    });
  }, [ctaGroupItems, ctaOverrides]);

  function renderParams(item) {
    const action = item?.type_action_key || "";
    const params = item?.mergedParams || {};
    const ctaId = item?.cta_id;
    if (!ctaId) return null;

    if (action === "navigate") {
      return (
        <div className="cta-param-row">
          <label>
            url
            <input
              type="text"
              value={params.url || ""}
              onChange={(e) => updateOverride(ctaId, "url", e.target.value)}
            />
          </label>
          <label>
            target
            <input
              type="text"
              value={params.target || ""}
              onChange={(e) => updateOverride(ctaId, "target", e.target.value)}
              placeholder="_blank"
            />
          </label>
        </div>
      );
    }

    if (action === "jump_to_item") {
      return (
        <div className="cta-param-row">
          <label>
            item_index
            <input
              type="number"
              value={params.item_index ?? ""}
              onChange={(e) => updateOverride(ctaId, "item_index", e.target.value)}
            />
          </label>
        </div>
      );
    }

    if (action === "replay_filtered") {
      return (
        <div className="cta-param-row">
          <label>
            filter
            <input
              type="text"
              value={params.filter || ""}
              onChange={(e) => updateOverride(ctaId, "filter", e.target.value)}
              placeholder="liked"
            />
          </label>
        </div>
      );
    }

    return <div className="cta-param-muted">No params</div>;
  }

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
                #{item.playlist_instance_id} {item.instance_name || "Untitled"}
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
            <button
              type="button"
              onClick={() => {
                if (!form.playlist_instance_id) return;
                const params = new URLSearchParams();
                if (form.audience && form.audience !== "any") params.set("aud", form.audience);
                if (form.demo_enabled) params.set("demo", "1");
                const qs = params.toString();
                const url = `${window.location.origin}/playlist/${form.playlist_instance_id}${qs ? `?${qs}` : ""}`;
                window.open(url, "_blank", "noopener");
              }}
              disabled={!form.playlist_instance_id}
            >
              Open Live URL
            </button>
            <button
              type="button"
              onClick={() => {
                if (!form.playlist_instance_id) return;
                const params = new URLSearchParams();
                if (form.audience && form.audience !== "any") params.set("aud", form.audience);
                if (form.cta_group_id) params.set("add_cta_group", form.cta_group_id);
                if (form.demo_enabled) params.set("demo", "1");
                const suffix = params.toString();
                navigate(`/admin/player-preview/${form.playlist_instance_id || ""}${suffix ? `?${suffix}` : ""}`);
              }}
              disabled={!form.playlist_instance_id}
            >
              Preview
            </button>
            <button
              type="button"
              className="primary-btn"
              onClick={handleSave}
              disabled={saving || (!form.playlist_instance_id && !form.playlist_id)}
            >
              {saving ? "Saving..." : "Save"}
            </button>
          </div>
        </div>

    
        <div className="instance-section">
          <div className="section-title">Playlist</div>
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
              Display title
              <input
                type="text"
                value={form.display_title || ""}
                onChange={(e) => updateForm("display_title", e.target.value)}
                placeholder="Shown to viewers (thumbnail title)"
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
                checked={form.demo_enabled}
                onChange={(e) => updateForm("demo_enabled", e.target.checked)}
              />
              Demo flow (adds demo=1)
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
        </div>

        <div className="instance-section cta-section">
          <div className="section-title">CTA Settings</div>
          <div className="cta-controls">
            <label className="cta-group">
              <span>Player CTA Group</span>
              <span className="cta-label-note">All will get default</span>
              <select
                className="cta-group-select"
                value={form.cta_group_id}
                onChange={(e) => updateForm("cta_group_id", e.target.value)}
              >
                <option value="">None</option>
                {filteredCtaGroupOptions.map((opt) => (
                  <option key={opt.id} value={opt.id}>
                    {opt.label}
                  </option>
                ))}
              </select>
            </label>

            <label className="cta-group">
              <span>Palette Viewer CTA Group</span>
              <select
                className="cta-group-select"
                value={form.palette_viewer_cta_group_id}
                onChange={(e) => updateForm("palette_viewer_cta_group_id", e.target.value)}
              >
                <option value="">None</option>
                {filteredCtaGroupOptions.map((opt) => (
                  <option key={opt.id} value={opt.id}>
                    {opt.label}
                  </option>
                ))}
              </select>
            </label>

            <label className="cta-context">
              Audience
              <select
                value={form.audience || "any"}
                onChange={(e) => updateForm("audience", e.target.value)}
              >
                {AUDIENCE_OPTIONS.map((opt) => (
                  <option key={opt.value} value={opt.value}>
                    {opt.label}
                  </option>
                ))}
              </select>
            </label>
          </div>

          <div className="cta-group-list">
            {!form.cta_group_id && (
              <div className="cta-empty">Select a CTA group to edit parameters.</div>
            )}
            {form.cta_group_id && parsedGroupItems.map((item) => (
              <div key={item.cta_id} className="cta-item">
                <div className="cta-item-head">
                  <div className="cta-item-title">{item.label}</div>
                  <div className="cta-item-meta">
                    {item.type_label} ({item.type_action_key})
                  </div>
                </div>
                {renderParams(item)}
              </div>
            ))}
          </div>
        </div>

            <div className="instance-section">
          <div className="section-title">Share Metadata</div>
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
                rows={2}
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
        </div>


        {saveStatus && <div className="panel-status success">{saveStatus}</div>}
        {saveError && <div className="panel-status error">{saveError}</div>}
      </div>
    </div>
  );
}
