import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import "./admin-playlist-editor.css";

const GET_URL = `${API_FOLDER}/v2/admin/playlists/get.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/playlists/save.php`;
const SAVE_ITEMS_URL = `${API_FOLDER}/v2/admin/playlist-items/save.php`;
const AP_LIST_URL = `${API_FOLDER}/v2/admin/applied-palettes/list.php`;

const emptyPlaylist = {
  playlist_id: null,
  title: "",
  type: "",
  is_active: true,
};

const emptyItem = {
  playlist_item_id: null,
  ap_id: "",
  image_url: "",
  title: "",
  subtitle: "",
  subtitle_2: "",
  body: "",
  item_type: "normal",
  layout: "default",
  title_mode: "",
  star: true,
  transition: "",
  duration_ms: "",
  exclude_from_thumbs: false,
  is_active: true,
};

export default function AdminPlaylistEditorPage() {
  const { playlistId } = useParams();
  const navigate = useNavigate();
  const [playlist, setPlaylist] = useState(emptyPlaylist);
  const [items, setItems] = useState([]);
  const [expandedItems, setExpandedItems] = useState({});
  const [apOptions, setApOptions] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [saveStatus, setSaveStatus] = useState("");
  const [saveError, setSaveError] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!playlistId) {
      setPlaylist(emptyPlaylist);
      setItems([]);
      return;
    }
    fetchPlaylist(playlistId);
  }, [playlistId]);

  useEffect(() => {
    fetchAppliedPalettes();
  }, []);

  async function fetchPlaylist(id) {
    setLoading(true);
    setError("");
    try {
      const res = await fetch(`${GET_URL}?playlist_id=${id}&_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load playlist");
      setPlaylist({
        playlist_id: data.playlist.playlist_id,
        title: data.playlist.title,
        type: data.playlist.type,
        is_active: Boolean(data.playlist.is_active),
      });
      setItems(
        (data.items || []).map((item) => ({
          ...emptyItem,
          ...item,
          playlist_item_id: item.playlist_item_id ?? null,
          ap_id: item.ap_id ?? "",
          image_url: item.image_url ?? "",
          title: item.title ?? "",
          subtitle: item.subtitle ?? "",
          subtitle_2: item.subtitle_2 ?? "",
          body: item.body ?? "",
          item_type: item.item_type ?? "normal",
          layout: item.layout ?? "default",
          title_mode: item.title_mode ?? "",
          star: item.star === null ? true : Boolean(item.star),
          transition: item.transition ?? "",
          duration_ms: item.duration_ms ?? "",
          exclude_from_thumbs: Boolean(item.exclude_from_thumbs),
          is_active: item.is_active === null ? true : Boolean(item.is_active),
        }))
      );
    } catch (err) {
      setError(err?.message || "Failed to load playlist");
    } finally {
      setLoading(false);
    }
  }

  async function fetchAppliedPalettes() {
    try {
      const qs = new URLSearchParams();
      qs.set("limit", "200");
      qs.set("_", Date.now().toString());
      const res = await fetch(`${AP_LIST_URL}?${qs.toString()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) return;
      const origin = typeof window !== "undefined" ? window.location.origin : "";
      const options = (data.items || []).map((row) => ({
        id: row.id,
        title: (row.title || `Applied Palette ${row.id}`) + (row.display_title ? ` — ${row.display_title}` : ""),
        renderUrl: row.render_rel_path ? `${origin}${row.render_rel_path}` : "",
      }));
      setApOptions(options);
    } catch (err) {
      // optional convenience list; ignore errors
    }
  }

  function updatePlaylist(field, value) {
    setPlaylist((prev) => ({ ...prev, [field]: value }));
    setSaveStatus("");
    setSaveError("");
  }

  function toggleExpanded(key) {
    setExpandedItems((prev) => ({ ...prev, [key]: !prev[key] }));
  }

  function updateItem(index, field, value) {
    let nextValue = value;
    if (typeof nextValue === "string") {
      nextValue = nextValue
        .replace(/&mdash;/gi, "—")
        .replace(/--/g, "—");
    }
    setItems((prev) =>
      prev.map((item, idx) => (idx === index ? { ...item, [field]: nextValue } : item))
    );
    setSaveStatus("");
    setSaveError("");
  }

  function applyAppliedPalette(index, apId) {
    const match = apOptions.find((option) => String(option.id) === String(apId));
    setItems((prev) =>
      prev.map((item, idx) => {
        if (idx !== index) return item;
        return {
          ...item,
          ap_id: apId ? String(apId) : "",
          image_url: match?.renderUrl || item.image_url,
          title: item.title || match?.title || "",
        };
      })
    );
    setSaveStatus("");
    setSaveError("");
  }

  function addItem(type = "normal") {
    setItems((prev) => [
      ...prev,
      { ...emptyItem, item_type: type },
    ]);
  }

  function removeItem(index) {
    setItems((prev) => prev.filter((_, idx) => idx !== index));
  }

  function moveItem(index, direction) {
    setItems((prev) => {
      const next = [...prev];
      const target = index + direction;
      if (target < 0 || target >= next.length) return next;
      [next[index], next[target]] = [next[target], next[index]];
      return next;
    });
  }

  async function handleSave() {
    setSaving(true);
    setSaveStatus("");
    setSaveError("");
    try {
      const playlistPayload = {
        playlist_id: playlist.playlist_id,
        title: playlist.title,
        type: playlist.type,
        is_active: playlist.is_active,
      };
      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(playlistPayload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to save playlist");

      const playlistIdToSave = data.playlist_id;
      setPlaylist((prev) => ({ ...prev, playlist_id: playlistIdToSave }));

      const itemsPayload = {
        playlist_id: playlistIdToSave,
        items: items.map((item) => ({
          ...item,
          ap_id: item.ap_id === "" ? null : item.ap_id,
          duration_ms: item.duration_ms === "" ? null : item.duration_ms,
        })),
      };
      const itemsRes = await fetch(SAVE_ITEMS_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(itemsPayload),
      });
      const itemsData = await itemsRes.json();
      if (!itemsRes.ok || !itemsData?.ok) {
        throw new Error(itemsData?.error || "Failed to save items");
      }

      setSaveStatus("Saved");
      if (!playlistId) {
        navigate(`/admin/playlists/${playlistIdToSave}`, { replace: true });
      }
    } catch (err) {
      setSaveError(err?.message || "Save failed");
    } finally {
      setSaving(false);
    }
  }

  const hasIntro = useMemo(() => {
    return items.some((item) => (item.item_type || "normal") === "intro");
  }, [items]);

  return (
    <div className="admin-playlist-editor">
      <div className="editor-header">
        <div>
          <div className="editor-title">
            {playlist.playlist_id ? `Playlist #${playlist.playlist_id}` : "New Playlist"}
          </div>
          <div className="editor-subtitle">
            Build slides and intro items here, then assign to instances.
          </div>
        </div>
        <div className="editor-actions">
          <button type="button" onClick={() => navigate("/admin/playlist-instances")}>
            Back to Instances
          </button>
          <button type="button" className="primary-btn" onClick={handleSave} disabled={saving}>
            {saving ? "Saving..." : "Save"}
          </button>
        </div>
      </div>

      {loading && <div className="panel-status">Loading…</div>}
      {error && <div className="panel-status error">{error}</div>}

      <div className="playlist-form">
        <label>
          Title
          <input
            type="text"
            value={playlist.title}
            onChange={(e) => updatePlaylist("title", e.target.value)}
          />
        </label>
        <label>
          Type
          <input
            type="text"
            value={playlist.type}
            onChange={(e) => updatePlaylist("type", e.target.value)}
          />
        </label>
        <label className="checkbox-row">
          <input
            type="checkbox"
            checked={playlist.is_active}
            onChange={(e) => updatePlaylist("is_active", e.target.checked)}
          />
          Active
        </label>
      </div>

      <div className="items-header">
        <div className="items-title">Playlist Items</div>
        <div className="items-actions">
          <button type="button" onClick={() => addItem("intro")}>Add Intro</button>
          <button type="button" onClick={() => addItem("normal")}>Add Slide</button>
        </div>
      </div>

      {!hasIntro && (
        <div className="panel-status">
          No intro item yet. Add one if you want a headline or landing screen.
        </div>
      )}

      <div className="items-list">
        {items.map((item, index) => (
          <div className="item-card" key={`${item.playlist_item_id || "new"}-${index}`}>
            <div className="item-row">
              <div className="item-cell item-order">#{index + 1}</div>
              <label className="item-cell">
                Type
                <select
                  value={item.item_type}
                  onChange={(e) => updateItem(index, "item_type", e.target.value)}
                >
                  <option value="normal">normal</option>
                  <option value="intro">intro</option>
                  <option value="text">text</option>
                </select>
              </label>
              <label className="item-cell item-title">
                Title
                <input
                  type="text"
                  value={item.title}
                  onChange={(e) => updateItem(index, "title", e.target.value)}
                />
              </label>
              <label className="item-cell item-subtitle">
                Subtitle
                <input
                  type="text"
                  value={item.subtitle}
                  onChange={(e) => updateItem(index, "subtitle", e.target.value)}
                />
              </label>
              <label className="item-cell item-ap">
                Applied Palette
                <select
                  value={item.ap_id || ""}
                  onChange={(e) => applyAppliedPalette(index, e.target.value)}
                >
                  <option value="">Select</option>
                  {apOptions.map((option) => (
                    <option key={option.id} value={option.id}>
                      {option.id} — {option.title}
                    </option>
                  ))}
                </select>
              </label>
              <div className="item-actions">
                <div className="item-move">
                  <button type="button" onClick={() => moveItem(index, -1)}>↑</button>
                  <button type="button" onClick={() => moveItem(index, 1)}>↓</button>
                </div>
                <button type="button" className="item-more" onClick={() => toggleExpanded(index)}>
                  {expandedItems[index] ? "Less" : "More"}
                </button>
                <button type="button" onClick={() => removeItem(index)}>Remove</button>
              </div>
            </div>

            {expandedItems[index] && (
              <div className="item-row item-row--details">
                <label className="item-cell item-wide">
                  Image URL
                  <input
                    type="text"
                    value={item.image_url}
                    onChange={(e) => updateItem(index, "image_url", e.target.value)}
                  />
                </label>
                <label className="item-cell">
                  AP ID
                  <input
                    type="number"
                    value={item.ap_id}
                    onChange={(e) => updateItem(index, "ap_id", e.target.value)}
                  />
                </label>
                <label className="item-cell item-wide">
                  Subtitle 2
                  <input
                    type="text"
                    value={item.subtitle_2}
                    onChange={(e) => updateItem(index, "subtitle_2", e.target.value)}
                  />
                </label>
                <label className="item-cell item-wide">
                  Body
                  <textarea
                    rows={2}
                    value={item.body}
                    onChange={(e) => updateItem(index, "body", e.target.value)}
                  />
                </label>
                <label className="item-cell">
                  Layout
                  <input
                    type="text"
                    value={item.layout}
                    onChange={(e) => updateItem(index, "layout", e.target.value)}
                  />
                </label>
                <label className="item-cell">
                  Title Mode
                  <select
                    value={item.title_mode}
                    onChange={(e) => updateItem(index, "title_mode", e.target.value)}
                  >
                    <option value="">Select</option>
                    <option value="static">static</option>
                    <option value="animate">animate</option>
                  </select>
                </label>
                <label className="item-cell">
                  Star
                  <select
                    value={item.star ? "1" : "0"}
                    onChange={(e) => updateItem(index, "star", e.target.value === "1")}
                  >
                    <option value="1">Yes</option>
                    <option value="0">No</option>
                  </select>
                </label>
                <label className="item-cell">
                  Transition
                  <input
                    type="text"
                    value={item.transition}
                    onChange={(e) => updateItem(index, "transition", e.target.value)}
                  />
                </label>
                <label className="item-cell">
                  Duration
                  <input
                    type="number"
                    value={item.duration_ms}
                    onChange={(e) => updateItem(index, "duration_ms", e.target.value)}
                  />
                </label>
                <label className="item-cell checkbox-row">
                  <input
                    type="checkbox"
                    checked={Boolean(item.exclude_from_thumbs)}
                    onChange={(e) => updateItem(index, "exclude_from_thumbs", e.target.checked)}
                  />
                  Hide from Thumbs
                </label>
                <label className="item-cell checkbox-row">
                  <input
                    type="checkbox"
                    checked={item.is_active}
                    onChange={(e) => updateItem(index, "is_active", e.target.checked)}
                  />
                  Active
                </label>
              </div>
            )}
          </div>
        ))}
      </div>

      {saveStatus && <div className="panel-status success">{saveStatus}</div>}
      {saveError && <div className="panel-status error">{saveError}</div>}
    </div>
  );
}
