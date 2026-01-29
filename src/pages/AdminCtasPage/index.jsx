import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./admin-ctas.css";

const TYPES_LIST_URL = `${API_FOLDER}/v2/admin/cta-types/list.php`;
const TYPES_SAVE_URL = `${API_FOLDER}/v2/admin/cta-types/save.php`;
const CTAS_LIST_URL = `${API_FOLDER}/v2/admin/ctas/list.php`;
const CTAS_SAVE_URL = `${API_FOLDER}/v2/admin/ctas/save.php`;
const GROUPS_LIST_URL = `${API_FOLDER}/v2/admin/cta-groups/list.php`;
const GROUPS_SAVE_URL = `${API_FOLDER}/v2/admin/cta-groups/save.php`;
const GROUP_ITEMS_LIST_URL = `${API_FOLDER}/v2/admin/cta-group-items/list.php`;
const GROUP_ITEMS_SAVE_URL = `${API_FOLDER}/v2/admin/cta-group-items/save.php`;

const emptyType = {
  cta_type_id: null,
  action_key: "",
  label: "",
  description: "",
  is_active: true,
};

const emptyCta = {
  cta_id: null,
  cta_type_id: "",
  label: "",
  params: "",
  is_active: true,
};

const emptyGroup = {
  id: null,
  key: "",
  label: "",
  description: "",
  audience: "homeowner",
};

function toBool(value) {
  return Boolean(value);
}

function normalizeParams(value) {
  if (value === null || value === undefined) return "";
  if (typeof value === "string") return value;
  try {
    return JSON.stringify(value);
  } catch {
    return "";
  }
}

function parseParams(value) {
  if (!value) return {};
  if (typeof value === "object") return value;
  try {
    const parsed = JSON.parse(value);
    return parsed && typeof parsed === "object" ? parsed : {};
  } catch {
    return {};
  }
}

function updateParamsString(current, key, value) {
  const base = parseParams(current);
  if (value === "" || value === null || value === undefined) {
    delete base[key];
  } else {
    base[key] = value;
  }
  return JSON.stringify(base, null, 0);
}

