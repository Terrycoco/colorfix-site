import PhotoPreviewImage from "@components/PhotoPreview/PhotoPreviewImage";
import { photoImageProps } from "@helpers/imageThumb";
import { useEffect, useId, useState } from "react";
import { createPortal } from "react-dom";
import { API_FOLDER } from "@helpers/config";
import PhotoPickerDialog from "@components/Dialogs/PhotoPickerDialog";
import { AdminEditorForm } from "@components/AdminLayout";
import "./ArtworkDialog.css";

const API_URL = `${API_FOLDER}/v2/admin/artwork/index.php`;
const INSPIRATION_URL = `${API_FOLDER}/v2/admin/photos/inspiration.php`;
const BLANK = {
  artist: "", artist_last: "", official_title: "", display_title: "", year: "", circa: false,
  medium: "", museum: "", source_url: "", historical_notes: "", color_analysis: "",
};

async function callApi(url, options) {
  const response = await fetch(url, { credentials: "include", cache: "no-store", ...options });
  const data = await response.json().catch(() => ({}));
  if (!response.ok || !data.ok) throw new Error(data.error || `Artwork request failed (${response.status})`);
  return data;
}

/** Reusable metadata editor: ARTWORK owns metadata; PHOTOS retains the photo. */
/** Photo Library retains the modal entry point. */
export default function ArtworkDialog(props) {
  return <ArtworkEditorContent {...props} embedded={false} />;
}

/** Admin master/detail always renders an inline editor, never a portal. */
export function ArtworkInlineEditor(props) {
  return <ArtworkEditorContent {...props} open={true} embedded={true} />;
}

