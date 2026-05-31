import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import PhotoPickerModal from "@components/PhotoPickerModal";
import "./admin-playlist-instance-sets.css";

const SETS_LIST_URL = `${API_FOLDER}/v2/admin/playlist-instance-sets/list.php`;
const SETS_GET_URL = `${API_FOLDER}/v2/admin/playlist-instance-sets/get.php`;
const SETS_SAVE_URL = `${API_FOLDER}/v2/admin/playlist-instance-sets/save.php`;
const SET_ITEMS_LIST_URL = `${API_FOLDER}/v2/admin/playlist-instance-set-items/list.php`;
const SET_ITEMS_SAVE_URL = `${API_FOLDER}/v2/admin/playlist-instance-set-items/save.php`;
const INSTANCES_LIST_URL = `${API_FOLDER}/v2/admin/playlist-instances/list.php`;
const PLAYLISTS_LIST_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;

const emptySet = {
  id: null,
  handle: "",
  title: "",
  subtitle: "",
  context: "",
  end_cta_label: "Explore ColorFix",
  end_cta_url: "/",
  end_cta_enabled: true,
};

const emptyItem = {
  playlist_id: "",
  playlist_instance_id: "",
  item_type: "playlist",
  target_set_id: "",
  title: "",
  subtitle: "",
  photo_url: "",
  photo_library_id: "",
};

function buildSetPlayUrl(setId) {
  const id = Number(setId || 0);
  if (id <= 0) return "";
  const params = new URLSearchParams({
    psi: String(id),
    include_private: "1",
    close: "1",
    return_to: "/admin/playlist-sets",
  });
  return `/picker?${params.toString()}`;
}

