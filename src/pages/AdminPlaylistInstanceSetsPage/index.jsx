import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./admin-playlist-instance-sets.css";

const SETS_LIST_URL = `${API_FOLDER}/v2/admin/playlist-instance-sets/list.php`;
const SETS_GET_URL = `${API_FOLDER}/v2/admin/playlist-instance-sets/get.php`;
const SETS_SAVE_URL = `${API_FOLDER}/v2/admin/playlist-instance-sets/save.php`;
const SET_ITEMS_LIST_URL = `${API_FOLDER}/v2/admin/playlist-instance-set-items/list.php`;
const SET_ITEMS_SAVE_URL = `${API_FOLDER}/v2/admin/playlist-instance-set-items/save.php`;
const INSTANCES_LIST_URL = `${API_FOLDER}/v2/admin/playlist-instances/list.php`;

const emptySet = {
  id: null,
  handle: "",
  title: "",
  subtitle: "",
  context: "",
};

const emptyItem = {
  playlist_instance_id: "",
  item_type: "instance",
  target_set_id: "",
  title: "",
  photo_url: "",
};

export default function AdminPlaylistInstanceSetsPage() {
  const [sets, setSets] = useState([]);
  const [instances, setInstances] = useState([]);
  const [activeSetId, setActiveSetId] = useState(null);
  const [setForm, setSetForm] = useState(emptySet);
  const [setItems, setSetItems] = useState([]);
  const [newItem, setNewItem] = useState(emptyItem);
  const [loading, setLoading] = useState(false);
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");
  const [expectedSaveCount, setExpectedSaveCount] = useState(0);

  useEffect(() => {
    fetchSets();
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
        target_set_id: item?.target_set_id ?? "",
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
    } catch (err) {
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
          next.playlist_instance_id = "";
        } else {
          next.target_set_id = "";
        }
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

  async function handleAddItem() {
    const itemType = newItem.item_type || "instance";
    const playlistId = Number(newItem.playlist_instance_id);
    const targetSetId = Number(newItem.target_set_id);
    if (itemType === "set") {
      if (!targetSetId) {
        setError("target set required");
        return;
      }
    } else if (!playlistId) {
      setError("playlist_instance_id required");
      return;
    }
    if (!newItem.title.trim()) {
      setError("title required");
      return;
    }
    if (!newItem.photo_url.trim()) {
      setError("photo_url required");
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
    setSetItems((prev) => [
      ...prev,
      {
        id: null,
        playlist_instance_id: itemType === "set" ? null : playlistId,
        item_type: itemType,
        target_set_id: itemType === "set" ? targetSetId : null,
        title: newItem.title.trim(),
        photo_url: newItem.photo_url.trim(),
        sort_order: prev.length + 1,
      },
    ]);
    setNewItem(emptyItem);
    setStatus("");
    setError("");
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
          updated.playlist_instance_id = "";
        } else {
          updated.target_set_id = "";
        }
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
        const itemType = item?.item_type || "instance";
        if (!String(item.title || "").trim()) return true;
        if (!String(item.photo_url || "").trim()) return true;
        if (itemType === "set") return !Number(item.target_set_id);
        return !Number(item.playlist_instance_id);
      });
      if (invalid) {
        setError("Each item needs a target (playlist or set), title, and photo URL.");
        return;
      }
      setExpectedSaveCount(itemsList.length);
      const payload = {
        set_id: setId,
        items: itemsList.map((item, index) => ({
          item_type: item.item_type || "instance",
          playlist_instance_id:
            (item.item_type || "instance") === "set" ? null : Number(item.playlist_instance_id) || null,
          target_set_id:
            (item.item_type || "instance") === "set" ? Number(item.target_set_id) || null : null,
          title: item.title || "",
          photo_url: item.photo_url || "",
          sort_order: index + 1,
        })),
      };
      const res = await fetch(SET_ITEMS_SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Save failed");
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

  const safeSets = Array.isArray(sets) ? sets : [];
  const safeSetItems = Array.isArray(setItems) ? setItems : [];

  const instanceOptions = useMemo(() => {
    const safeInstances = Array.isArray(instances) ? instances : [];
    return safeInstances
      .map((item) => ({
        id: item.playlist_instance_id,
        label: `#${item.playlist_instance_id} — ${item.instance_name || "Untitled"}`,
      }))
      .sort((a, b) => a.label.localeCompare(b.label));
  }, [instances]);

  const setOptions = useMemo(() => {
    return safeSets
      .filter((set) => Number(set.id) !== Number(activeSetId))
      .map((set) => ({
        id: set.id,
        label: `${set.handle || "set"} (#${set.id})`,
      }))
      .sort((a, b) => a.label.localeCompare(b.label));
  }, [safeSets, activeSetId]);

  return (
    <div className="admin-pi-sets">
      <div className="pi-panel">
        <div className="panel-header">
          <div className="panel-title">Playlist Instance Sets</div>
          <button type="button" className="primary-btn" onClick={handleNewSet}>
            New Set
          </button>
        </div>
        <div className="panel-list">
          {safeSets.map((set) => (
            <button
              key={set.id}
              type="button"
              className={`list-row${Number(activeSetId) === Number(set.id) ? " active" : ""}`}
              onClick={() => setActiveSetId(set.id)}
            >
              <div className="row-title">{set.handle} (#{set.id})</div>
            </button>
          ))}
          {!safeSets.length && <div className="panel-empty">No sets yet.</div>}
        </div>
      </div>

      <div className="pi-panel">
        <div className="panel-header">
          <div className="panel-title">Set Details</div>
          <div className="panel-actions">
            <button type="button" className="primary-btn" onClick={handleSaveAll} disabled={loading}>
              {loading ? "Saving..." : "Save All"}
            </button>
          </div>
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
        </div>

        <div className="pi-items">
          {safeSetItems.length === 0 && <div className="panel-empty">No items yet.</div>}
          {safeSetItems.map((item, index) => (
            <div key={item.id ?? `${item.item_type}-${index}`} className="pi-item-row">
              <div className="pi-item-order">#{index + 1}</div>
              <div className="pi-item-main">
                <div className="pi-item-field">
                  <div className="pi-item-label">
                    {item.item_type === "set" ? "Set" : "Playlist"}
                  </div>
                  <div className="pi-item-value">
                    {item.item_type === "set" ? `#${item.target_set_id}` : `#${item.playlist_instance_id}`}
                  </div>
                </div>
                <label className="pi-item-field">
                  Type
                  <select
                    value={item.item_type || "instance"}
                    onChange={(e) => handleEditItem(index, "item_type", e.target.value)}
                  >
                    <option value="instance">Playlist</option>
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
                ) : (
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
                  Photo URL
                  <input
                    type="text"
                    value={item.photo_url || ""}
                    onChange={(e) => handleEditItem(index, "photo_url", e.target.value)}
                  />
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
              <option value="instance">Playlist</option>
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
          ) : (
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
          )}
          <label>
            Title
            <input
              type="text"
              value={newItem.title}
              onChange={(e) => updateNewItem("title", e.target.value)}
              placeholder="Model A"
            />
          </label>
          <label className="full-width">
            Photo URL
            <input
              type="text"
              value={newItem.photo_url}
              onChange={(e) => updateNewItem("photo_url", e.target.value)}
              placeholder="https://..."
            />
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