function ArtworkEditorContent({ open, photoLibraryId, imageUrl, onClose, onSaved, onDeleted, embedded }) {
  const titleId = useId();
  const [fields, setFields] = useState(BLANK);
  const [exists, setExists] = useState(false);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [designs, setDesigns] = useState([]);
  const [pickerOpen, setPickerOpen] = useState(false);
  const [linkBusy, setLinkBusy] = useState(false);
  const [linkLoading, setLinkLoading] = useState(false);
  const [linkError, setLinkError] = useState("");

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
    if (!open || !photoLibraryId) return;
    const controller = new AbortController();
    setPickerOpen(false); setDesigns([]); setLinkError(""); setLinkLoading(true);
    callApi(`${INSPIRATION_URL}?action=list&artwork_photo_id=${encodeURIComponent(photoLibraryId)}`, { signal: controller.signal })
      .then(({ items }) => { if (!controller.signal.aborted) setDesigns(items || []); })
      .catch((err) => { if (err.name !== "AbortError") setLinkError(err.message); })
      .finally(() => { if (!controller.signal.aborted) setLinkLoading(false); });
    return () => controller.abort();
  }, [open, photoLibraryId]);

  async function changeDesign(photoId, action) {
    if (linkBusy) return;
    setLinkBusy(true); setLinkError("");
    try {
      await callApi(INSPIRATION_URL, { method: "POST", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action, photo_id: Number(photoId), artwork_photo_id: Number(photoLibraryId) }) });
      const { items } = await callApi(`${INSPIRATION_URL}?action=list&artwork_photo_id=${encodeURIComponent(photoLibraryId)}`);
      setDesigns(items || []);

    } catch (err) { setLinkError(err.message); }
    finally { setLinkBusy(false); }
  }

  async function pickDesign(photo) {
    setPickerOpen(false);
    const selectedId = Number(photo.photo_library_id);
    if (!Number.isInteger(selectedId) || selectedId <= 0 || selectedId === Number(photoLibraryId)) {
      setLinkError("Choose an inspired room, not the source painting.");
      return;
    }
    if (designs.some((design) => Number(design.photo_library_id) === selectedId)) return;
    await changeDesign(selectedId, "set");
  }

  const imageSource = (photo) => {
    const rel = String(photo.rel_path || "");
    if (/^https?:\/\//i.test(rel)) return rel;
    return rel.startsWith("/") ? rel : `/${rel}`;
  };

  useEffect(() => {
    if (!open || embedded) return;
    const onKeyDown = (e) => { if (e.key === "Escape" && !saving && !pickerOpen) onClose?.(); };
    document.addEventListener("keydown", onKeyDown);
    return () => document.removeEventListener("keydown", onKeyDown);
  }, [open, embedded, saving, pickerOpen, onClose]);

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
      setExists(true); onSaved?.(artwork); if (!embedded) onClose?.();
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
      onDeleted?.(Number(photoLibraryId)); if (!embedded) onClose?.();
    } catch (err) { setError(err.message); }
    finally { setSaving(false); }
  }

  if (!open || (!embedded && typeof document === "undefined")) return null;
  const field = (name, label, props = {}) => (
    <label className="artwork-dialog__field" key={name}>
      <span>{label}</span>
      <input value={fields[name]} onChange={(e) => update(name, e.target.value)} disabled={loading || saving} {...props} />
    </label>
  );

  // One set of fields and handlers. Only the modal entry point gets dialog chrome.
  const formContents = (
    <>
          {imageUrl && <PhotoPreviewImage className="artwork-dialog__photo" {...photoImageProps(photoLibraryId, imageUrl, { width: 800 })} alt="Artwork photograph" />}
          {loading && <p role="status">Loading artwork…</p>}
          {error && <p className="artwork-dialog__error" role="alert">{error}</p>}
          <div className="artwork-dialog__fields">
            {field("artist", "Artist")}
            {field("artist_last", "Artist last name", { placeholder: "e.g. van Gogh" })}
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
          <section className="artwork-dialog__inspired" aria-label="Designs inspired by this artwork">
            <h3>Designs Inspired by This Artwork</h3>

            {linkError && <p className="artwork-dialog__error" role="alert">{linkError}</p>}
            {linkLoading ? <p role="status">Loading designs…</p> : (
              <div className="artwork-dialog__design-list">
                {designs.length === 0 && <p className="artwork-dialog__hint">No designs linked yet.</p>}
                {designs.map((design) => (
                  <div className="artwork-dialog__design" key={design.photo_library_id}>
                    {design.rel_path && <PhotoPreviewImage {...photoImageProps(design.photo_library_id, imageSource(design))} loading="lazy" alt="" />}
                    <span>{design.title || `Photo #${design.photo_library_id}`} <small>#{design.photo_library_id}</small></span>
                    <button type="button" disabled={linkBusy}
                      aria-label={`Edit photo #${design.photo_library_id} in Photo Library`}
                      onClick={() => { window.location.href = `/admin/photo-library?photo_library_ids=${encodeURIComponent(design.photo_library_id)}&include_inactive=1`; }}>
                      Edit
                    </button>
                    <button type="button" disabled={linkBusy} onClick={() => changeDesign(design.photo_library_id, "remove")}>Remove</button>
                  </div>
                ))}
              </div>
            )}
          </section>
          <footer className="artwork-dialog__footer">
            {exists && <button type="button" className="artwork-dialog__delete" onClick={remove} disabled={loading || saving}>
              {confirmDelete ? "Confirm Delete Metadata" : "Delete Metadata"}
            </button>}
            <button type="button" className="artwork-dialog__choose" disabled={linkBusy || linkLoading}
              onClick={() => { setLinkError(""); setPickerOpen(true); }}>
              Photo Library
            </button>
            <span className="artwork-dialog__spacer" />
            {!embedded && <button type="button" onClick={() => onClose?.()} disabled={saving}>Cancel</button>}
            <button type="submit" className="artwork-dialog__save" disabled={loading || saving}>{saving ? "Saving…" : "Save Artwork"}</button>
          </footer>
    </>
  );

  const form = embedded ? (
    <AdminEditorForm
      onSubmit={save}
      className="artwork-dialog__form"
      style={{ padding: 0, overflow: "visible", minWidth: 0 }}
    >
      {formContents}
    </AdminEditorForm>
  ) : (
    <form onSubmit={save} className="artwork-dialog__form">
      {formContents}
    </form>
  );

  return (
    <>
      {embedded ? form : createPortal(
        <div
          className={`artwork-dialog__backdrop${pickerOpen ? " artwork-dialog__backdrop--under-picker" : ""}`}
          onMouseDown={(event) => {
            if (event.target === event.currentTarget && !saving && !pickerOpen) onClose?.();
          }}
        >
          <section role="dialog" aria-modal="true" aria-labelledby={titleId} className="artwork-dialog">
            <header className="artwork-dialog__header">
              <h2 id={titleId}>{exists ? "Edit Artwork" : "Add Artwork"} · Photo #{photoLibraryId}</h2>
              <button type="button" onClick={() => onClose?.()} disabled={saving} aria-label="Close artwork editor">×</button>
            </header>
            {form}
          </section>
        </div>,
        document.body
      )}
      <PhotoPickerDialog
        open={pickerOpen}
        title="Choose an Inspired Design"
        onClose={() => setPickerOpen(false)}
        onPick={pickDesign}
      />
    </>
  );
}