export default function AdminPlaylistInstanceSetsPage() {
  const [sets, setSets] = useState([]);
  const [playlists, setPlaylists] = useState([]);
  const [instances, setInstances] = useState([]);
  const [activeSetId, setActiveSetId] = useState(null);
  const [setForm, setSetForm] = useState(emptySet);
  const [setItems, setSetItems] = useState([]);
  const [newItem, setNewItem] = useState(emptyItem);
  const [loading, setLoading] = useState(false);
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");
  const [expectedSaveCount, setExpectedSaveCount] = useState(0);
  const [photoPicker, setPhotoPicker] = useState({ open: false, mode: "", index: null });
  const [mobileEditorOpen, setMobileEditorOpen] = useState(false);

  useEffect(() => {
    fetchSets();
    fetchPlaylists();
    fetchInstances();
  }, []);

  useEffect(() => {
    if (!activeSetId) {
      setSetForm(emptySet);
      setSetItems([]);
      return;
    }
    fetchSet(activeSetId);
    fetchSetItems(activeSetId);
  }, [activeSetId]);

  async function fetchSets() {
    setError("");
    try {
      const res = await fetch(`${SETS_LIST_URL}?_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load sets");
      setSets(Array.isArray(data.items) ? data.items : []);
    } catch (err) {
      setError(err?.message || "Failed to load sets");
    }
  }

  async function fetchSet(id) {
    setError("");
    try {
      const res = await fetch(`${SETS_GET_URL}?id=${id}&_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load set");
      setSetForm({
        id: data.item?.id ?? null,
        handle: data.item?.handle ?? "",
        title: data.item?.title ?? "",
        subtitle: data.item?.subtitle ?? "",
        context: data.item?.context ?? "",
        end_cta_label: data.item?.end_cta_label || "Explore ColorFix",
        end_cta_url: data.item?.end_cta_url || "/",
        end_cta_enabled: data.item?.end_cta_enabled !== false,
      });
    } catch (err) {
      setError(err?.message || "Failed to load set");
    }
  }

  async function fetchSetItems(id) {
    setError("");
    try {
      const res = await fetch(`${SET_ITEMS_LIST_URL}?set_id=${id}&_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load set items");
      const items = Array.isArray(data.items) ? data.items : [];
      const normalized = items.map((item) => ({
        ...item,
        item_type: item?.item_type || "instance",
        playlist_id: item?.playlist_id ?? "",
        target_set_id: item?.target_set_id ?? "",
        subtitle: item?.subtitle ?? "",
        photo_library_id: item?.photo_library_id ?? "",
      }));
      setSetItems(normalized);
      if (expectedSaveCount > 0 && items.length === 0) {
        setError("Save failed: no items returned. Please try saving again.");
      }
      if (expectedSaveCount > 0) {
        setExpectedSaveCount(0);
      }
    } catch (err) {
      setError(err?.message || "Failed to load set items");
    }
  }

  async function fetchInstances() {
    try {
      const res = await fetch(`${INSTANCES_LIST_URL}?_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load instances");
      setInstances(Array.isArray(data.items) ? data.items : []);
    } catch {
      // optional list
    }
  }

  async function fetchPlaylists() {
    try {
      const res = await fetch(`${PLAYLISTS_LIST_URL}?_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load playlists");
      setPlaylists(Array.isArray(data.items) ? data.items : []);
    } catch {
      // optional list
    }
  }

  function updateSet(field, value) {
    setSetForm((prev) => ({ ...prev, [field]: value }));
    setStatus("");
    setError("");
  }

  function updateNewItem(field, value) {
    setNewItem((prev) => {
      const next = { ...prev, [field]: value };
      if (field === "item_type") {
        if (value === "set") {
          next.playlist_id = "";
          next.playlist_instance_id = "";
        } else if (value === "playlist") {
          next.playlist_instance_id = "";
          next.target_set_id = "";
        } else {
          next.playlist_id = "";
          next.target_set_id = "";
        }
        next.title = "";
        next.subtitle = "";
      }
      if (field === "playlist_id") {
        const selected = playlists.find((item) => String(item.playlist_id) === String(value));
        next.title = selected?.title || "";
        next.subtitle = "";
      }
      if (field === "playlist_instance_id") {
        const selected = instances.find((item) => String(item.playlist_instance_id) === String(value));
        next.title = selected?.display_title || selected?.instance_name || "";
        next.subtitle = selected?.display_subtitle || "";
      }
      if (field === "target_set_id") {
        const selected = sets.find((item) => String(item.id) === String(value));
        next.title = selected?.title || "";
        next.subtitle = selected?.subtitle || "";
      }
      return next;
    });
    setStatus("");
    setError("");
  }

  function handleNewSet() {
    setActiveSetId(null);
    setSetForm(emptySet);
    setSetItems([]);
    setNewItem(emptyItem);
    setMobileEditorOpen(true);
    setStatus("");
    setError("");
  }

  async function saveSet() {
    const payload = {
      id: setForm.id ?? null,
      handle: setForm.handle.trim(),
      title: setForm.title.trim(),
      subtitle: setForm.subtitle.trim(),
      context: setForm.context.trim(),
      end_cta_label: (setForm.end_cta_label || "Explore ColorFix").trim(),
      end_cta_url: (setForm.end_cta_url || "/").trim(),
      end_cta_enabled: setForm.end_cta_enabled !== false,
    };
    if (!payload.handle) throw new Error("Handle required");
    if (!payload.title) throw new Error("Title required");
    const res = await fetch(SETS_SAVE_URL, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok || !data?.ok) throw new Error(data?.error || "Save failed");
    const newId = Number(data.id) || null;
    setActiveSetId(newId);
    setSetForm((prev) => ({ ...prev, id: newId }));
    fetchSets();
    return newId;
  }

  function buildItemsPayload(itemsList) {
    return itemsList.map((item, index) => ({
      item_type: item.item_type || "playlist",
      playlist_id:
        (item.item_type || "playlist") === "playlist" ? Number(item.playlist_id) || null : null,
      playlist_instance_id:
        (item.item_type || "playlist") === "instance" ? Number(item.playlist_instance_id) || null : null,
      target_set_id:
        (item.item_type || "playlist") === "set" ? Number(item.target_set_id) || null : null,
      title: item.title || "",
      subtitle: item.subtitle || "",
      photo_url: item.photo_url || "",
      photo_library_id: item.photo_library_id ? Number(item.photo_library_id) : null,
      sort_order: index + 1,
    }));
  }

  async function persistSetItems(setId, itemsList) {
    const payload = {
      set_id: setId,
      items: buildItemsPayload(itemsList),
    };
    const res = await fetch(SET_ITEMS_SAVE_URL, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok || !data?.ok) throw new Error(data?.error || "Save failed");
  }

  async function handleAddItem() {
    const itemType = newItem.item_type || "playlist";
    const playlistId = Number(newItem.playlist_id);
    const playlistInstanceId = Number(newItem.playlist_instance_id);
    const targetSetId = Number(newItem.target_set_id);
    if (itemType === "set") {
      if (!targetSetId) {
        setError("target set required");
        return;
      }
    } else if (itemType === "playlist") {
      if (!playlistId) {
        setError("playlist required");
        return;
      }
    } else if (!playlistInstanceId) {
      setError("playlist instance required");
      return;
    }
    if (!newItem.title.trim()) {
      setError("title required");
      return;
    }
    if (!newItem.photo_library_id && !newItem.photo_url.trim()) {
      setError("Pick a photo.");
      return;
    }
    let setId = activeSetId;
    if (!setId) {
      setLoading(true);
      setError("");
      setStatus("");
      try {
        setId = await saveSet();
      } catch (err) {
        setError(err?.message || "Save failed");
        setLoading(false);
        return;
      }
      setLoading(false);
    }
    if (!setId) {
      setError("Save the set before adding items.");
      return;
    }
    const nextItems = [
      ...setItems,
      {
        id: null,
        playlist_id: itemType === "playlist" ? playlistId : null,
        playlist_instance_id: itemType === "instance" ? playlistInstanceId : null,
        item_type: itemType,
        target_set_id: itemType === "set" ? targetSetId : null,
        title: newItem.title.trim(),
        subtitle: newItem.subtitle.trim(),
        photo_url: newItem.photo_url.trim(),
        photo_library_id: newItem.photo_library_id ? Number(newItem.photo_library_id) : null,
        sort_order: setItems.length + 1,
      },
    ];

    setLoading(true);
    setError("");
    setStatus("");
    setSetItems(nextItems);
    try {
      await persistSetItems(setId, nextItems);
      setNewItem(emptyItem);
      setStatus("Saved");
      await fetchSetItems(setId);
    } catch (err) {
      setError(err?.message || "Save failed");
    } finally {
      setLoading(false);
    }
  }

  function handleRemoveItem(index) {
    setSetItems((prev) => prev.filter((_, idx) => idx !== index));
  }

  function moveItem(index, delta) {
    setSetItems((prev) => {
      const next = [...prev];
      const target = index + delta;
      if (target < 0 || target >= next.length) return prev;
      const [moved] = next.splice(index, 1);
      next.splice(target, 0, moved);
      return next;
    });
  }

  function handleEditItem(index, field, value) {
    setSetItems((prev) => {
      const next = [...prev];
      const updated = { ...next[index], [field]: value };
      if (field === "item_type") {
        if (value === "set") {
          updated.playlist_id = "";
          updated.playlist_instance_id = "";
        } else if (value === "playlist") {
          updated.playlist_instance_id = "";
          updated.target_set_id = "";
        } else {
          updated.playlist_id = "";
          updated.target_set_id = "";
        }
        updated.title = "";
        updated.subtitle = "";
      }
      if (field === "playlist_id") {
        const selected = playlists.find((row) => String(row.playlist_id) === String(value));
        updated.title = selected?.title || "";
        updated.subtitle = "";
      }
      if (field === "playlist_instance_id") {
        const selected = instances.find((row) => String(row.playlist_instance_id) === String(value));
        updated.title = selected?.display_title || selected?.instance_name || "";
        updated.subtitle = selected?.display_subtitle || "";
      }
      if (field === "target_set_id") {
        const selected = sets.find((row) => String(row.id) === String(value));
        updated.title = selected?.title || "";
        updated.subtitle = selected?.subtitle || "";
      }
      next[index] = updated;
      return next;
    });
  }

  async function saveItems(setId) {
    if (!setId) return;
    try {
      const itemsList = Array.isArray(setItems) ? setItems : [];
      if (itemsList.length === 0) {
        setError("No items to save");
        return;
      }
      const invalid = itemsList.find((item) => {
        const itemType = item?.item_type || "playlist";
        if (!String(item.title || "").trim()) return true;
        if (!String(item.photo_url || "").trim() && !Number(item.photo_library_id)) return true;
        if (itemType === "set") return !Number(item.target_set_id);
        if (itemType === "playlist") return !Number(item.playlist_id);
        return !Number(item.playlist_instance_id);
      });
      if (invalid) {
        setError("Each item needs a target (playlist or set), title, and photo.");
        return;
      }
      setExpectedSaveCount(itemsList.length);
      await persistSetItems(setId, itemsList);
      setStatus("Saved");
      fetchSetItems(setId);
    } catch (err) {
      setError(err?.message || "Save failed");
    }
  }

  async function handleSaveAll() {
    setLoading(true);
    setError("");
    setStatus("");
    try {
      const setId = await saveSet();
      if (setId && Array.isArray(setItems) && setItems.length > 0) {
        await saveItems(setId);
      } else {
        setStatus("Saved");
      }
    } catch (err) {
      setError(err?.message || "Save failed");
    } finally {
      setLoading(false);
    }
  }

  const safeSets = useMemo(() => {
    const list = Array.isArray(sets) ? [...sets] : [];
    return list.sort((a, b) => {
      const aLabel = (a.title || a.handle || `Set ${a.id || ""}`).trim();
      const bLabel = (b.title || b.handle || `Set ${b.id || ""}`).trim();
      return aLabel.localeCompare(bLabel, undefined, {
        numeric: true,
        sensitivity: "base",
      });
    });
  }, [sets]);
  const safeSetItems = Array.isArray(setItems) ? setItems : [];

  const instanceOptions = useMemo(() => {
    const safeInstances = Array.isArray(instances) ? instances : [];
    return safeInstances
      .map((item) => ({
        id: item.playlist_instance_id,
        label: `${item.display_title || item.instance_name || "Untitled"} (#${item.playlist_instance_id})`,
      }))
      .sort((a, b) => a.label.localeCompare(b.label, undefined, {
        numeric: true,
        sensitivity: "base",
      }));
  }, [instances]);

  const playlistOptions = useMemo(() => {
    const safePlaylists = Array.isArray(playlists) ? playlists : [];
    return safePlaylists
      .filter((item) => Number(item.is_active) !== 0)
      .map((item) => ({
        id: item.playlist_id,
        label: `${item.title || "Untitled"} (#${item.playlist_id}) ${Number(item.is_public) === 1 ? "Public" : "Private"}`,
      }))
      .sort((a, b) => a.label.localeCompare(b.label, undefined, {
        numeric: true,
        sensitivity: "base",
      }));
  }, [playlists]);

  const setOptions = useMemo(() => {
    return safeSets
      .filter((set) => Number(set.id) !== Number(activeSetId))
      .map((set) => ({
        id: set.id,
        label: `${set.handle || "set"} (#${set.id})`,
      }))
      .sort((a, b) => a.label.localeCompare(b.label));
  }, [safeSets, activeSetId]);

  const activeSetSummary = useMemo(() => {
    if (!activeSetId) return null;
    return safeSets.find((set) => Number(set.id) === Number(activeSetId)) || null;
  }, [activeSetId, safeSets]);

  const activeSetLabel = activeSetSummary?.title || activeSetSummary?.handle || setForm.title || setForm.handle || "Untitled set";
  const playUrl = buildSetPlayUrl(activeSetId || setForm.id);

  function handlePlaySet() {
    if (!playUrl) return;
    window.open(playUrl, "_blank", "noopener");
  }

  return (
    <div className="admin-pi-sets">
      <div className="pi-panel">
        <div className="panel-header">
          <div className="panel-title">Playlist Instance Sets</div>
          <div className="panel-actions">
            <button
              type="button"
              className="primary-btn primary-btn--play"
              onClick={handlePlaySet}
              disabled={!playUrl}
            >
              Play
            </button>
            <button type="button" className="primary-btn" onClick={handleNewSet}>
              New Set
            </button>
          </div>
        </div>
        <div className="pi-play-summary">
          {activeSetId ? (
            <>
              <div className="pi-play-summary__label">Selected</div>
              <div className="pi-play-summary__title">{activeSetLabel}</div>
            </>
          ) : (
            <div className="pi-play-summary__empty">Select a set to play it.</div>
          )}
        </div>
        <div className="panel-list">
          {safeSets.map((set) => (
            <button
              key={set.id}
              type="button"
              className={`list-row${Number(activeSetId) === Number(set.id) ? " active" : ""}`}
              onClick={() => {
                setActiveSetId(set.id);
                setMobileEditorOpen(false);
              }}
            >
              <div className="row-title">{set.handle} (#{set.id})</div>
            </button>
          ))}
          {!safeSets.length && <div className="panel-empty">No sets yet.</div>}
        </div>
      </div>
      <PhotoPickerModal
        open={photoPicker.open}
        title="Pick Photo"
        onClose={() => setPhotoPicker({ open: false, mode: "", index: null })}
        onPick={async (photo) => {
          if (!photo) return;
          if (photoPicker.mode === "new") {
            updateNewItem("photo_library_id", photo.photo_library_id);
            updateNewItem("photo_url", photo.image_url || "");
          } else if (photoPicker.mode === "item" && photoPicker.index != null) {
            const nextItems = safeSetItems.map((item, index) =>
              index === photoPicker.index
                ? {
                    ...item,
                    photo_library_id: photo.photo_library_id,
                    photo_url: photo.image_url || "",
                  }
                : item
            );
            setSetItems(nextItems);
            if (activeSetId) {
              setLoading(true);
              setError("");
              setStatus("");
              try {
                await persistSetItems(activeSetId, nextItems);
                setStatus("Saved");
                await fetchSetItems(activeSetId);
              } catch (err) {
                setError(err?.message || "Save failed");
              } finally {
                setLoading(false);
              }
            }
          }
          setPhotoPicker({ open: false, mode: "", index: null });
        }}
      />

      <div className="pi-mobile-priority">
        <div className="pi-mobile-priority__copy">
          <div className="pi-mobile-priority__label">Mobile priority</div>
          <div className="pi-mobile-priority__title">
            {activeSetId ? activeSetLabel : "Pick a set, then play it"}
          </div>
        </div>
        <div className="pi-mobile-priority__actions">
          <button
            type="button"
            className="primary-btn primary-btn--play"
            onClick={handlePlaySet}
            disabled={!playUrl}
          >
            Play Set
          </button>
          <button
            type="button"
            className="secondary-btn"
            onClick={() => setMobileEditorOpen((prev) => !prev)}
          >
            {mobileEditorOpen ? "Hide Editor" : "Show Editor Anyway"}
          </button>
        </div>
      </div>

      <div className={`pi-panel pi-panel--editor${mobileEditorOpen ? " is-mobile-open" : ""}`}>
        <div className="panel-header">
          <div className="panel-title">Set Details</div>
          <div className="panel-actions">
            <button type="button" className="primary-btn" onClick={handleSaveAll} disabled={loading}>
              {loading ? "Saving..." : "Save All"}
            </button>
          </div>
        </div>
        <div className="pi-mobile-editor-note">
          Mobile is now optimized for playing a set. Editing stays available here if you really need it, but this page is meant for desktop editing.
        </div>
        <div className="form-grid">
          <label>
            ID
            <input type="text" value={setForm.id ?? ""} readOnly />
          </label>
          <label>
            Handle
            <input
              type="text"
              value={setForm.handle}
              onChange={(e) => updateSet("handle", e.target.value)}
              placeholder="citrus_heights_models"
            />
          </label>
          <label className="full-width">
            Title
            <input
              type="text"
              value={setForm.title}
              onChange={(e) => updateSet("title", e.target.value)}
              placeholder="Citrus Heights HOA — Choose Your Home Model"
            />
          </label>
          <label className="full-width">
            Subtitle
            <input
              type="text"
              value={setForm.subtitle}
              onChange={(e) => updateSet("subtitle", e.target.value)}
              placeholder="Select your model to view available color schemes."
            />
          </label>
          <label>
            Context (optional)
            <input
              type="text"
              value={setForm.context || ""}
              onChange={(e) => updateSet("context", e.target.value)}
              placeholder="hoa"
            />
          </label>
          <label>
            End Set CTA Label
            <input
              type="text"
              value={setForm.end_cta_label || ""}
              onChange={(e) => updateSet("end_cta_label", e.target.value)}
              placeholder="Explore ColorFix"
            />
          </label>
          <label>
            End Set CTA URL
            <input
              type="text"
              value={setForm.end_cta_url || ""}
              onChange={(e) => updateSet("end_cta_url", e.target.value)}
              placeholder="/"
            />
          </label>
          <label className="pi-checkbox-label">
            <input
              type="checkbox"
              checked={setForm.end_cta_enabled !== false}
              onChange={(e) => updateSet("end_cta_enabled", e.target.checked)}
            />
            Show end set CTA
          </label>
        </div>

        <div className="pi-items">
          {safeSetItems.length === 0 && <div className="panel-empty">No items yet.</div>}
          {safeSetItems.map((item, index) => (
            <div key={item.id ?? `${item.item_type}-${index}`} className="pi-item-row">
              <div className="pi-item-order">#{index + 1}</div>
              <div className="pi-item-main">
                <div className="pi-item-field">
                  <div className="pi-item-label">
                    {item.item_type === "set" ? "Set" : item.item_type === "instance" ? "Instance" : "Playlist"}
                  </div>
                  <div className="pi-item-value">
                    {item.item_type === "set"
                      ? `#${item.target_set_id}`
                      : item.item_type === "instance"
                        ? `#${item.playlist_instance_id}`
                        : `#${item.playlist_id}`}
                  </div>
                </div>
                <label className="pi-item-field">
                  Type
                  <select
                    value={item.item_type || "playlist"}
                    onChange={(e) => handleEditItem(index, "item_type", e.target.value)}
                  >
                    <option value="playlist">Playlist</option>
                    <option value="instance">Playlist Instance</option>
                    <option value="set">Set</option>
                  </select>
                </label>
                {item.item_type === "set" ? (
                  <label className="pi-item-field">
                    Target Set
                    <select
                      value={item.target_set_id || ""}
                      onChange={(e) => handleEditItem(index, "target_set_id", e.target.value)}
                    >
                      <option value="">Select set</option>
                      {setOptions.map((opt) => (
                        <option key={opt.id} value={opt.id}>
                          {opt.label}
                        </option>
                      ))}
                    </select>
                  </label>
                ) : item.item_type === "instance" ? (
                  <label className="pi-item-field">
                    Playlist instance
                    <select
                      value={item.playlist_instance_id || ""}
                      onChange={(e) => handleEditItem(index, "playlist_instance_id", e.target.value)}
                    >
                      <option value="">Select instance</option>
                      {instanceOptions.map((opt) => (
                        <option key={opt.id} value={opt.id}>
                          {opt.label}
                        </option>
                      ))}
                    </select>
                  </label>
                ) : (
                  <label className="pi-item-field">
                    Playlist
                    <select
                      value={item.playlist_id || ""}
                      onChange={(e) => handleEditItem(index, "playlist_id", e.target.value)}
                    >
                      <option value="">Select playlist</option>
                      {playlistOptions.map((opt) => (
                        <option key={opt.id} value={opt.id}>
                          {opt.label}
                        </option>
                      ))}
                    </select>
                  </label>
                )}
                <label className="pi-item-field">
                  Title
                  <input
                    type="text"
                    value={item.title || ""}
                    onChange={(e) => handleEditItem(index, "title", e.target.value)}
                  />
                </label>
                <label className="pi-item-field">
                  Subtitle
                  <input
                    type="text"
                    value={item.subtitle || ""}
                    onChange={(e) => handleEditItem(index, "subtitle", e.target.value)}
                  />
                </label>
                <label className="pi-item-field">
                  Photo
                  <div className="pi-photo-row">
                    <input
                      type="text"
                      value={item.photo_library_id || item.photo_url || ""}
                      readOnly
                      placeholder="Pick a photo"
                    />
                    <button
                      type="button"
                      className="secondary-btn"
                      onClick={() => setPhotoPicker({ open: true, mode: "item", index })}
                    >
                      Pick Photo
                    </button>
                  </div>
                </label>
              </div>
              <div className="pi-item-actions">
                <button type="button" onClick={() => moveItem(index, -1)} disabled={index === 0}>
                  ↑
                </button>
                <button type="button" onClick={() => moveItem(index, 1)} disabled={index === safeSetItems.length - 1}>
                  ↓
                </button>
                <button type="button" className="danger-btn" onClick={() => handleRemoveItem(index)}>
                  Remove
                </button>
              </div>
            </div>
          ))}
        </div>

        <div className="pi-item-form">
          <div className="pi-panel">
            <div className="panel-title">New Item</div>
     
          <label>
            Item type
            <select
              value={newItem.item_type}
              onChange={(e) => updateNewItem("item_type", e.target.value)}
            >
              <option value="playlist">Playlist</option>
              <option value="instance">Playlist Instance</option>
              <option value="set">Set</option>
            </select>
          </label>
          {newItem.item_type === "set" ? (
            <label>
              Target set
              <select
                value={newItem.target_set_id}
                onChange={(e) => updateNewItem("target_set_id", e.target.value)}
              >
                <option value="">Select set</option>
                {setOptions.map((opt) => (
                  <option key={opt.id} value={opt.id}>
                    {opt.label}
                  </option>
                ))}
              </select>
            </label>
          ) : newItem.item_type === "instance" ? (
            <label>
              Playlist instance
              <select
                value={newItem.playlist_instance_id}
                onChange={(e) => updateNewItem("playlist_instance_id", e.target.value)}
              >
                <option value="">Select instance</option>
                {instanceOptions.map((opt) => (
                  <option key={opt.id} value={opt.id}>
                    {opt.label}
                  </option>
                ))}
              </select>
            </label>
          ) : (
            <label>
              Playlist
              <select
                value={newItem.playlist_id}
                onChange={(e) => updateNewItem("playlist_id", e.target.value)}
              >
                <option value="">Select playlist</option>
                {playlistOptions.map((opt) => (
                  <option key={opt.id} value={opt.id}>
                    {opt.label}
                  </option>
                ))}
              </select>
            </label>
          )}
          <label>
            Title
            <input
              type="text"
              value={newItem.title}
              onChange={(e) => updateNewItem("title", e.target.value)}
              placeholder="Auto-filled from selection"
            />
          </label>
          <label>
            Subtitle
            <input
              type="text"
              value={newItem.subtitle}
              onChange={(e) => updateNewItem("subtitle", e.target.value)}
              placeholder="Auto-filled from selection"
            />
          </label>
          <label className="full-width">
            Photo
            <div className="pi-photo-row">
              <input
                type="text"
                value={newItem.photo_library_id || newItem.photo_url}
                readOnly
                placeholder="Pick a photo"
              />
              <button
                type="button"
                className="secondary-btn"
                onClick={() => setPhotoPicker({ open: true, mode: "new", index: null })}
              >
                Pick Photo
              </button>
            </div>
          </label>
          <button type="button" className="secondary-btn" onClick={handleAddItem} disabled={loading}>
            Add New Item
          </button>
        </div>

</div>
        {(status || error) && (
          <div className={`panel-status ${error ? "error" : "success"}`}>
            {error || status}
          </div>
        )}
      </div>
    </div>
  );
}
