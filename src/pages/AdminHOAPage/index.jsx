import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import "./admin-hoa-page.css";

const LIST_URL = `${API_FOLDER}/v2/admin/hoas/list.php`;
const GET_URL = `${API_FOLDER}/v2/admin/hoas/get.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/hoas/save.php`;
const SCHEMES_LIST_URL = `${API_FOLDER}/v2/admin/hoa-schemes/list.php`;
const SCHEMES_GET_URL = `${API_FOLDER}/v2/admin/hoa-schemes/get.php`;
const SCHEMES_SAVE_URL = `${API_FOLDER}/v2/admin/hoa-schemes/save.php`;
const SCHEME_COLORS_LIST_URL = `${API_FOLDER}/v2/admin/hoa-schemes/colors/list.php`;
const SCHEME_COLORS_ADD_URL = `${API_FOLDER}/v2/admin/hoa-schemes/colors/add.php`;
const SCHEME_COLORS_DELETE_URL = `${API_FOLDER}/v2/admin/hoa-schemes/colors/delete.php`;
const SCHEME_COLORS_UPDATE_URL = `${API_FOLDER}/v2/admin/hoa-schemes/colors/update.php`;

const emptyHoa = {
  id: null,
  name: "",
  city: "",
  state: "",
  hoa_type: "unknown",
  eligibility_status: "potential",
  reason_not_eligible: "",
  source: "other",
  notes: "",
};

const hoaTypeOptions = [
  { value: "unknown", label: "Unknown" },
  { value: "single_family", label: "Single family" },
  { value: "condo", label: "Condo" },
  { value: "townhome", label: "Townhome" },
  { value: "mixed", label: "Mixed" },
];

const eligibilityOptions = [
  { value: "potential", label: "Potential" },
  { value: "contacted", label: "Contacted" },
  { value: "active_client", label: "Active client" },
  { value: "not_eligible", label: "Not eligible" },
];

const sourceOptions = [
  { value: "other", label: "Other" },
  { value: "arc", label: "Arc" },
  { value: "drive_by", label: "Drive by" },
  { value: "google_maps", label: "Google Maps" },
  { value: "referral", label: "Referral" },
];

