import { useEffect, useMemo, useRef, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import PhotoPickerModal from "@components/PhotoPickerModal";
import { makePhotoRef, parsePhotoRef } from "@helpers/assetImage";
import "./admin-playlist-editor.css";

const GET_URL = `${API_FOLDER}/v2/admin/playlists/get.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/playlists/save.php`;
const SAVE_ITEMS_URL = `${API_FOLDER}/v2/admin/playlist-items/save.php`;
const DELETE_URL = `${API_FOLDER}/v2/admin/playlists/delete.php`;
const AP_LIST_URL = `${API_FOLDER}/v2/admin/applied-palettes/list.php`;
const PLAYLISTS_LIST_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;
const SAVED_LIST_URL = `${API_FOLDER}/v2/admin/saved-palettes.php`;
const PHOTO_LIBRARY_LIST_URL = `${API_FOLDER}/v2/admin/photo-library/list.php`;

const emptyPlaylist = {
  playlist_id: null,
  title: "",
  type: "",
  is_active: true,
};

const emptyItem = {
  playlist_item_id: null,
  ap_id: "",
  palette_hash: "",
  image_url: "",
  photo_library_id: "",
  title: "",
  subtitle: "",
  subtitle_2: "",
  body: "",
  item_type: "non-palette",
  layout: "default",
  title_mode: "",
  star: true,
  transition: "",
  duration_ms: "",
  exclude_from_thumbs: false,
  is_active: true,
};

const DRAFT_KEY = "admin:playlist-draft";

export default function AdminPlaylistEditorPage() {
  const { playlistId } = useParams();
  const navigate = useNavigate();
  const [playlist, setPlaylist] = useState(emptyPlaylist);
  const [items, setItems] = useState([]);
  const [expandedItems, setExpandedItems] = useState({});
  const [apOptions, setApOptions] = useState([]);
  const [savedOptions, setSavedOptions] = useState([]);
  const [playlistTypes, setPlaylistTypes] = useState([]);
  const [customType, setCustomType] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [saveStatus, setSaveStatus] = useState("");
  const [saveError, setSaveError] = useState("");
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [photoPickerIndex, setPhotoPickerIndex] = useState(null);
  const [photoThumbs, setPhotoThumbs] = useState({});
  const didLoadDraft = useRef(false);

  useEffect(() => {
    if (!playlistId) {
      setPlaylist(emptyPlaylist);
      setItems([]);
      return;
    }
    fetchPlaylist(playlistId);
  }, [playlistId]);

  useEffect(() => {
    if (didLoadDraft.current) return;
    if (playlistId) return;
    try {
      const raw = window.sessionStorage.getItem(DRAFT_KEY);
      if (!raw) return;
      const parsed = JSON.parse(raw);
      if (parsed && typeof parsed === "object") {
        if (parsed.playlist) setPlaylist((prev) => ({ ...prev, ...parsed.playlist, playlist_id: null }));
        if (Array.isArray(parsed.items)) setItems(parsed.items);
        didLoadDraft.current = true;
      }
    } catch {
      /* ignore */
    }
  }, [playlistId]);

  useEffect(() => {
    if (playlistId) return;
    try {
      window.sessionStorage.setItem(DRAFT_KEY, JSON.stringify({ playlist, items }));
    } catch {
      /* ignore */
    }
  }, [playlistId, playlist, items]);

  useEffect(() => {
    fetchAppliedPalettes();
  }, []);

  useEffect(() => {
    fetchPlaylistTypes();
  }, []);

  useEffect(() => {
    fetchSavedPalettes();
  }, []);

  useEffect(() => {
    const missingIds = Array.from(new Set(
      items
        .map((item) => {
          const photoId = String(getPhotoLibraryId(item) || "").trim();
          if (!photoId) return "";
          const parsed = parsePhotoRef(item?.image_url || "");
          if (parsed.url) return "";
          if (item?.image_url && !String(item.image_url).startsWith("photo:")) return "";
          if (photoThumbs[photoId]) return "";
          return photoId;
        })
        .filter(Boolean)
    ));
    if (missingIds.length === 0) return;

    let cancelled = false;

    (async () => {
      try {
        const params = new URLSearchParams();
        params.set("photo_library_ids", missingIds.join(","));
        params.set("limit", String(Math.max(missingIds.length, 1)));
        params.set("_", Date.now().toString());
        const res = await fetch(`${PHOTO_LIBRARY_LIST_URL}?${params.toString()}`, {
          credentials: "include",
        });
        const data = await res.json();
        if (!res.ok || !data?.ok || cancelled) return;
        setPhotoThumbs((prev) => {
          const next = { ...prev };
          for (const row of data.items || []) {
            const id = String(row?.photo_library_id || "").trim();
            if (!id) continue;
            next[id] = row?.rel_path || "";
          }
          return next;
        });
      } catch {
        // ignore thumb lookups; editor should remain usable
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [items, photoThumbs]);

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
      const typeValue = String(data.playlist.type || "").trim();
      if (typeValue && !playlistTypes.includes(typeValue)) {
        setCustomType(typeValue);
      }
      setItems(
        (data.items || []).map((item) => ({
          ...emptyItem,
          ...item,
          playlist_item_id: item.playlist_item_id ?? null,
          ap_id: item.ap_id ?? "",
          palette_hash: item.palette_hash ?? "",
          image_url: item.image_url ?? "",
          photo_library_id:
            item.photo_library_id ?? parsePhotoRef(item.image_url || "").photoId ?? "",
          title: item.title ?? "",
          subtitle: item.subtitle ?? "",
          subtitle_2: item.subtitle_2 ?? "",
          body: item.body ?? "",
          item_type: item.item_type ?? "non-palette",
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
      const options = (data.items || []).map((row) => {
        const title = (row.title || `Applied Palette ${row.id}`) + (row.display_title ? ` — ${row.display_title}` : "");
        return {
          key: `applied:${row.id}`,
          kind: "applied",
          id: row.id,
          title,
          renderUrl: row.render_rel_path ? `${origin}${row.render_rel_path}` : "",
          label: title,
        };
      });
      setApOptions(options);
    } catch {
      // optional convenience list; ignore errors
    }
  }

  async function fetchSavedPalettes() {
    try {
      const qs = new URLSearchParams();
      qs.set("limit", "500");
      qs.set("with_photos", "1");
      qs.set("_", Date.now().toString());
      const res = await fetch(`${SAVED_LIST_URL}?${qs.toString()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) return;
      const options = (data.items || [])
        .filter((row) => row.palette_hash)
        .map((row) => {
        const label = (row.nickname || "").trim() || `Saved #${row.id}`;
        const photos = Array.isArray(row.photos) ? row.photos : [];
        const fullPhoto = photos.find((photo) => photo.photo_type === "full") || photos[0];
        return {
          key: `saved:${row.palette_hash}`,
          kind: "saved",
          palette_hash: row.palette_hash,
          title: label,
          renderUrl: fullPhoto?.rel_path || "",
          label,
        };
      });
      setSavedOptions(options);
    } catch {
      // optional convenience list; ignore errors
    }
  }

  const paletteOptions = useMemo(() => {
    return [...apOptions, ...savedOptions].sort((a, b) => a.label.localeCompare(b.label));
  }, [apOptions, savedOptions]);

  async function fetchPlaylistTypes() {
    try {
      const qs = new URLSearchParams();
      qs.set("limit", "500");
      qs.set("_", Date.now().toString());
      const res = await fetch(`${PLAYLISTS_LIST_URL}?${qs.toString()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) return;
      const types = Array.from(
        new Set(
          (data.items || [])
            .map((row) => String(row?.type || "").trim())
            .filter(Boolean)
        )
      ).sort((a, b) => a.localeCompare(b));
      setPlaylistTypes(types);
    } catch {
      // optional convenience list; ignore errors
    }
  }

  function updatePlaylist(field, value) {
    setPlaylist((prev) => ({ ...prev, [field]: value }));
    setSaveStatus("");
    setSaveError("");
  }

  const isCustomType = useMemo(() => {
    if (!playlist.type) return false;
    return !playlistTypes.includes(playlist.type);
  }, [playlist.type, playlistTypes]);

  const handleTypeSelect = (value) => {
    if (value === "__custom__") {
      updatePlaylist("type", customType);
      return;
    }
    setCustomType("");
    updatePlaylist("type", value);
  };

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

  function applyPaletteOption(index, optionKey) {
    if (!optionKey) {
      setItems((prev) =>
        prev.map((item, idx) =>
          idx === index ? { ...item, ap_id: "", palette_hash: "" } : item
        )
      );
      setSaveStatus("");
      setSaveError("");
      return;
    }
    const match = paletteOptions.find((option) => option.key === optionKey);
    if (!match) return;
    setItems((prev) =>
      prev.map((item, idx) => {
        if (idx !== index) return item;
        if (match.kind === "saved") {
          return {
            ...item,
            ap_id: "",
            palette_hash: match.palette_hash || "",
            image_url: match.renderUrl || item.image_url,
            title: item.title || match.title || "",
          };
        }
        return {
          ...item,
          ap_id: String(match.id || ""),
          palette_hash: "",
          image_url: match.renderUrl || item.image_url,
          title: item.title || match.title || "",
        };
      })
    );
    setSaveStatus("");
    setSaveError("");
  }

  function addItem(type = "non-palette") {
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

  const getPhotoLibraryId = (item) => {
    if (item?.photo_library_id) return item.photo_library_id;
    return parsePhotoRef(item?.image_url || "").photoId || "";
  };

  const getItemPhotoThumb = (item) => {
    const parsed = parsePhotoRef(item?.image_url || "");
    if (parsed.url) return parsed.url;
    if (item?.image_url && !String(item.image_url).startsWith("photo:")) return item.image_url;
    const photoId = String(getPhotoLibraryId(item) || "").trim();
    return photoThumbs[photoId] || "";
  };

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
          palette_hash: item.palette_hash === "" ? null : item.palette_hash,
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
      try {
        window.sessionStorage.removeItem(DRAFT_KEY);
      } catch {
        /* ignore */
      }
      await fetchPlaylist(playlistIdToSave);
      if (!playlistId) {
        navigate(`/admin/playlists/${playlistIdToSave}`, { replace: true });
      }
    } catch (err) {
      setSaveError(err?.message || "Save failed");
    } finally {
      setSaving(false);
    }
  }

  async function handleDeletePlaylist() {
    if (!playlist.playlist_id) return;
    if (!window.confirm(`Delete playlist #${playlist.playlist_id}?`)) return;
    setSaveStatus("");
    setSaveError("");
    setDeleting(true);
    try {
      const res = await fetch(DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ playlist_id: playlist.playlist_id }),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Delete failed");
      navigate("/admin/playlists");
    } catch (err) {
      setSaveError(err?.message || "Delete failed");
    } finally {
      setDeleting(false);
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
          {playlist.playlist_id && (
            <button
              type="button"
              className="danger-btn"
              onClick={handleDeletePlaylist}
              disabled={deleting}
            >
              {deleting ? "Deleting..." : "Delete"}
            </button>
          )}
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
          <select
            value={isCustomType || !playlist.type ? "__custom__" : playlist.type}
            onChange={(e) => handleTypeSelect(e.target.value)}
          >
            <option value="__custom__">Custom…</option>
            {playlistTypes.map((type) => (
              <option key={type} value={type}>
                {type}
              </option>
            ))}
          </select>
          {(isCustomType || !playlist.type) && (
            <input
              type="text"
              placeholder="Enter new type"
              value={customType}
              onChange={(e) => {
                const next = e.target.value;
                setCustomType(next);
                updatePlaylist("type", next);
              }}
            />
          )}
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
                  <option value="palette">palette</option>
                  <option value="intro">intro</option>
                  <option value="text">text</option>
                  <option value="non-palette">no palette</option>
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
                Palette
                <select
                  value={
                    item.palette_hash
                      ? `saved:${item.palette_hash}`
                      : item.ap_id
                      ? `applied:${item.ap_id}`
                      : ""
                  }
                  onChange={(e) => applyPaletteOption(index, e.target.value)}
                >
                  <option value="">Select</option>
                  {paletteOptions.map((option) => (
                    <option key={option.key} value={option.key}>
                      {option.label}
                    </option>
                  ))}
                </select>
              </label>
              <div className="item-cell item-photo">
                Photo
                <div className="item-inline">
                  {getItemPhotoThumb(item) ? (
                    <img
                      className="item-photo-thumb"
                      src={getItemPhotoThumb(item)}
                      alt=""
                      loading="lazy"
                    />
                  ) : (
                    <div className="item-photo-thumb item-photo-thumb--empty" aria-hidden="true" />
                  )}
                  <input
                    type="text"
                    value={getPhotoLibraryId(item)}
                    placeholder="Pick a photo"
                    onChange={(e) => updateItem(index, "photo_library_id", e.target.value)}
                  />
                  <button
                    type="button"
                    className="item-inline-btn"
                    onClick={() => setPhotoPickerIndex(index)}
                  >
                    Pick Photo
                  </button>
                </div>
              </div>
              <label className="item-cell item-transition">
                Transition Into
                <select
                  value={item.transition || ""}
                  onChange={(e) => updateItem(index, "transition", e.target.value)}
                >
                  <option value="">(default animation)</option>
                  <option value="animation">animation</option>
                  <option value="cut">cut</option>
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

      <PhotoPickerModal
        open={photoPickerIndex != null}
        onClose={() => setPhotoPickerIndex(null)}
        onPick={(picked) => {
          if (!picked?.photo_library_id) return;
          const pid = String(picked.photo_library_id);
          updateItem(photoPickerIndex, "photo_library_id", pid);
          updateItem(photoPickerIndex, "image_url", makePhotoRef(pid, picked.image_url || ""));
          setPhotoPickerIndex(null);
        }}
      />
    </div>
  );
}
