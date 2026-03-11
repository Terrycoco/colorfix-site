import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./admin-ctas.css";

const TYPES_LIST_URL = `${API_FOLDER}/v2/admin/cta-types/list.php`;
const CTAS_LIST_URL = `${API_FOLDER}/v2/admin/ctas/list.php`;
const CTAS_SAVE_URL = `${API_FOLDER}/v2/admin/ctas/save.php`;

const emptyCta = {
  cta_id: null,
  cta_type_id: "",
  label: "",
  params: "",
  is_active: true,
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
  const [ctaForm, setCtaForm] = useState(emptyCta);

  const [status, setStatus] = useState("");
  const [error, setError] = useState("");
  useEffect(() => {
    fetchTypes();
    fetchCtas();
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
      items.sort((a, b) => (Number(b.cta_id) || 0) - (Number(a.cta_id) || 0));
      setCtas(items);
    } catch (err) {
      setError(err?.message || "Failed to load CTAs");
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
        return 'Example: {"url":"/hoa/contact", "target":"_blank"}';
      case "jump_to_item":
        return 'Example: {"item_index":2}';
      case "replay_filtered":
        return 'Example: {"filter":"liked"}';
      case "article_link":
        return 'Example: {"article_id":123,"title":"Gray But Not Boring","dek":"Soft neutrals..."}';
      case "playlist_link":
        return 'Example: {"playlist_instance_id":123,"title":"My Playlist"}';
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
  const styleValue = (ctaParams.style || ctaParams.variant || "").toString();
  const themeValue = (ctaParams.theme || "").toString();
  const alignValue = (ctaParams.align || "").toString();
  const widthValue = (ctaParams.width || "").toString();
  const articleIdValue = (ctaParams.article_id || ctaParams.articleId || "").toString();
  const articleTitleValue = (ctaParams.title || "").toString();
  const articleDekValue = (ctaParams.dek || ctaParams.subtitle || "").toString();
  const playlistInstanceValue = (ctaParams.playlist_instance_id || ctaParams.playlistInstanceId || "").toString();
  const playlistSetValue = (ctaParams.playlist_instance_set_id || ctaParams.set_id || "").toString();
  const watchNextSubtitleValue = (ctaParams.subtitle || ctaParams.dek || "").toString();
  const noteValue = (ctaParams.note || "").toString();

  const ctaErrors = useMemo(() => {
    const errors = [];
    if (!ctaForm.cta_type_id) errors.push("Select an action.");
    if (!ctaForm.label.trim()) errors.push("Label is required.");
    if (selectedActionKey === "article_link" && !articleIdValue) {
      errors.push("Article ID is required.");
    }
    if (selectedActionKey === "navigate" && !(ctaParams.url || "").toString().trim()) {
      errors.push("URL is required.");
    }
    if (selectedActionKey === "jump_to_item" && (ctaParams.item_index === undefined || ctaParams.item_index === "")) {
      errors.push("Item index is required.");
    }
    return errors;
  }, [ctaForm.cta_type_id, ctaForm.label, selectedActionKey, articleIdValue, ctaParams]);

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
                    is_active: toBool(cta.is_active),
                  })
                }
              >
                <div className="row-title">{cta.label}</div>
                <div className="row-meta">
                  #{cta.cta_id} • {cta.type_label || "Unknown type"}
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
              CTA ID (read-only)
              <input type="text" value={ctaForm.cta_id ?? ""} readOnly />
            </label>
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
                <label>
                  Playlist instance ID
                  <input
                    type="number"
                    value={playlistInstanceValue}
                    onChange={(e) =>
                      updateCtaForm("params", updateParamsString(ctaForm.params, "playlist_instance_id", e.target.value))
                    }
                  />
                </label>
                <label className="full-width">
                  Playlist title (optional)
                  <input
                    type="text"
                    value={(ctaParams.title || "").toString()}
                    onChange={(e) => updateCtaForm("params", updateParamsString(ctaForm.params, "title", e.target.value))}
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

    </div>
  );
}