export default function AdminHOAPage() {
  const [query, setQuery] = useState("");
  const [items, setItems] = useState([]);
  const [activeId, setActiveId] = useState(null);
  const [form, setForm] = useState(emptyHoa);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState("");
  const [saveStatus, setSaveStatus] = useState("");
  const [schemes, setSchemes] = useState([]);
  const [schemesOpen, setSchemesOpen] = useState(false);
  const [schemeEditorOpen, setSchemeEditorOpen] = useState(false);
  const [schemeForm, setSchemeForm] = useState({
    id: null,
    hoa_id: null,
    scheme_code: "",
    source_brand: "",
    notes: "",
  });
  const [schemeSaving, setSchemeSaving] = useState(false);
  const [schemeError, setSchemeError] = useState("");
  const [selectedSchemeId, setSelectedSchemeId] = useState(null);
  const [schemeColors, setSchemeColors] = useState([]);
  const [schemeColorForm, setSchemeColorForm] = useState({
    color_id: "",
    allowed_roles: "",
    notes: "",
  });
  const [schemeColorSelected, setSchemeColorSelected] = useState(null);
  const [schemeColorPickerKey, setSchemeColorPickerKey] = useState(0);
  const [schemeColorEditorOpen, setSchemeColorEditorOpen] = useState(false);
  const [schemeColorEditing, setSchemeColorEditing] = useState(null);
  const [schemeColorSaving, setSchemeColorSaving] = useState(false);
  const [roleSuggestions, setRoleSuggestions] = useState([]);

  useEffect(() => {
    fetchHoas();
  }, []);

  useEffect(() => {
    if (!activeId) return;
    fetchHoa(activeId);
    fetchSchemes(activeId);
    setSelectedSchemeId(null);
    setSchemeColors([]);
  }, [activeId]);

  async function fetchHoas() {
    setLoading(true);
    setError("");
    try {
      const qs = new URLSearchParams();
      if (query.trim()) qs.set("q", query.trim());
      qs.set("_", Date.now().toString());
      const res = await fetch(`${LIST_URL}?${qs.toString()}`, { credentials: "include" });
      const text = await res.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch {
        throw new Error(`Unexpected response: ${text.slice(0, 200)}`);
      }
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load HOAs");
      setItems(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load HOAs");
    } finally {
      setLoading(false);
    }
  }

  async function fetchHoa(id) {
    setError("");
    try {
      const res = await fetch(`${GET_URL}?id=${id}&_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load HOA");
      setForm({ ...emptyHoa, ...data.item });
      setSaveStatus("");
      setSaveError("");
    } catch (err) {
      setError(err?.message || "Failed to load HOA");
    }
  }

  async function fetchSchemes(hoaId) {
    if (!hoaId) return;
    try {
      const res = await fetch(`${SCHEMES_LIST_URL}?hoa_id=${hoaId}&_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load schemes");
      setSchemes(data.items || []);
    } catch (err) {
      setSchemeError(err?.message || "Failed to load schemes");
    }
  }

  async function fetchSchemeColors(schemeId) {
    if (!schemeId) return;
    try {
      const res = await fetch(`${SCHEME_COLORS_LIST_URL}?scheme_id=${schemeId}&_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load scheme colors");
      const items = data.items || [];
      setSchemeColors(items);
      setRoleSuggestions((prev) => {
        const nextSuggestions = new Set(prev);
        items.forEach((row) => {
          const roles = (row.allowed_roles || "").trim();
          if (roles) nextSuggestions.add(roles);
        });
        return Array.from(nextSuggestions);
      });
    } catch (err) {
      setSchemeError(err?.message || "Failed to load scheme colors");
    }
  }

  function updateForm(field, value) {
    setForm((prev) => ({ ...prev, [field]: value }));
    setSaveStatus("");
    setSaveError("");
  }

  function handleNew() {
    setActiveId(null);
    setForm(emptyHoa);
    setSaveStatus("");
    setSaveError("");
  }

  function openSchemes() {
    if (!activeId) return;
    setSchemesOpen(true);
    fetchSchemes(activeId);
  }

  function closeSchemes() {
    setSchemesOpen(false);
    setSchemeEditorOpen(false);
    setSchemeColorEditorOpen(false);
  }

  function openSchemeEditor(scheme = null) {
    setSchemeError("");
    if (scheme) {
      setSchemeForm({
        id: scheme.id,
        hoa_id: scheme.hoa_id,
        scheme_code: scheme.scheme_code || "",
        source_brand: scheme.source_brand || "",
        notes: scheme.notes || "",
      });
    } else {
      setSchemeForm({
        id: null,
        hoa_id: activeId,
        scheme_code: "",
        source_brand: "",
        notes: "",
      });
    }
    setSchemeEditorOpen(true);
  }

  async function handleSchemeSave() {
    if (!schemeForm.scheme_code.trim()) {
      setSchemeError("Scheme code is required.");
      return;
    }
    setSchemeSaving(true);
    setSchemeError("");
    try {
      const payload = {
        ...schemeForm,
        hoa_id: activeId,
      };
      const res = await fetch(SCHEMES_SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Save failed");
      await fetchSchemes(activeId);
      if (!schemeForm.id && data.id) {
        setSelectedSchemeId(data.id);
        fetchSchemeColors(data.id);
      }
      setSchemeEditorOpen(false);
    } catch (err) {
      setSchemeError(err?.message || "Save failed");
    } finally {
      setSchemeSaving(false);
    }
  }

  function handleSchemeSelect(schemeId) {
    setSelectedSchemeId(schemeId);
    setSchemeColorEditorOpen(false);
    fetchSchemeColors(schemeId);
  }

  async function handleAddSchemeColor() {
    if (!selectedSchemeId) return;
    const colorId = Number(schemeColorForm.color_id);
    if (!colorId) {
      setSchemeError("Color id is required.");
      return;
    }
    try {
      const payload = {
        scheme_id: selectedSchemeId,
        color_id: colorId,
        allowed_roles: schemeColorForm.allowed_roles.trim() || "any",
        notes: schemeColorForm.notes || null,
      };
      const res = await fetch(SCHEME_COLORS_ADD_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Add failed");
      if (payload.allowed_roles && !roleSuggestions.includes(payload.allowed_roles)) {
        setRoleSuggestions((prev) => [...prev, payload.allowed_roles]);
      }
      setSchemeColorForm({ color_id: "", allowed_roles: "", notes: "" });
      setSchemeColorSelected(null);
      setSchemeColorPickerKey((v) => v + 1);
      fetchSchemeColors(selectedSchemeId);
    } catch (err) {
      setSchemeError(err?.message || "Add failed");
    }
  }

  async function handleDeleteSchemeColor(id) {
    if (!id) return;
    try {
      const res = await fetch(SCHEME_COLORS_DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ id }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Delete failed");
      fetchSchemeColors(selectedSchemeId);
    } catch (err) {
      setSchemeError(err?.message || "Delete failed");
    }
  }

  function openSchemeColorEditor(row) {
    if (!row) return;
    setSchemeColorEditing({
      scheme_color_id: row.scheme_color_id ?? row.id,
      name: row.name || row.color_name || "",
      brand: row.brand || row.color_brand || "",
      allowed_roles: row.allowed_roles || "any",
      notes: row.notes || "",
    });
    setSchemeColorEditorOpen(true);
  }

  async function handleSchemeColorUpdate() {
    if (!schemeColorEditing?.scheme_color_id) return;
    setSchemeColorSaving(true);
    setSchemeError("");
    const payload = {
      id: schemeColorEditing.scheme_color_id,
      allowed_roles: schemeColorEditing.allowed_roles?.trim() || "any",
      notes: schemeColorEditing.notes || null,
    };
    try {
      const res = await fetch(SCHEME_COLORS_UPDATE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Update failed");
      if (payload.allowed_roles && !roleSuggestions.includes(payload.allowed_roles)) {
        setRoleSuggestions((prev) => [...prev, payload.allowed_roles]);
      }
      setSchemeColorEditorOpen(false);
      fetchSchemeColors(selectedSchemeId);
    } catch (err) {
      setSchemeError(err?.message || "Update failed");
    } finally {
      setSchemeColorSaving(false);
    }
  }

  async function handleSave() {
    setSaving(true);
    setSaveError("");
    setSaveStatus("");
    try {
      const payload = { ...form };
      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Save failed");
      setSaveStatus("Saved");
      const newId = data.id;
      setActiveId(newId);
      setForm((prev) => ({ ...prev, id: newId }));
      fetchHoas();
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
      const name = (item?.name || "").toLowerCase();
      const city = (item?.city || "").toLowerCase();
      const state = (item?.state || "").toLowerCase();
      const id = String(item?.id || "");
      return (
        name.includes(needle) ||
        city.includes(needle) ||
        state.includes(needle) ||
        id.includes(needle)
      );
    });
  }, [items, query]);

  return (
    <div className="admin-hoa-page">
      <div className="hoa-panel list-panel">
        <div className="panel-header">
          <div className="panel-title">HOAs</div>
          <div className="header-actions">
            <button type="button" className="primary-btn" onClick={handleNew}>
              New HOA
            </button>
          </div>
        </div>

        <div className="panel-controls">
          <input
            type="text"
            placeholder="Search by name, city, or state"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
          <button type="button" onClick={fetchHoas}>
            Refresh
          </button>
        </div>

        {loading && <div className="panel-status">Loading…</div>}
        {error && <div className="panel-status error">{error}</div>}

        <div className="panel-list">
          {filteredItems.map((item) => (
            <button
              type="button"
              key={item.id}
              className={`list-row ${activeId === item.id ? "active" : ""}`}
              onClick={() => setActiveId(item.id)}
            >
              <div className="row-title">{item.name || "Untitled HOA"}</div>
              <div className="row-meta">
                #{item.id}
                {item.city ? ` • ${item.city}` : ""}
                {item.state ? `, ${item.state}` : ""}
              </div>
            </button>
          ))}
        </div>
      </div>

      <div className="hoa-panel editor-panel">
        <div className="panel-header">
          <div className="panel-title">
            {form.id ? `HOA #${form.id}` : "New HOA"}
          </div>
          <div className="panel-actions">
            <button type="button" className="primary-btn" onClick={openSchemes} disabled={!activeId}>
              Schemes
            </button>
            <button type="button" className="primary-btn" onClick={handleSave} disabled={saving}>
              {saving ? "Saving..." : "Save"}
            </button>
          </div>
        </div>

        <div className="form-grid">
          <label>
            HOA name
            <input
              type="text"
              value={form.name}
              onChange={(e) => updateForm("name", e.target.value)}
            />
          </label>

          <label>
            City
            <input
              type="text"
              value={form.city || ""}
              onChange={(e) => updateForm("city", e.target.value)}
            />
          </label>

          <label>
            State
            <input
              type="text"
              value={form.state || ""}
              onChange={(e) => updateForm("state", e.target.value)}
            />
          </label>

          <label>
            HOA type
            <select
              value={form.hoa_type || "unknown"}
              onChange={(e) => updateForm("hoa_type", e.target.value)}
            >
              {hoaTypeOptions.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
          </label>

          <label>
            Eligibility status
            <select
              value={form.eligibility_status || "potential"}
              onChange={(e) => updateForm("eligibility_status", e.target.value)}
            >
              {eligibilityOptions.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
          </label>

          <label>
            Reason not eligible
            <input
              type="text"
              value={form.reason_not_eligible || ""}
              onChange={(e) => updateForm("reason_not_eligible", e.target.value)}
            />
          </label>

          <label>
            Source
            <select
              value={form.source || "other"}
              onChange={(e) => updateForm("source", e.target.value)}
            >
              {sourceOptions.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
          </label>

          <label>
            Notes
            <textarea
              rows={4}
              value={form.notes || ""}
              onChange={(e) => updateForm("notes", e.target.value)}
            />
          </label>
        </div>

        <div className="hoa-section">
          <div className="section-title">Approved Applied Palettes (coming soon)</div>
          <div className="section-body">Link applied palettes to this HOA.</div>
        </div>

        <div className="hoa-section">
          <div className="section-title">HOA Playlists (coming soon)</div>
          <div className="section-body">Link playlists to this HOA.</div>
        </div>

        {saveStatus && <div className="panel-status success">{saveStatus}</div>}
        {saveError && <div className="panel-status error">{saveError}</div>}
      </div>

      {schemesOpen && (
        <div className="hoa-modal">
          <div className="hoa-modal__content">
            <div className="hoa-modal__header">
              <div className="hoa-modal__title">
                HOA Schemes{form.name ? ` — ${form.name}` : ""}
              </div>
              <div className="hoa-modal__actions">
                <button type="button" className="primary-btn" onClick={() => openSchemeEditor(null)}>
                  + New Scheme
                </button>
                <button type="button" className="ghost-btn" onClick={closeSchemes}>
                  Close
                </button>
              </div>
            </div>

            <div className="hoa-modal__body">
              <div className="hoa-schemes-table">
                <div className="hoa-schemes-row header">
                  <div>Code</div>
                  <div>Brand</div>
                  <div>Notes</div>
                </div>
                {schemes.map((scheme) => (
                  <div
                    key={scheme.id}
                    className={`hoa-schemes-row ${selectedSchemeId === scheme.id ? "active" : ""}`}
                    onClick={() => handleSchemeSelect(scheme.id)}
                    onDoubleClick={() => openSchemeEditor(scheme)}
                  >
                    <div>{scheme.scheme_code}</div>
                    <div>{scheme.source_brand || "-"}</div>
                    <div className="notes">{scheme.notes || ""}</div>
                  </div>
                ))}
              </div>

              <div className="hoa-scheme-colors">
                <div className="section-title">Scheme colors</div>
                {!selectedSchemeId && <div className="section-body">Select a scheme to view colors.</div>}
                {selectedSchemeId && (
                  <>
                    <div className="scheme-color-form">
                      <div className="scheme-color-select">
                        <FuzzySearchColorSelect
                          key={schemeColorPickerKey}
                          mobileBreakpoint={0}
                          onSelect={(color) => {
                            setSchemeColorSelected(color || null);
                            setSchemeColorForm((prev) => ({
                              ...prev,
                              color_id: color?.id || color?.color_id || "",
                            }));
                          }}
                        />
                        {schemeColorSelected && (
                          <div className="scheme-color-meta">
                            #{schemeColorSelected.id || schemeColorSelected.color_id} • {schemeColorSelected.name || schemeColorSelected.color_name}
                          </div>
                        )}
                      </div>
                      <input
                        type="text"
                        placeholder="Allowed roles (comma or 'any')"
                        value={schemeColorForm.allowed_roles}
                        onChange={(e) => setSchemeColorForm((prev) => ({ ...prev, allowed_roles: e.target.value }))}
                        onFocus={(e) => e.target.select()}
                        list="scheme-role-suggestions"
                      />
                      <datalist id="scheme-role-suggestions">
                        {roleSuggestions.map((entry) => (
                          <option key={entry} value={entry} />
                        ))}
                      </datalist>
                      <input
                        type="text"
                        placeholder="Notes"
                        value={schemeColorForm.notes}
                        onChange={(e) => setSchemeColorForm((prev) => ({ ...prev, notes: e.target.value }))}
                        onFocus={(e) => e.target.select()}
                      />
                      <button type="button" className="primary-btn" onClick={handleAddSchemeColor}>
                        Add Color
                      </button>
                    </div>

                    <div className="hoa-schemes-table">
                      <div className="hoa-schemes-row header">
                        <div>Color</div>
                        <div>Brand</div>
                        <div>Roles</div>
                        <div>Notes</div>
                        <div></div>
                      </div>
                      {schemeColors.map((row) => (
                        <div
                          key={row.scheme_color_id ?? row.id}
                          className="hoa-schemes-row"
                          onDoubleClick={() => openSchemeColorEditor(row)}
                        >
                          <div>{row.name || row.color_name || row.color_id}</div>
                          <div>{row.brand || row.color_brand || "-"}</div>
                          <div>{row.allowed_roles || "any"}</div>
                          <div className="notes">{row.notes || ""}</div>
                          <div>
                            <button
                              type="button"
                              className="ghost-btn"
                              onClick={() => handleDeleteSchemeColor(row.scheme_color_id ?? row.id)}
                            >
                              Remove
                            </button>
                          </div>
                        </div>
                      ))}
                    </div>
                  </>
                )}
              </div>
            </div>

            {schemeError && <div className="panel-status error">{schemeError}</div>}
          </div>
        </div>
      )}

      {schemeEditorOpen && (
        <div className="hoa-modal">
          <div className="hoa-modal__content hoa-modal__content--compact">
            <div className="hoa-modal__header">
              <div className="hoa-modal__title">
                {schemeForm.id ? `Edit Scheme #${schemeForm.id}` : "New Scheme"}
              </div>
              <button type="button" className="ghost-btn" onClick={() => setSchemeEditorOpen(false)}>
                Close
              </button>
            </div>
            <div className="hoa-modal__body">
              <div className="form-grid">
                <label>
                  Scheme code
                  <input
                    type="text"
                    value={schemeForm.scheme_code}
                    onChange={(e) => setSchemeForm((prev) => ({ ...prev, scheme_code: e.target.value }))}
                  />
                </label>
                <label>
                  Source brand
                  <input
                    type="text"
                    value={schemeForm.source_brand}
                    onChange={(e) => setSchemeForm((prev) => ({ ...prev, source_brand: e.target.value }))}
                  />
                </label>
                <label>
                  Notes
                  <textarea
                    rows={3}
                    value={schemeForm.notes}
                    onChange={(e) => setSchemeForm((prev) => ({ ...prev, notes: e.target.value }))}
                  />
                </label>
              </div>
            </div>
            <div className="hoa-modal__footer">
              <button type="button" className="primary-btn" onClick={handleSchemeSave} disabled={schemeSaving}>
                {schemeSaving ? "Saving..." : "Save scheme"}
              </button>
            </div>
          </div>
        </div>
      )}

      {schemeColorEditorOpen && schemeColorEditing && (
        <div className="hoa-modal">
          <div className="hoa-modal__content hoa-modal__content--compact">
            <div className="hoa-modal__header">
              <div className="hoa-modal__title">
                Edit Scheme Color
              </div>
              <button type="button" className="ghost-btn" onClick={() => setSchemeColorEditorOpen(false)}>
                Close
              </button>
            </div>
            <div className="hoa-modal__body">
              <div className="scheme-color-editor-meta">
                <strong>{schemeColorEditing.name || "Color"}</strong>
                {schemeColorEditing.brand ? ` • ${schemeColorEditing.brand}` : ""}
              </div>
              <div className="form-grid">
                <label>
                  Allowed roles
                  <input
                    type="text"
                    value={schemeColorEditing.allowed_roles}
                    onChange={(e) =>
                      setSchemeColorEditing((prev) => ({ ...prev, allowed_roles: e.target.value }))
                    }
                    onFocus={(e) => e.target.select()}
                    list="scheme-role-suggestions"
                  />
                </label>
                <label>
                  Notes
                  <input
                    type="text"
                    value={schemeColorEditing.notes}
                    onChange={(e) =>
                      setSchemeColorEditing((prev) => ({ ...prev, notes: e.target.value }))
                    }
                    onFocus={(e) => e.target.select()}
                  />
                </label>
              </div>
            </div>
            <div className="hoa-modal__footer">
              <button
                type="button"
                className="primary-btn"
                onClick={handleSchemeColorUpdate}
                disabled={schemeColorSaving}
              >
                {schemeColorSaving ? "Saving..." : "Save changes"}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