export default function AdminCtasPage() {
  const [types, setTypes] = useState([]);
  const [ctas, setCtas] = useState([]);
  const [groups, setGroups] = useState([]);
  const [activeGroupId, setActiveGroupId] = useState(null);

  const [typeForm, setTypeForm] = useState(emptyType);
  const [ctaForm, setCtaForm] = useState(emptyCta);
  const [groupForm, setGroupForm] = useState(emptyGroup);

  const [groupItems, setGroupItems] = useState([]);
  const [selectedGroupIndex, setSelectedGroupIndex] = useState(null);
  const [selectedAvailableId, setSelectedAvailableId] = useState(null);

  const [status, setStatus] = useState("");
  const [error, setError] = useState("");
  const [showTypes, setShowTypes] = useState(false);

  useEffect(() => {
    fetchTypes();
    fetchCtas();
    fetchGroups();
  }, []);

  useEffect(() => {
    if (activeGroupId) {
      fetchGroupItems(activeGroupId);
    } else {
      setGroupItems([]);
    }
    setSelectedGroupIndex(null);
  }, [activeGroupId]);

  async function fetchTypes() {
    try {
      const res = await fetch(`${TYPES_LIST_URL}?_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load CTA types");
      setTypes(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load CTA types");
    }
  }

  async function fetchCtas() {
    try {
      const res = await fetch(`${CTAS_LIST_URL}?_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load CTAs");
      setCtas(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load CTAs");
    }
  }

  async function fetchGroups() {
    try {
      const res = await fetch(`${GROUPS_LIST_URL}?_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load CTA groups");
      setGroups(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load CTA groups");
    }
  }

  async function fetchGroupItems(groupId) {
    try {
      const res = await fetch(`${GROUP_ITEMS_LIST_URL}?group_id=${groupId}&_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load group items");
      const items = (data.items || [])
        .map((item) => ({
          cta_id: Number(item.cta_id),
          order_index: Number(item.order_index) || 0,
        }))
        .sort((a, b) => a.order_index - b.order_index);
      setGroupItems(items);
    } catch (err) {
      setError(err?.message || "Failed to load group items");
    }
  }

  function updateTypeForm(field, value) {
    setTypeForm((prev) => ({ ...prev, [field]: value }));
    setStatus("");
    setError("");
  }

  function updateCtaForm(field, value) {
    setCtaForm((prev) => ({ ...prev, [field]: value }));
    setStatus("");
    setError("");
  }

  function updateGroupForm(field, value) {
    setGroupForm((prev) => ({ ...prev, [field]: value }));
    setStatus("");
    setError("");
  }

  async function saveType() {
    setStatus("");
    setError("");
    try {
      const payload = {
        ...typeForm,
        is_active: toBool(typeForm.is_active),
      };
      const res = await fetch(TYPES_SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to save CTA type");
      setStatus("CTA type saved");
      setTypeForm((prev) => ({ ...prev, cta_type_id: data.cta_type_id }));
      fetchTypes();
    } catch (err) {
      setError(err?.message || "Failed to save CTA type");
    }
  }

  async function saveCta() {
    setStatus("");
    setError("");
    try {
      const payload = {
        ...ctaForm,
        cta_type_id: Number(ctaForm.cta_type_id) || 0,
        is_active: toBool(ctaForm.is_active),
      };
      const res = await fetch(CTAS_SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to save CTA");
      setStatus("CTA saved");
      setCtaForm((prev) => ({ ...prev, cta_id: data.cta_id }));
      fetchCtas();
    } catch (err) {
      setError(err?.message || "Failed to save CTA");
    }
  }

  async function saveGroup() {
    setStatus("");
    setError("");
    try {
      const payload = { ...groupForm };
      const res = await fetch(GROUPS_SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to save CTA group");
      setStatus("CTA group saved");
      setGroupForm((prev) => ({ ...prev, id: data.id }));
      setActiveGroupId(Number(data.id) || null);
      fetchGroups();
    } catch (err) {
      setError(err?.message || "Failed to save CTA group");
    }
  }

  async function saveGroupItems() {
    if (!activeGroupId) return;
    setStatus("");
    setError("");
    try {
      const items = groupItems.map((item, index) => ({
        cta_id: Number(item.cta_id),
        order_index: index + 1,
      }));
      const payload = {
        group_id: activeGroupId,
        items,
      };
      const res = await fetch(GROUP_ITEMS_SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to save group items");
      setStatus("Group items saved");
      fetchGroupItems(activeGroupId);
    } catch (err) {
      setError(err?.message || "Failed to save group items");
    }
  }

  function handleSelectGroupItem(index) {
    setSelectedGroupIndex(index);
    const item = groupItems[index];
    if (!item) return;
    const hit = ctas.find((cta) => Number(cta.cta_id) === Number(item.cta_id));
    if (!hit) return;
    setCtaForm({
      cta_id: hit.cta_id,
      cta_type_id: hit.cta_type_id,
      label: hit.label || "",
      params: normalizeParams(hit.params),
      is_active: toBool(hit.is_active),
    });
  }

  function handleAddToGroup() {
    if (!activeGroupId || !selectedAvailableId) return;
    setGroupItems((prev) => [
      ...prev,
      { cta_id: Number(selectedAvailableId), order_index: prev.length + 1 },
    ]);
    setSelectedGroupIndex(groupItems.length);
    const hit = ctas.find((cta) => Number(cta.cta_id) === Number(selectedAvailableId));
    if (hit) {
      setCtaForm({
        cta_id: hit.cta_id,
        cta_type_id: hit.cta_type_id,
        label: hit.label || "",
        params: normalizeParams(hit.params),
        is_active: toBool(hit.is_active),
      });
    }
  }

  function handleRemoveFromGroup() {
    if (selectedGroupIndex == null) return;
    setGroupItems((prev) => prev.filter((_, idx) => idx !== selectedGroupIndex));
    setSelectedGroupIndex(null);
  }

  function moveGroupItem(delta) {
    if (selectedGroupIndex == null) return;
    setGroupItems((prev) => {
      const next = [...prev];
      const targetIndex = selectedGroupIndex + delta;
      if (targetIndex < 0 || targetIndex >= next.length) return prev;
      const [moved] = next.splice(selectedGroupIndex, 1);
      next.splice(targetIndex, 0, moved);
      return next;
    });
    setSelectedGroupIndex((prev) => (prev == null ? prev : prev + delta));
  }

  const typeOptions = useMemo(
    () =>
      types.map((row) => ({
        id: row.cta_type_id,
        label: `${row.label} (${row.action_key})`,
        action_key: row.action_key,
      })),
    [types]
  );

  const activeGroup = useMemo(
    () => groups.find((group) => String(group.id) === String(activeGroupId)),
    [groups, activeGroupId]
  );

  const selectedActionKey = useMemo(() => {
    const hit = typeOptions.find((opt) => String(opt.id) === String(ctaForm.cta_type_id));
    return hit?.action_key || "";
  }, [typeOptions, ctaForm.cta_type_id]);

  const paramsHint = useMemo(() => {
    switch (selectedActionKey) {
      case "navigate":
        return 'Example: {"url":"/hoa/contact", "target":"_blank"}';
      case "jump_to_item":
        return 'Example: {"item_index":2}';
      case "replay_filtered":
        return 'Example: {"filter":"liked"}';
      case "see_colors_used":
        return "Example: (optional)";
      case "share":
      case "copy_link":
      case "replay":
        return "Example: (not used)";
      default:
        return selectedActionKey ? "Example: (optional)" : "Example: choose an action first";
    }
  }, [selectedActionKey]);

  const ctaParams = useMemo(() => parseParams(ctaForm.params), [ctaForm.params]);
  const styleValue = (ctaParams.style || ctaParams.variant || "").toString();
  const themeValue = (ctaParams.theme || "").toString();
  const alignValue = (ctaParams.align || "").toString();
  const widthValue = (ctaParams.width || "").toString();

  return (
    <div className="admin-ctas">
      <div className="cta-column">
        <div className="cta-panel">
          <div className="panel-header">
            <div className="panel-title">CTA Groups</div>
            <button
              type="button"
              className="primary-btn"
              onClick={() => {
                setGroupForm(emptyGroup);
                setActiveGroupId(null);
              }}
            >
              New Group
            </button>
          </div>
          <label>
            Select group
            <select
              value={activeGroupId || ""}
              onChange={(e) => {
                const id = e.target.value ? Number(e.target.value) : null;
                setActiveGroupId(id);
                const hit = groups.find((g) => Number(g.id) === Number(id));
                if (hit) {
                  setGroupForm({
                    id: hit.id,
                    key: hit.key,
                    label: hit.label,
                    description: hit.description || "",
                    audience: hit.audience || "homeowner",
                  });
                }
              }}
            >
              <option value="">Select group</option>
              {groups.map((group) => (
                <option key={group.id} value={group.id}>
                  {group.label} (ID {group.id})
                </option>
              ))}
            </select>
          </label>
          <div className="form-grid">
            <label>
              Group ID
              <input type="text" value={groupForm.id ?? ""} readOnly />
            </label>
            <label>
              Group key
              <input
                type="text"
                value={groupForm.key}
                onChange={(e) => updateGroupForm("key", e.target.value)}
              />
            </label>
            <label>
              Label
              <input
                type="text"
                value={groupForm.label}
                onChange={(e) => updateGroupForm("label", e.target.value)}
              />
            </label>
            <label>
              Audience
              <select
                value={groupForm.audience || "homeowner"}
                onChange={(e) => updateGroupForm("audience", e.target.value)}
              >
                <option value="any">Any</option>
                <option value="homeowner">Homeowner</option>
                <option value="hoa">HOA</option>
                <option value="contractor">Contractor</option>
                <option value="admin">Admin</option>
              </select>
            </label>
            <label className="full-width">
              Description
              <textarea
                rows={3}
                value={groupForm.description || ""}
                onChange={(e) => updateGroupForm("description", e.target.value)}
              />
            </label>
          </div>
          <div className="panel-actions">
            <button type="button" className="primary-btn" onClick={saveGroup}>
              Save Group
            </button>
          </div>
        </div>

        <div className="cta-panel">
          <div className="panel-header">
            <div className="panel-title">Group Items</div>
            <div className="panel-status">
              {activeGroup ? `Editing: ${activeGroup.label}` : "Select a group"}
            </div>
          </div>
          <div className="cta-dual">
            <div className="cta-listbox">
              <div className="cta-listbox-title">In group</div>
              <div className="cta-listbox-items">
                {groupItems.length === 0 && (
                  <div className="cta-listbox-empty">No CTAs in this group.</div>
                )}
                {groupItems.map((item, index) => {
                  const cta = ctas.find((row) => Number(row.cta_id) === Number(item.cta_id));
                  return (
                    <button
                      key={`${item.cta_id}-${index}`}
                      type="button"
                      className={`cta-listbox-row${index === selectedGroupIndex ? " active" : ""}`}
                      onClick={() => handleSelectGroupItem(index)}
                    >
                      <div className="row-title">{cta?.label || `CTA #${item.cta_id}`}</div>
                      <div className="row-meta">{cta?.type_label || ""}</div>
                    </button>
                  );
                })}
              </div>
            </div>
            <div className="cta-dual-actions">
              <button
                type="button"
                className="cta-arrow"
                onClick={handleAddToGroup}
                disabled={!selectedAvailableId || !activeGroupId}
                title="Add to group"
              >
                ←
              </button>
              <button
                type="button"
                className="cta-arrow"
                onClick={handleRemoveFromGroup}
                disabled={selectedGroupIndex == null}
                title="Remove from group"
              >
                →
              </button>
              <button
                type="button"
                className="cta-arrow"
                onClick={() => moveGroupItem(-1)}
                disabled={selectedGroupIndex == null || selectedGroupIndex === 0}
                title="Move up"
              >
                ↑
              </button>
              <button
                type="button"
                className="cta-arrow"
                onClick={() => moveGroupItem(1)}
                disabled={selectedGroupIndex == null || selectedGroupIndex === groupItems.length - 1}
                title="Move down"
              >
                ↓
              </button>
            </div>
            <div className="cta-listbox">
              <div className="cta-listbox-title">
                Available CTAs
                <button
                  type="button"
                  className="secondary-btn"
                  onClick={() => {
                    setCtaForm(emptyCta);
                    setSelectedGroupIndex(null);
                  }}
                >
                  Add New
                </button>
              </div>
              <div className="cta-listbox-items">
                {ctas.map((cta) => (
                  <button
                    key={cta.cta_id}
                    type="button"
                    className={`cta-listbox-row${Number(selectedAvailableId) === Number(cta.cta_id) ? " active" : ""}`}
                    onClick={() => {
                      setSelectedAvailableId(cta.cta_id);
                      setCtaForm({
                        cta_id: cta.cta_id,
                        cta_type_id: cta.cta_type_id,
                        label: cta.label || "",
                        params: normalizeParams(cta.params),
                        is_active: toBool(cta.is_active),
                      });
                    }}
                  >
                    <div className="row-title">{cta.label}</div>
                    <div className="row-meta">{cta.type_label}</div>
                  </button>
                ))}
              </div>
            </div>
          </div>
          <div className="panel-actions">
            <button type="button" className="primary-btn" onClick={saveGroupItems} disabled={!activeGroupId}>
              Save Group Items
            </button>
          </div>
        </div>

  
      </div>

      <div className="cta-column">
        <div className="cta-panel">
          <div className="panel-header">
            <div className="panel-title">CTAs</div>
            <button
              type="button"
              className="primary-btn"
              onClick={() => setCtaForm(emptyCta)}
            >
              New CTA
            </button>
          </div>
          <div className="form-grid">
            <label>
              Action (what it DOES when clicked)
              <select
                value={ctaForm.cta_type_id}
                onChange={(e) => updateCtaForm("cta_type_id", e.target.value)}
                onDoubleClick={() => setShowTypes(true)}
              >
                <option value="">Select action</option>
                {typeOptions.map((opt) => (
                  <option key={opt.id} value={opt.id}>
                    {opt.label}
                  </option>
                ))}
              </select>
            </label>
            <label>
              Label (what user sees)
              <input
                type="text"
                value={ctaForm.label}
                onChange={(e) => updateCtaForm("label", e.target.value)}
              />
            </label>
            <label className="full-width">
              Params (JSON)
              <textarea
                rows={3}
                value={ctaForm.params || ""}
                onChange={(e) => updateCtaForm("params", e.target.value)}
                placeholder={paramsHint}
              />
              <div className="field-hint">{paramsHint}</div>
            </label>
            <label>
              Style
              <select
                value={styleValue}
                onChange={(e) => updateCtaForm("params", updateParamsString(ctaForm.params, "style", e.target.value))}
              >
                <option value="">(default)</option>
                <option value="button">Button</option>
                <option value="link">Anchor</option>
                <option value="primary">Primary</option>
                <option value="secondary">Secondary</option>
                <option value="ghost">Ghost</option>
              </select>
            </label>
            <label>
              Theme
              <select
                value={themeValue}
                onChange={(e) => updateCtaForm("params", updateParamsString(ctaForm.params, "theme", e.target.value))}
              >
                <option value="">(inherit)</option>
                <option value="dark">Dark</option>
                <option value="light">Light</option>
              </select>
            </label>
            <label>
              Align
              <select
                value={alignValue}
                onChange={(e) => updateCtaForm("params", updateParamsString(ctaForm.params, "align", e.target.value))}
              >
                <option value="">(inherit)</option>
                <option value="left">Left</option>
                <option value="center">Center</option>
                <option value="right">Right</option>
              </select>
            </label>
            <label>
              Width
              <select
                value={widthValue}
                onChange={(e) => updateCtaForm("params", updateParamsString(ctaForm.params, "width", e.target.value))}
              >
                <option value="">(default)</option>
                <option value="auto">Auto</option>
                <option value="standard">Standard</option>
                <option value="full">Full</option>
              </select>
            </label>
            <label className="checkbox-row">
              <input
                type="checkbox"
                checked={Boolean(ctaForm.is_active)}
                onChange={(e) => updateCtaForm("is_active", e.target.checked)}
              />
              Active
            </label>
          </div>
          <div className="panel-actions">
            <button type="button" className="primary-btn" onClick={saveCta}>
              Save CTA
            </button>
            <button type="button" className="secondary-btn" onClick={() => setShowTypes(true)}>
              Manage Types
            </button>
          </div>
        </div>
              <div className="cta-panel cta-cheatsheet">
          <div className="panel-header">
            <div className="panel-title">CTA Params Cheat Sheet</div>
          </div>
          <div className="cta-cheatsheet-body">
            <div className="cta-cheatsheet-row">
              <div className="cta-cheatsheet-key">style</div>
              <div className="cta-cheatsheet-value">button | link | primary | secondary | ghost</div>
            </div>
            <div className="cta-cheatsheet-row">
              <div className="cta-cheatsheet-key">theme</div>
              <div className="cta-cheatsheet-value">dark | light</div>
            </div>
            <div className="cta-cheatsheet-row">
              <div className="cta-cheatsheet-key">align</div>
              <div className="cta-cheatsheet-value">left | center | right</div>
            </div>
            <div className="cta-cheatsheet-row">
              <div className="cta-cheatsheet-key">width</div>
              <div className="cta-cheatsheet-value">auto | standard | full</div>
            </div>
            <div className="cta-cheatsheet-row">
              <div className="cta-cheatsheet-key">require_psi</div>
              <div className="cta-cheatsheet-value">true | false</div>
            </div>
            <div className="cta-cheatsheet-row">
              <div className="cta-cheatsheet-key">require_thumb</div>
              <div className="cta-cheatsheet-value">true | false</div>
            </div>
            <div className="cta-cheatsheet-row">
              <div className="cta-cheatsheet-key">require_demo</div>
              <div className="cta-cheatsheet-value">true | false</div>
            </div>
            <div className="cta-cheatsheet-row">
              <div className="cta-cheatsheet-key">require_aud</div>
              <div className="cta-cheatsheet-value">hoa | homeowner | contractor | admin</div>
            </div>
            <div className="cta-cheatsheet-note">
              Add these inside Params (JSON). Example: {"{ \"style\":\"link\", \"align\":\"center\" }"}
            </div>
          </div>
        </div>
      </div>

      {(status || error) && (
        <div className={`cta-toast ${error ? "error" : "success"}`}>
          {error || status}
        </div>
      )}

      {showTypes && (
        <div className="cta-modal">
          <div className="cta-modal__backdrop" onClick={() => setShowTypes(false)} />
          <div className="cta-modal__panel" role="dialog" aria-modal="true">
            <div className="panel-header">
              <div className="panel-title">CTA Actions</div>
              <div className="panel-actions">
                <button type="button" className="secondary-btn" onClick={() => setTypeForm(emptyType)}>
                  New Action
                </button>
                <button type="button" className="primary-btn" onClick={() => setShowTypes(false)}>
                  Done
                </button>
              </div>
            </div>
            <div className="cta-modal__body">
              <div className="panel-list">
                {types.map((type) => (
                  <button
                    key={type.cta_type_id}
                    type="button"
                    className={`list-row${String(typeForm.cta_type_id) === String(type.cta_type_id) ? " active" : ""}`}
                    onClick={() =>
                      setTypeForm({
                        cta_type_id: type.cta_type_id,
                        action_key: type.action_key,
                        label: type.label,
                        description: type.description || "",
                        is_active: toBool(type.is_active),
                      })
                    }
                  >
                    <div className="row-title">{type.label}</div>
                    <div className="row-meta">{type.action_key}</div>
                  </button>
                ))}
              </div>
              <div className="form-grid">
                <label>
                  Action key
                  <input
                    type="text"
                    value={typeForm.action_key}
                    onChange={(e) => updateTypeForm("action_key", e.target.value)}
                  />
                </label>
                <label>
                  Label (what user sees)
                  <input
                    type="text"
                    value={typeForm.label}
                    onChange={(e) => updateTypeForm("label", e.target.value)}
                  />
                </label>
                <label className="full-width">
                  Description
                  <textarea
                    rows={2}
                    value={typeForm.description || ""}
                    onChange={(e) => updateTypeForm("description", e.target.value)}
                  />
                </label>
                <label className="checkbox-row">
                  <input
                    type="checkbox"
                    checked={Boolean(typeForm.is_active)}
                    onChange={(e) => updateTypeForm("is_active", e.target.checked)}
                  />
                  Active
                </label>
              </div>
              <div className="panel-actions">
                <button type="button" className="primary-btn" onClick={saveType}>
                  Save Type
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
