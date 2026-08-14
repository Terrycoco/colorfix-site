import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import ModalDialog from "@components/ModalDialog";
import "./admin-ctas.css";
import PlaylistPicker from "@components/PlaylistPicker";

const TYPES_LIST_URL = `${API_FOLDER}/v2/admin/cta-types/list.php`;
const CTAS_LIST_URL = `${API_FOLDER}/v2/admin/ctas/list.php`;
const CTAS_SAVE_URL = `${API_FOLDER}/v2/admin/ctas/save.php`;
const EVENT_DEFINITIONS_URL = `${API_FOLDER}/v2/admin/user-events/definitions.php`;
const EVENT_SAVE_URL = `${API_FOLDER}/v2/admin/user-events/save-event.php`;

const emptyCta = {
  cta_id: null,
  cta_type_id: "",
  label: "",
  params: "",
  onclick: "",
  is_active: true,
};

const emptyEventForm = {
  key: "",
  label: "",
  definition: "",
  sort_order: "100",
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

function hasInvalidParamsJson(value) {
  if (!value || typeof value === "object") return false;
  try {
    const parsed = JSON.parse(value);
    return parsed === null || typeof parsed !== "object" || Array.isArray(parsed);
  } catch {
    return true;
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
  const [eventTypes, setEventTypes] = useState([]);
  const [ctaForm, setCtaForm] = useState(emptyCta);
  const [eventsModalOpen, setEventsModalOpen] = useState(false);
  const [eventForm, setEventForm] = useState(emptyEventForm);
  const [eventSaving, setEventSaving] = useState(false);
  const [eventError, setEventError] = useState("");

  const [status, setStatus] = useState("");
  const [error, setError] = useState("");
  useEffect(() => {
    fetchTypes();
    fetchCtas();
    fetchEventTypes();
  }, []);

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
      const raw = data.items || [];
      const items = Array.isArray(raw) ? raw : Object.values(raw || {});
      items.sort((a, b) => {
        const labelCompare = String(a.label || "").localeCompare(String(b.label || ""), undefined, {
          sensitivity: "base",
        });
        if (labelCompare !== 0) return labelCompare;
        return (Number(a.cta_id) || 0) - (Number(b.cta_id) || 0);
      });
      setCtas(items);
    } catch (err) {
      setError(err?.message || "Failed to load CTAs");
    }
  }

  async function fetchEventTypes() {
    try {
      const res = await fetch(`${EVENT_DEFINITIONS_URL}?_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load tracking events");
      setEventTypes(Array.isArray(data.events) ? data.events : []);
    } catch (err) {
      setError(err?.message || "Failed to load tracking events");
    }
  }

  function updateEventForm(field, value) {
    setEventForm((prev) => ({
      ...prev,
      [field]: field === "key" ? normalizeEventKey(value) : value,
    }));
    setEventError("");
  }

  async function saveEvent() {
    setEventSaving(true);
    setEventError("");
    setStatus("");
    setError("");
    try {
      const payload = {
        ...eventForm,
        sort_order: Number(eventForm.sort_order) || 100,
      };
      const res = await fetch(EVENT_SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to save event");
      setStatus(`Event saved: ${data.key}`);
      setEventForm(emptyEventForm);
      await fetchEventTypes();
    } catch (err) {
      setEventError(err?.message || "Failed to save event");
    } finally {
      setEventSaving(false);
    }
  }

  function updateCtaForm(field, value) {
    setCtaForm((prev) => ({ ...prev, [field]: value }));
    setStatus("");
    setError("");
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
      const text = await res.text();
      let data = null;
      try {
        data = JSON.parse(text);
      } catch {
        data = null;
      }
      if (!res.ok || !data?.ok) {
        const fallback = text ? text.slice(0, 200) : "Failed to save CTA";
        throw new Error(data?.error || fallback);
      }
      setStatus("CTA saved");
      setCtaForm((prev) => ({ ...prev, cta_id: data.cta_id }));
      fetchCtas();
    } catch (err) {
      setError(err?.message || "Failed to save CTA");
    }
  }

  async function saveCtaAsNew() {
    setStatus("");
    setError("");
    try {
      const payload = {
        ...ctaForm,
        cta_id: null,
        cta_type_id: Number(ctaForm.cta_type_id) || 0,
        is_active: toBool(ctaForm.is_active),
      };
      const res = await fetch(CTAS_SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const text = await res.text();
      let data = null;
      try {
        data = JSON.parse(text);
      } catch {
        data = null;
      }
      if (!res.ok || !data?.ok) {
        const fallback = text ? text.slice(0, 200) : "Failed to save CTA";
        throw new Error(data?.error || fallback);
      }
      setStatus(`CTA saved (#${data.cta_id})`);
      setCtaForm((prev) => ({ ...prev, cta_id: data.cta_id }));
      fetchCtas();
    } catch (err) {
      setError(err?.message || "Failed to save CTA");
    }
  }

  async function deactivateCta() {
    if (!ctaForm.cta_id) return;
    const label = ctaForm.label || `CTA #${ctaForm.cta_id}`;
    if (!window.confirm(`Deactivate "${label}"?\n\nIt will stop appearing as an available active CTA. Existing CTA Page assignments can still be removed from CTA Pages.`)) {
      return;
    }
    setStatus("");
    setError("");
    try {
      const payload = {
        ...ctaForm,
        cta_type_id: Number(ctaForm.cta_type_id) || 0,
        is_active: false,
      };
      const res = await fetch(CTAS_SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const text = await res.text();
      let data = null;
      try {
        data = JSON.parse(text);
      } catch {
        data = null;
      }
      if (!res.ok || !data?.ok) {
        const fallback = text ? text.slice(0, 200) : "Failed to deactivate CTA";
        throw new Error(data?.error || fallback);
      }
      setStatus(`CTA deactivated (#${ctaForm.cta_id})`);
      setCtaForm((prev) => ({ ...prev, is_active: false }));
      fetchCtas();
    } catch (err) {
      setError(err?.message || "Failed to deactivate CTA");
    }
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

  const selectedActionKey = useMemo(() => {
    const hit = typeOptions.find((opt) => String(opt.id) === String(ctaForm.cta_type_id));
    return hit?.action_key || "";
  }, [typeOptions, ctaForm.cta_type_id]);

  const paramsHint = useMemo(() => {
    switch (selectedActionKey) {
      case "navigate":
        return 'Example: {"url":"/playlists", "target":"_self", "preserve_src":true}';
      case "jump_to_item":
        return 'Example: {"item_index":2}';
      case "replay_filtered":
        return 'Example: {"filter":"liked"}';
      case "article_link":
        return 'Example: {"article_id":123,"title":"Gray But Not Boring","dek":"Soft neutrals..."}';
      case "playlist_link":
           return 'Example: {"playlist_id":123,"title":"My Playlist"}';
      case "watch_next":
        return 'Example: {"playlist_instance_set_id":3,"subtitle":"More like this"}';
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
  const paramsJsonInvalid = useMemo(() => hasInvalidParamsJson(ctaForm.params), [ctaForm.params]);
  const styleValue = (ctaParams.style || ctaParams.variant || "").toString();
  const themeValue = (ctaParams.theme || "").toString();
  const alignValue = (ctaParams.align || "").toString();
  const widthValue = (ctaParams.width || "").toString();
  const navigateUrlValue = (ctaParams.url || "").toString();
  const navigateTargetValue = (ctaParams.target || "").toString();
  const preserveSrcValue = ctaParams.preserve_src === true || ctaParams.preserve_src === 1 || ctaParams.preserve_src === "true";
  const articleIdValue = (ctaParams.article_id || ctaParams.articleId || "").toString();
  const articleTitleValue = (ctaParams.title || "").toString();
  const articleDekValue = (ctaParams.dek || ctaParams.subtitle || "").toString();
  const playlistValue = (ctaParams.playlist_id || "").toString();
  
  const playlistSetValue = (ctaParams.playlist_instance_set_id || ctaParams.set_id || "").toString();
  const watchNextSubtitleValue = (ctaParams.subtitle || ctaParams.dek || "").toString();
  const noteValue = (ctaParams.note || "").toString();

  const ctaErrors = useMemo(() => {
    const errors = [];
    if (!ctaForm.cta_type_id) errors.push("Select an action.");
    if (!ctaForm.label.trim()) errors.push("Label is required.");
    if (paramsJsonInvalid) {
      errors.push("Params must be a valid JSON object.");
    }
    if (selectedActionKey === "article_link" && !articleIdValue) {
      errors.push("Article ID is required.");
    }
    if (selectedActionKey === "navigate" && !navigateUrlValue.trim()) {
      errors.push("URL is required.");
    }
    if (selectedActionKey === "jump_to_item" && (ctaParams.item_index === undefined || ctaParams.item_index === "")) {
      errors.push("Item index is required.");
    }
    if (ctaForm.onclick && !eventTypes.some((eventType) => eventType.key === ctaForm.onclick)) {
      errors.push("Onclick event must be an active tracking event.");
    }
    return errors;
  }, [ctaForm.cta_type_id, ctaForm.label, ctaForm.onclick, selectedActionKey, articleIdValue, navigateUrlValue, paramsJsonInvalid, ctaParams, eventTypes]);

  const canSaveCta = ctaErrors.length === 0;

  return (
    <div className="admin-ctas">
      <div className="cta-column">
        <div className="cta-panel">
          <div className="panel-header">
            <div className="panel-title">CTA Library</div>
            <button
              type="button"
              className="primary-btn"
              onClick={() => {
                setCtaForm(emptyCta);
              }}
            >
              New CTA
            </button>
          </div>
          <div className="cta-listbox-items">
            {ctas.length === 0 && <div className="cta-listbox-empty">No CTAs yet.</div>}
            {ctas.map((cta) => (
              <button
                key={cta.cta_id}
                type="button"
                className="cta-listbox-row"
                onClick={() =>
                  setCtaForm({
                    cta_id: cta.cta_id,
                    cta_type_id: cta.cta_type_id,
                    label: cta.label || "",
                    params: normalizeParams(cta.params),
                    onclick: cta.onclick || "",
                    is_active: toBool(cta.is_active),
                  })
                }
              >
                <div className="row-title">{cta.label}</div>
                <div className="row-meta">
                  #{cta.cta_id} • {cta.type_label || "Unknown type"}
                  {!toBool(cta.is_active) ? " • inactive" : ""}
                  {cta.onclick ? ` • onclick: ${cta.onclick}` : ""}
                  {(() => {
                    const note = parseParams(cta.params).note;
                    return note ? ` • ${note}` : "";
                  })()}
                </div>
              </button>
            ))}
          </div>
        </div>
      </div>

      <div className="cta-column">
        <div className="cta-panel">
          <div className="panel-header">
            <div className="panel-title">CTAs</div>
            <div className="panel-actions">
              <Link className="secondary-btn" to="/admin/cta-pages">
                CTA Pages
              </Link>
              <button
                type="button"
                className="secondary-btn"
                onClick={() => {
                  setEventsModalOpen(true);
                  fetchEventTypes();
                }}
              >
                Events
              </button>
              <button
                type="button"
                className="primary-btn"
                onClick={() => setCtaForm(emptyCta)}
              >
                New CTA
              </button>
            </div>
          </div>
          <div className="form-grid">
            <label>
              CTA ID (read-only)
              <input type="text" value={ctaForm.cta_id ?? ""} readOnly />
            </label>
            <label>
              Action (what it DOES when clicked)
              <select
                value={ctaForm.cta_type_id}
                onChange={(e) => updateCtaForm("cta_type_id", e.target.value)}
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
              Onclick event
              <select
                value={ctaForm.onclick || ""}
                onChange={(e) => updateCtaForm("onclick", e.target.value)}
              >
                <option value="">No click event</option>
                {eventTypes.map((eventType) => (
                  <option key={eventType.key} value={eventType.key}>
                    {eventType.label} ({eventType.key})
                  </option>
                ))}
              </select>
              <div className="field-hint">Fires this tracking event when the CTA is clicked.</div>
            </label>
            <label className="full-width">
              Note (short internal reminder)
              <input
                type="text"
                value={noteValue}
                onChange={(e) =>
                  updateCtaForm("params", updateParamsString(ctaForm.params, "note", e.target.value))
                }
                placeholder="Where this CTA is used, or any reminder"
              />
            </label>
            {selectedActionKey === "navigate" && (
              <>
                <label className="full-width">
                  URL
                  <input
                    type="text"
                    value={navigateUrlValue}
                    onChange={(e) => updateCtaForm("params", updateParamsString(ctaForm.params, "url", e.target.value))}
                    placeholder="/send-note"
                  />
                </label>
                <label>
                  Target
                  <select
                    value={navigateTargetValue}
                    onChange={(e) => updateCtaForm("params", updateParamsString(ctaForm.params, "target", e.target.value))}
                  >
                    <option value="">(same tab)</option>
                    <option value="_self">Same tab</option>
                    <option value="_blank">New tab</option>
                  </select>
                </label>
                <label className="checkbox-row">
                  <input
                    type="checkbox"
                    checked={preserveSrcValue}
                    onChange={(e) => updateCtaForm("params", updateParamsString(ctaForm.params, "preserve_src", e.target.checked))}
                  />
                  Preserve src tracking
                </label>
              </>
            )}
            {selectedActionKey === "article_link" && (
              <>
                <label>
                  Article ID
                  <input
                    type="number"
                    value={articleIdValue}
                    onChange={(e) => updateCtaForm("params", updateParamsString(ctaForm.params, "article_id", e.target.value))}
                  />
                </label>
                <label className="full-width">
                  Title (optional override)
                  <input
                    type="text"
                    value={articleTitleValue}
                    onChange={(e) => updateCtaForm("params", updateParamsString(ctaForm.params, "title", e.target.value))}
                  />
                </label>
                <label className="full-width">
                  Dek (optional override)
                  <input
                    type="text"
                    value={articleDekValue}
                    onChange={(e) => updateCtaForm("params", updateParamsString(ctaForm.params, "dek", e.target.value))}
                  />
                </label>
              </>
            )}

            {selectedActionKey === "playlist_link" && (
              <>
                <PlaylistPicker
                  value={playlistValue}
                  onChange={(playlistId) =>
                    updateCtaForm(
                      "params",
                      updateParamsString(ctaForm.params, "playlist_id", playlistId)
                    )
                  }
                />

                <label className="full-width">
                  Playlist title (optional)
                  <input
                    type="text"
                    value={(ctaParams.title || "").toString()}
                    onChange={(e) =>
                      updateCtaForm(
                        "params",
                        updateParamsString(ctaForm.params, "title", e.target.value)
                      )
                    }
                  />
                </label>
              </>
            )}

            {selectedActionKey === "watch_next" && (
              <>
                <label>
                  Playlist set ID (optional)
                  <input
                    type="number"
                    value={playlistSetValue}
                    onChange={(e) =>
                      updateCtaForm("params", updateParamsString(ctaForm.params, "playlist_instance_set_id", e.target.value))
                    }
                  />
                </label>
                <label className="full-width">
                  Subtitle (optional)
                  <input
                    type="text"
                    value={watchNextSubtitleValue}
                    onChange={(e) => updateCtaForm("params", updateParamsString(ctaForm.params, "subtitle", e.target.value))}
                  />
                </label>
              </>
            )}
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
                <option value="button_logo">Button with Logo</option>
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
            <button type="button" className="primary-btn" onClick={saveCta} disabled={!canSaveCta}>
              Save CTA
            </button>
            <button type="button" className="secondary-btn" onClick={saveCtaAsNew} disabled={!canSaveCta}>
              Save As New
            </button>
            <button type="button" className="danger-btn danger-btn--small" onClick={deactivateCta} disabled={!ctaForm.cta_id || !ctaForm.is_active}>
              Deactivate CTA
            </button>
          </div>
          {ctaErrors.length > 0 && (
            <div className="cta-form-errors">
              {ctaErrors.map((err) => (
                <div key={err} className="cta-form-error">
                  {err}
                </div>
              ))}
            </div>
          )}
        </div>
        <div className="cta-panel cta-cheatsheet">
          <div className="panel-header">
            <div className="panel-title">CTA Params Cheat Sheet</div>
          </div>
          <div className="cta-cheatsheet-body">
            <div className="cta-cheatsheet-row">
              <div className="cta-cheatsheet-key">style</div>
              <div className="cta-cheatsheet-value">button | button_logo | link | primary | secondary | ghost</div>
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
              <div className="cta-cheatsheet-key">preserve_src</div>
              <div className="cta-cheatsheet-value">true keeps the current src query param on navigation</div>
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
              <div className="cta-cheatsheet-value">any configured audience key</div>
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

      <ModalDialog
        open={eventsModalOpen}
        title="Tracking Events"
        subtitle="Create events that CTAs can emit from the Onclick event dropdown."
        onClose={() => setEventsModalOpen(false)}
        width="860px"
      >
        <div className="cta-events-modal">
          <div className="cta-events-list">
            {eventTypes.length === 0 ? (
              <div className="cta-listbox-empty">No active events found.</div>
            ) : (
              eventTypes.map((eventType) => (
                <div key={eventType.key} className="cta-event-row">
                  <div className="row-title">{eventType.label}</div>
                  <div className="row-meta">
                    <code>{eventType.key}</code>
                    {eventType.definition ? ` • ${eventType.definition}` : ""}
                  </div>
                </div>
              ))
            )}
          </div>
          <div className="cta-event-form">
            <div className="panel-title">Add Event</div>
            <label>
              Key
              <input
                type="text"
                value={eventForm.key}
                onChange={(e) => updateEventForm("key", e.target.value)}
                placeholder="browse_more_playlists_click"
              />
            </label>
            <label>
              Label
              <input
                type="text"
                value={eventForm.label}
                onChange={(e) => updateEventForm("label", e.target.value)}
                placeholder="Browse More Playlists Click"
              />
            </label>
            <label>
              Definition
              <textarea
                rows={4}
                value={eventForm.definition}
                onChange={(e) => updateEventForm("definition", e.target.value)}
                placeholder="A viewer clicked a CTA to browse playlist choices."
              />
            </label>
            <label>
              Sort order
              <input
                type="number"
                value={eventForm.sort_order}
                onChange={(e) => updateEventForm("sort_order", e.target.value)}
              />
            </label>
            {eventError ? <div className="cta-form-error">{eventError}</div> : null}
            <div className="panel-actions">
              <button
                type="button"
                className="primary-btn"
                onClick={saveEvent}
                disabled={eventSaving || !eventForm.key || !eventForm.label}
              >
                {eventSaving ? "Saving..." : "Save Event"}
              </button>
            </div>
          </div>
        </div>
      </ModalDialog>

    </div>
  );
}

function normalizeEventKey(value) {
  return String(value || "")
    .trim()
    .toLowerCase()
    .replace(/\s+/g, "_")
    .replace(/[^a-z0-9_-]+/g, "")
    .slice(0, 100);
}
