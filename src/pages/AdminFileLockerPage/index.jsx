import { useEffect, useRef, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./admin-file-locker.css";

const GET_URL = `${API_FOLDER}/v2/admin/file-locker/get.php`;
const UPLOAD_URL = `${API_FOLDER}/v2/admin/file-locker/upload.php`;
const DELETE_URL = `${API_FOLDER}/v2/admin/file-locker/delete.php`;
const DOWNLOAD_URL = `${API_FOLDER}/v2/admin/file-locker/download.php`;
const LARGE_FILE_WARNING_BYTES = 200 * 1024 * 1024;

function formatBytes(value) {
  const size = Number(value || 0);
  if (!size) return "0 B";
  const units = ["B", "KB", "MB", "GB"];
  let current = size;
  let unitIndex = 0;
  while (current >= 1024 && unitIndex < units.length - 1) {
    current /= 1024;
    unitIndex += 1;
  }
  return `${current >= 100 ? Math.round(current) : current.toFixed(1).replace(/\.0$/, "")} ${units[unitIndex]}`;
}

function formatDateTime(value) {
  const raw = String(value || "").trim();
  if (!raw) return "";
  const parsed = new Date(raw.replace(" ", "T"));
  if (Number.isNaN(parsed.getTime())) return raw;
  return parsed.toLocaleString([], {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
}

async function parseJsonResponse(res, failureMessage) {
  const text = await res.text();
  try {
    return JSON.parse(text);
  } catch {
    const contentType = String(res.headers.get("content-type") || "").toLowerCase();
    const looksLikeHtml = contentType.includes("text/html") || /^\s*</.test(text);
    if (looksLikeHtml) {
      throw new Error(
        `${failureMessage} The server returned an HTML error page instead of a JSON response. This usually means the host rejected the upload, hit a PHP size limit, or timed out before ColorFix could respond.`
      );
    }
    throw new Error(`${failureMessage} The server returned an unreadable response.`);
  }
}

export default function AdminFileLockerPage() {
  const [item, setItem] = useState(null);
  const [loading, setLoading] = useState(true);
  const [uploading, setUploading] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [selectedFile, setSelectedFile] = useState(null);
  const [note, setNote] = useState("");
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const uploadControllerRef = useRef(null);

  async function loadLocker() {
    setLoading(true);
    setError("");
    try {
      const res = await fetch(`${GET_URL}?_=${Date.now()}`, { credentials: "include" });
      const data = await parseJsonResponse(res, "Failed to load file locker.");
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load file locker");
      setItem(data.item || null);
      setNote(String(data?.item?.note || ""));
    } catch (err) {
      setError(err?.message || "Failed to load file locker");
      setItem(null);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void loadLocker();
    return () => {
      uploadControllerRef.current?.abort();
      uploadControllerRef.current = null;
    };
  }, []);

  async function handleUpload(event) {
    event.preventDefault();
    if (!selectedFile) {
      setError("Choose a file first.");
      return;
    }

    setUploading(true);
    setError("");
    setNotice("");
    const controller = new AbortController();
    uploadControllerRef.current = controller;
    try {
      const formData = new FormData();
      formData.append("file", selectedFile);
      formData.append("note", note);
      const res = await fetch(UPLOAD_URL, {
        method: "POST",
        credentials: "include",
        body: formData,
        signal: controller.signal,
      });
      const data = await parseJsonResponse(res, "Upload failed.");
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to upload file");
      setItem(data.item || null);
      setSelectedFile(null);
      setNotice(item ? "File replaced." : "File uploaded.");
    } catch (err) {
      if (err?.name === "AbortError") {
        setNotice("Upload canceled.");
        return;
      }
      setError(err?.message || "Failed to upload file");
    } finally {
      uploadControllerRef.current = null;
      setUploading(false);
    }
  }

  function handleCancelUpload() {
    uploadControllerRef.current?.abort();
  }

  async function handleDelete() {
    if (!item) return;
    if (!window.confirm(`Delete "${item.original_name}" from File Locker?`)) return;
    setDeleting(true);
    setError("");
    setNotice("");
    try {
      const res = await fetch(DELETE_URL, {
        method: "POST",
        credentials: "include",
      });
      const data = await parseJsonResponse(res, "Failed to delete file.");
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to delete file");
      setItem(null);
      setSelectedFile(null);
      setNote("");
      setNotice("File deleted.");
    } catch (err) {
      setError(err?.message || "Failed to delete file");
    } finally {
      setDeleting(false);
    }
  }

  return (
    <div className="admin-file-locker">
      <div className="admin-file-locker__head">
        <div>
          <h1>File Locker</h1>
          <p>One private working-file slot for moving a file between machines.</p>
        </div>
        <div className="admin-file-locker__head-actions">
          <button type="button" className="admin-file-locker__btn" onClick={() => loadLocker()} disabled={loading}>
            Refresh
          </button>
        </div>
      </div>

      {error ? <div className="admin-file-locker__message admin-file-locker__message--error">{error}</div> : null}
      {notice ? <div className="admin-file-locker__message admin-file-locker__message--ok">{notice}</div> : null}

      <section className="admin-file-locker__card">
        <div className="admin-file-locker__card-title">Current File</div>
        {loading ? <div className="admin-file-locker__empty">Loading…</div> : null}
        {!loading && !item ? <div className="admin-file-locker__empty">No file in the locker right now.</div> : null}
        {!loading && item ? (
          <div className="admin-file-locker__current">
            <div className="admin-file-locker__file-name">{item.original_name}</div>
            <div className="admin-file-locker__meta">
              {formatBytes(item.file_size)} · Updated {formatDateTime(item.updated_at)}
              {!item.exists ? " · file missing on disk" : ""}
            </div>
            {item.note ? <div className="admin-file-locker__note">{item.note}</div> : null}
            <div className="admin-file-locker__actions">
              <a className="admin-file-locker__btn admin-file-locker__btn--primary" href={DOWNLOAD_URL}>
                Download
              </a>
              <button
                type="button"
                className="admin-file-locker__btn admin-file-locker__btn--danger"
                onClick={handleDelete}
                disabled={deleting}
              >
                {deleting ? "Deleting…" : "Delete"}
              </button>
            </div>
          </div>
        ) : null}
      </section>

      <form className="admin-file-locker__card admin-file-locker__form" onSubmit={handleUpload}>
        <div className="admin-file-locker__card-title">{item ? "Replace File" : "Upload File"}</div>
        <label className="admin-file-locker__stack">
          File
          <input
            type="file"
            disabled={uploading}
            onChange={(e) => setSelectedFile(e.target.files?.[0] || null)}
          />
        </label>
        <label className="admin-file-locker__stack">
          Note
          <textarea
            rows={3}
            value={note}
            onChange={(e) => setNote(e.target.value)}
            disabled={uploading}
            placeholder="Latest working version, what changed, where to pick up..."
          />
        </label>
        <div className="admin-file-locker__hint">
          One file only. New upload replaces the current one. App limit: 500 MB, but your server PHP upload limits may be lower.
        </div>
        {selectedFile ? (
          <div className="admin-file-locker__selected">
            Selected: {selectedFile.name} · {formatBytes(selectedFile.size)}
            {selectedFile.size > LARGE_FILE_WARNING_BYTES ? (
              <div className="admin-file-locker__large-warning">
                Large file warning: uploads this big may be rejected by Bluehost or PHP before ColorFix can answer.
              </div>
            ) : null}
          </div>
        ) : null}
        <div className="admin-file-locker__actions">
          <button type="submit" className="admin-file-locker__btn admin-file-locker__btn--primary" disabled={!selectedFile || uploading}>
            {uploading ? "Uploading…" : item ? "Replace File" : "Upload File"}
          </button>
          {uploading ? (
            <button type="button" className="admin-file-locker__btn" onClick={handleCancelUpload}>
              Cancel Upload
            </button>
          ) : null}
        </div>
      </form>
    </div>
  );
}
