import "./photo-editorial.css";
import { useEffect, useRef, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import { AdminButton, AdminDialog, AdminNotice } from "@components/AdminLayout";
import PhotoPreviewImage from "@components/PhotoPreview/PhotoPreviewImage";

const EDITORIAL_URL = `${API_FOLDER}/v2/admin/photo-library/editorial.php`;
const KEYS = ["design_notes", "refined_commentary", "photo_description"];
const EMPTY = { design_notes: "", refined_commentary: "", photo_description: "" };

function normalized(data) {
  return Object.fromEntries(KEYS.map((key) => [key, String(data?.[key] ?? "")]));
}

async function responseJson(response) {
  const raw = await response.text();
  let data;
  try { data = JSON.parse(raw); } catch { throw new Error(`Editorial API returned an invalid response (HTTP ${response.status}).`); }
  if (!response.ok || !data?.ok) throw new Error(data?.error || `Editorial API failed (HTTP ${response.status}).`);
  return data;
}

/** Reusable photo-ID-based admin editor. PHOTOS owns persistence; no playlist-specific state. */
export default function PhotoEditorialDialog({ open, photoLibraryId, imageUrl = "", onClose }) {
  const [fields, setFields] = useState(EMPTY);
  const [original, setOriginal] = useState(EMPTY);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const requestId = useRef(0);
  const dirty = KEYS.some((key) => fields[key] !== original[key]);

  useEffect(() => {
    if (!open || !Number(photoLibraryId)) return;
    const request = ++requestId.current;
    const controller = new AbortController();
    setFields(EMPTY);
    setOriginal(EMPTY);
    setLoading(true);
    setError("");
    setMessage("");
    (async () => {
      try {
        const params = new URLSearchParams({ photo_library_id: String(photoLibraryId) });
        const res = await fetch(`${EDITORIAL_URL}?${params}`, {
          credentials: "include", cache: "no-store", signal: controller.signal,
        });
        const data = await responseJson(res);
        if (request !== requestId.current) return;
        const loaded = normalized(data.editorial);
        setFields(loaded);
        setOriginal(loaded);
      } catch (err) {
        if (!controller.signal.aborted && request === requestId.current) setError(err.message || "Unable to load editorial.");
      } finally {
        if (request === requestId.current) setLoading(false);
      }
    })();
    return () => { controller.abort(); requestId.current += 1; };
  }, [open, photoLibraryId]);

  function close() {
    if (saving) return;
    if (dirty && !window.confirm("Discard unsaved Photo Editorial changes?")) return;
    onClose?.();
  }

  async function save() {
    if (saving || loading || !Number(photoLibraryId)) return;
    setSaving(true);
    setError("");
    setMessage("");
    try {
      const response = await fetch(EDITORIAL_URL, {
        method: "POST", credentials: "include", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ photo_library_id: Number(photoLibraryId), ...fields }),
      });
      const data = await responseJson(response);
      const saved = normalized(data.editorial);
      setFields(saved);
      setOriginal(saved);
      setMessage("Saved.");
    } catch (err) {
      setError(err.message || "Unable to save editorial.");
    } finally {
      setSaving(false);
    }
  }

  async function copyPhoto() {
    if (!imageUrl) return;
    setError("");
    try {
      if (!navigator.clipboard?.write || typeof ClipboardItem === "undefined") {
        throw new Error("Image copying isn't supported in this browser. Right-click the photo and choose Copy Image.");
      }
      // Chrome supports writing PNG data to the clipboard. Decode through a canvas
      // so JPEG/WebP photo sources work too, without altering the underlying image.
      const response = await fetch(imageUrl, { credentials: "include" });
      if (!response.ok) throw new Error("Unable to load the photo for copying.");
      const blob = await response.blob();
      const bitmap = await createImageBitmap(blob);
      try {
        const canvas = document.createElement("canvas");
        canvas.width = bitmap.width;
        canvas.height = bitmap.height;
        const context = canvas.getContext("2d");
        if (!context) throw new Error("Unable to prepare the photo for copying.");
        context.drawImage(bitmap, 0, 0);
        const png = await new Promise((resolve, reject) => {
          canvas.toBlob((result) => result ? resolve(result) : reject(new Error("Unable to copy photo.")), "image/png");
        });
        await navigator.clipboard.write([new ClipboardItem({ "image/png": png })]);
      } finally {
        bitmap.close();
      }
      setMessage("Photo copied. Paste it into AI.");
    } catch (err) {
      setError(err?.message || "Couldn't copy the photo. Try right-clicking it and choosing Copy Image.");
    }
  }

  async function copyNotes() {
    try {
      await navigator.clipboard.writeText(fields.design_notes);
      setMessage("Design notes copied.");
    } catch {
      setError("Could not copy notes to clipboard.");
    }
  }

  return (
    <AdminDialog
      open={Boolean(open)}
      title={`Photo Editorial · #${photoLibraryId || "?"}`}
      width={800}
      onClose={close}
      actions={<>
        <AdminButton type="button" onClick={save} disabled={loading || saving || !dirty}>{saving ? "Saving…" : "Save"}</AdminButton>
        <AdminButton type="button" variant="secondary" onClick={close} disabled={saving}>Close</AdminButton>
      </>}
    >
      <div className="photo-editorial">
        {imageUrl && <div className="photo-editorial__field">
          <PhotoPreviewImage key={photoLibraryId} className="photo-editorial__preview" src={imageUrl} alt="Selected photo" />
          <div className="photo-editorial__field-heading">
            <span />
            <AdminButton type="button" variant="secondary" onClick={copyPhoto}>Copy Photo</AdminButton>
          </div>
        </div>}
        {error && <AdminNotice variant="danger">{error}</AdminNotice>}
        {message && <AdminNotice>{message}</AdminNotice>}
        {loading ? <p>Loading editorial…</p> : <>
          <label className="photo-editorial__field">
            <span>Photo Description</span>
            <textarea rows={2} value={fields.photo_description} disabled={saving} placeholder="Describe what's visible, e.g. light living room with dark accent wall" onChange={(event) => setFields((prev) => ({ ...prev, photo_description: event.target.value }))} />
          </label>
          <div className="photo-editorial__field">
            <div className="photo-editorial__field-heading">
              <span>Design Notes</span>
              <AdminButton type="button" variant="secondary" disabled={!fields.design_notes || saving} onClick={copyNotes}>Copy Notes</AdminButton>
            </div>
            <textarea aria-label="Design Notes" rows={5} value={fields.design_notes} disabled={saving} placeholder="Explain your choices as you would to a client…" onChange={(event) => setFields((prev) => ({ ...prev, design_notes: event.target.value }))} />
          </div>
          <label className="photo-editorial__field">
            <span>Refined Commentary</span>
            <textarea rows={5} value={fields.refined_commentary} disabled={saving} placeholder="Paste AI-refined copy here, then edit…" onChange={(event) => setFields((prev) => ({ ...prev, refined_commentary: event.target.value }))} />
          </label>
        </>}
      </div>
    </AdminDialog>
  );
}
