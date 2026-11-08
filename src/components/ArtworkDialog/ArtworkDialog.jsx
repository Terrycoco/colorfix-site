import { useEffect, useId, useState } from "react";
import { createPortal } from "react-dom";
import { API_FOLDER } from "@helpers/config";
import "./ArtworkDialog.css";

const API_URL = `${API_FOLDER}/v2/admin/artwork/index.php`;
const BLANK = {
  artist: "", official_title: "", display_title: "", year: "", circa: false,
  medium: "", museum: "", source_url: "", historical_notes: "", color_analysis: "",
};

async function callApi(url, options) {
  const response = await fetch(url, { credentials: "include", cache: "no-store", ...options });
  const data = await response.json().catch(() => ({}));
  if (!response.ok || !data.ok) throw new Error(data.error || `Artwork request failed (${response.status})`);
  return data;
}

/** Reusable metadata editor: ARTWORK owns metadata; PHOTOS retains the photo. */
export default function ArtworkDialog({ open, photoLibraryId, imageUrl, onClose, onSaved, onDeleted }) {
  const titleId = useId();
  const [fields, setFields] = useState(BLANK);
  const [exists, setExists] = useState(false);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [confirmDelete, setConfirmDelete] = useState(false);

  useEffect(() => {
    if (!open || !photoLibraryId) return;
    const controller = new AbortController();
    setLoading(true); setError(""); setFields(BLANK); setExists(false); setConfirmDelete(false);
    callApi(`${API_URL}?action=get&photo_library_id=${encodeURIComponent(photoLibraryId)}`, { signal: controller.signal })
      .then(({ artwork }) => {
        if (controller.signal.aborted) return;
        setExists(!!artwork);
        if (artwork) setFields(Object.fromEntries(Object.keys(BLANK).map((key) => [key,
          key === "circa" ? !!Number(artwork.circa) : (artwork[key] ?? "")
        ])));
      })
      .catch((err) => { if (err.name !== "AbortError") setError(err.message); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [open, photoLibraryId]);

  useEffect(() => {
    if (!open) return;
    const onKeyDown = (e) => { if (e.key === "Escape" && !saving) onClose?.(); };
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [open, saving, onClose]);

  const update = (key, value) => setFields((prev) => ({ ...prev, [key]: value }));
  const post = (payload) => callApi(API_URL, {
    method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload),
  });

  async function save(e) {
    e.preventDefault();
    if (saving || loading) return;
    setSaving(true); setError("");
    try {
      const metadata = { ...fields,
        year: fields.year === "" ? null : Number(fields.year),
        circa: fields.circa ? 1 : 0,
      };
      const { artwork } = await post({ action: exists ? "update" : "create", photo_library_id: Number(photoLibraryId), fields: metadata });
      setExists(true); onSaved?.(artwork); onClose?.();
    } catch (err) { setError(err.message); }
    finally { setSaving(false); }
  }

  async function remove() {
    if (!exists || saving) return;
    if (!confirmDelete) { setConfirmDelete(true); return; }
    setSaving(true); setError("");
    try {
      await post({ action: "delete", photo_library_id: Number(photoLibraryId) });
      setFields(BLANK); setExists(false); setConfirmDelete(false);
      onDeleted?.(Number(photoLibraryId)); onClose?.();
    } catch (err) { setError(err.message); }
    finally { setSaving(false); }
  }

  if (!open || typeof document === "undefined") return null;
  const field = (name, label, props = {}) => (
    <label className="artwork-dialog__field" key={name}>
      <span>{label}</span>
      <input value={fields[name]} onChange={(e) => update(name, e.target.value)} disabled={loading || saving} {...props} />
    </label>
  );

  return createPortal(
    <div className="artwork-dialog__backdrop" onMouseDown={(e) => { if (e.target === e.currentTarget && !saving) onClose?.(); }}>
      <section role="dialog" aria-modal="true" aria-labelledby={titleId} className="artwork-dialog">
        <header className="artwork-dialog__header">
          <h2 id={titleId}>{exists ? "Edit Artwork" : "Add Artwork"} · Photo #{photoLibraryId}</h2>
          <button type="button" onClick={() => onClose?.()} disabled={saving} aria-label="Close artwork editor">×</button>
        </header>
        <form onSubmit={save} className="artwork-dialog__form">
          {imageUrl && <img className="artwork-dialog__photo" src={imageUrl} alt="Artwork photograph" />}
          {loading && <p role="status">Loading artwork…</p>}
          {error && <p className="artwork-dialog__error" role="alert">{error}</p>}
          <div className="artwork-dialog__fields">
            {field("artist", "Artist")}
            {field("official_title", "Official title")}
            {field("display_title", "Display title")}
            <div className="artwork-dialog__year">
              {field("year", "Year", { type: "number", min: 0, max: 65535 })}
              <label><input type="checkbox" checked={fields.circa} onChange={(e) => update("circa", e.target.checked)} disabled={loading || saving} /> Circa</label>
            </div>
            {field("medium", "Medium")}
            {field("museum", "Museum / collection")}
            {field("source_url", "Source URL", { type: "url" })}
            <label className="artwork-dialog__field artwork-dialog__wide"><span>Historical notes</span>
              <textarea rows={3} value={fields.historical_notes} onChange={(e) => update("historical_notes", e.target.value)} disabled={loading || saving} /></label>
            <label className="artwork-dialog__field artwork-dialog__wide"><span>Color analysis</span>
              <textarea rows={5} value={fields.color_analysis} onChange={(e) => update("color_analysis", e.target.value)} disabled={loading || saving} /></label>
          </div>
          <footer className="artwork-dialog__footer">
            {exists && <button type="button" className="artwork-dialog__delete" onClick={remove} disabled={loading || saving}>
              {confirmDelete ? "Confirm Delete Metadata" : "Delete Metadata"}
            </button>}
            <span className="artwork-dialog__spacer" />
            <button type="button" onClick={() => onClose?.()} disabled={saving}>Cancel</button>
            <button type="submit" className="artwork-dialog__save" disabled={loading || saving}>{saving ? "Saving…" : "Save Artwork"}</button>
          </footer>
        </form>
      </section>
    </div>, document.body
  );
}
