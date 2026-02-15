import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./admin-photo-library.css";

const LIST_URL = `${API_FOLDER}/v2/admin/photo-library/list.php`;
const UPDATE_URL = `${API_FOLDER}/v2/admin/photo-library/update.php`;
const DELETE_URL = `${API_FOLDER}/v2/admin/photo-library/delete.php`;
const UPLOAD_URL = `${API_FOLDER}/v2/admin/photo-library/upload.php`;
const SAVED_UPLOAD_URL = `${API_FOLDER}/v2/admin/saved-palette-photos/upload.php`;
const SAVED_LIST_URL = `${API_FOLDER}/v2/admin/saved-palettes.php`;

const SOURCE_OPTIONS = [
  { value: "saved_palette", label: "Saved Palette" },
  { value: "progression", label: "Progression" },
  { value: "article", label: "Article" },
];

const defaultUpload = {
  source_type: "saved_palette",
  palette_id: "",
  series: "",
  title_prefix: "",
  tags: "",
  alt_text: "",
  show_in_gallery: false,
  has_palette: false,
};

const defaultFilters = {
  q: "",
  source_type: "",
  palette_id: "",
};

export default function AdminPhotoLibraryPage() {
  const [uploadForm, setUploadForm] = useState(defaultUpload);
  const [uploading, setUploading] = useState(false);
  const [uploadStatus, setUploadStatus] = useState({ error: "", success: "" });
  const [files, setFiles] = useState([]);

  const [filters, setFilters] = useState(defaultFilters);
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [refreshKey, setRefreshKey] = useState(0);

  const [savedPalettes, setSavedPalettes] = useState([]);

  useEffect(() => {
    let active = true;
    async function loadSavedPalettes() {
      try {
        const params = new URLSearchParams();
        params.set("limit", "200");
        params.set("_", Date.now().toString());
        const res = await fetch(`${SAVED_LIST_URL}?${params.toString()}`, { credentials: "include" });
        const data = await res.json();
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load saved palettes");
        if (!active) return;
        setSavedPalettes(Array.isArray(data.items) ? data.items : []);
      } catch {
        if (!active) return;
        setSavedPalettes([]);
      }
    }
    loadSavedPalettes();
    return () => {
      active = false;
    };
  }, []);

  const paletteOptions = useMemo(() => {
    return savedPalettes.map((palette) => ({
      id: palette.id,
      label: palette.nickname || palette.palette_hash || `Saved #${palette.id}`,
    }));
  }, [savedPalettes]);

  useEffect(() => {
    let active = true;
    async function loadLibrary() {
      setLoading(true);
      setError("");
      try {
        const params = new URLSearchParams();
        if (filters.q.trim()) params.set("q", filters.q.trim());
        if (filters.source_type) params.set("source_type", filters.source_type);
        if (filters.palette_id) params.set("palette_id", filters.palette_id);
        params.set("limit", "200");
        params.set("_", Date.now().toString());
        const res = await fetch(`${LIST_URL}?${params.toString()}`, { credentials: "include" });
        const data = await res.json();
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load photo library");
        if (!active) return;
        setItems(Array.isArray(data.items) ? data.items : []);
      } catch (err) {
        if (!active) return;
        setItems([]);
        setError(err?.message || "Failed to load photo library");
      } finally {
        if (active) setLoading(false);
      }
    }
    loadLibrary();
    return () => {
      active = false;
    };
  }, [filters, refreshKey]);

  const handleUploadField = (key, value) => {
    setUploadForm((prev) => ({ ...prev, [key]: value }));
  };

  const handleLibraryField = (id, key, value) => {
    setItems((prev) =>
      prev.map((item) => (item.photo_library_id === id ? { ...item, [key]: value } : item))
    );
  };

  const resetUpload = () => {
    setUploadForm(defaultUpload);
    setFiles([]);
    setUploadStatus({ error: "", success: "" });
  };

  const handleUploadSubmit = async (event) => {
    event.preventDefault();
    if (!files.length) {
      setUploadStatus({ error: "Select at least one photo.", success: "" });
      return;
    }
    if (uploadForm.source_type === "saved_palette" && !uploadForm.palette_id) {
      setUploadStatus({ error: "Choose a saved palette.", success: "" });
      return;
    }
    setUploading(true);
    setUploadStatus({ error: "", success: "" });
    try {
      const formData = new FormData();
      Array.from(files).forEach((file) => formData.append("photos[]", file));

      let res;
      if (uploadForm.source_type === "saved_palette") {
        formData.append("palette_id", String(uploadForm.palette_id));
        res = await fetch(SAVED_UPLOAD_URL, {
          method: "POST",
          credentials: "include",
          body: formData,
        });
      } else {
        formData.append("source_type", uploadForm.source_type);
        formData.append("series", uploadForm.series || "");
        formData.append("title_prefix", uploadForm.title_prefix || "");
        formData.append("tags", uploadForm.tags || "");
        formData.append("alt_text", uploadForm.alt_text || "");
        if (uploadForm.show_in_gallery) formData.append("show_in_gallery", "1");
        if (uploadForm.has_palette) formData.append("has_palette", "1");
        res = await fetch(UPLOAD_URL, {
          method: "POST",
          credentials: "include",
          body: formData,
        });
      }

      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || `HTTP ${res.status}`);
      }
      setUploadStatus({ error: "", success: "Upload complete." });
      resetUpload();
      setRefreshKey((prev) => prev + 1);
    } catch (err) {
      setUploadStatus({ error: err?.message || "Upload failed", success: "" });
    } finally {
      setUploading(false);
    }
  };

  const handleLibrarySave = async (item) => {
    setError("");
    try {
      const res = await fetch(UPDATE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          photo_library_id: item.photo_library_id,
          title: item.title,
          tags: item.tags,
          alt_text: item.alt_text,
          show_in_gallery: !!item.show_in_gallery,
          has_palette: !!item.has_palette,
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Save failed");
      }
      setRefreshKey((prev) => prev + 1);
    } catch (err) {
      setError(err?.message || "Failed to save row");
    }
  };

  const handleLibraryDelete = async (item) => {
    if (!window.confirm("Delete this photo from the library?")) return;
    setError("");
    try {
      const res = await fetch(DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ photo_library_id: item.photo_library_id }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Delete failed");
      }
      setItems((prev) => prev.filter((row) => row.photo_library_id !== item.photo_library_id));
      setRefreshKey((prev) => prev + 1);
    } catch (err) {
      setError(err?.message || "Failed to delete row");
    }
  };

  const showSavedFields = uploadForm.source_type === "saved_palette";
  const showLibraryFields = uploadForm.source_type !== "saved_palette";

  useEffect(() => {
    if (uploadForm.source_type === "saved_palette" && uploadForm.palette_id) {
      setFilters((prev) => ({
        ...prev,
        source_type: "saved_palette_photo",
        palette_id: uploadForm.palette_id,
      }));
    }
  }, [uploadForm.source_type, uploadForm.palette_id]);

  return (
    <div className="admin-photo-library">
      <header className="admin-photo-library__header">
        <h1>Photo Library</h1>
        <p>Upload and tag photos for playlists, progressions, or saved palettes.</p>
      </header>

      <section className="admin-photo-library__section">
        <h2>Upload Photos</h2>
        {uploadStatus.error && <div className="admin-photo-library__error">{uploadStatus.error}</div>}
        {uploadStatus.success && <div className="admin-photo-library__status">{uploadStatus.success}</div>}
        <form className="admin-photo-library__upload" onSubmit={handleUploadSubmit}>
          <label>
            Type
            <select
              value={uploadForm.source_type}
              onChange={(e) => handleUploadField("source_type", e.target.value)}
            >
              {SOURCE_OPTIONS.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
          </label>

          {showSavedFields && (
            <label>
              Saved Palette
              <select
                value={uploadForm.palette_id}
                onChange={(e) => handleUploadField("palette_id", e.target.value)}
              >
                <option value="">Select palette</option>
                {paletteOptions.map((palette) => (
                  <option key={palette.id} value={palette.id}>
                    {palette.label}
                  </option>
                ))}
              </select>
            </label>
          )}

          {showLibraryFields && (
            <label>
              Series (folder label)
              <input
                type="text"
                value={uploadForm.series}
                onChange={(e) => handleUploadField("series", e.target.value)}
                placeholder="ranch-demo"
              />
            </label>
          )}

          {showLibraryFields && (
            <label>
              Title prefix
              <input
                type="text"
                value={uploadForm.title_prefix}
                onChange={(e) => handleUploadField("title_prefix", e.target.value)}
                placeholder="Ranch progression"
              />
            </label>
          )}

          {showLibraryFields && (
            <label>
              Tags (comma separated)
              <input
                type="text"
                value={uploadForm.tags}
                onChange={(e) => handleUploadField("tags", e.target.value)}
                placeholder="ranch, progression, exterior"
              />
            </label>
          )}

          {showLibraryFields && (
            <label>
              Alt text (SEO)
              <input
                type="text"
                value={uploadForm.alt_text}
                onChange={(e) => handleUploadField("alt_text", e.target.value)}
                placeholder="Optional alt text"
              />
            </label>
          )}

          {showLibraryFields && (
            <label className="admin-photo-library__check">
              <input
                type="checkbox"
                checked={uploadForm.show_in_gallery}
                onChange={(e) => handleUploadField("show_in_gallery", e.target.checked)}
              />
              Show in gallery
            </label>
          )}

          {showLibraryFields && (
            <label className="admin-photo-library__check">
              <input
                type="checkbox"
                checked={uploadForm.has_palette}
                onChange={(e) => handleUploadField("has_palette", e.target.checked)}
              />
              Has palette
            </label>
          )}

          <label className="admin-photo-library__file">
            Photos
            <input
              type="file"
              accept="image/*"
              multiple
              onChange={(e) => setFiles(Array.from(e.target.files || []))}
              disabled={uploading}
            />
          </label>

          <div className="admin-photo-library__upload-actions">
            <button type="submit" disabled={uploading}>
              {uploading ? "Uploading…" : "Upload"}
            </button>
            <button type="button" className="ghost" onClick={resetUpload} disabled={uploading}>
              Clear
            </button>
          </div>
        </form>
      </section>

      <section className="admin-photo-library__section">
        <h2>Library</h2>
        {error && <div className="admin-photo-library__error">{error}</div>}
        <div className="admin-photo-library__filters">
          <label>
            Search
            <input
              type="text"
              value={filters.q}
              onChange={(e) => setFilters((prev) => ({ ...prev, q: e.target.value }))}
              placeholder="title, tags, path…"
            />
          </label>
          <label>
            Type
            <select
              value={filters.source_type}
              onChange={(e) => setFilters((prev) => ({ ...prev, source_type: e.target.value }))}
            >
              <option value="">All</option>
              <option value="saved_palette_photo">Saved palette</option>
              <option value="progression">Progression</option>
              <option value="article">Article</option>
              <option value="extra_photo">Extras</option>
            </select>
          </label>
          {filters.source_type === "saved_palette_photo" && (
            <label>
              Palette
              <select
                value={filters.palette_id}
                onChange={(e) => setFilters((prev) => ({ ...prev, palette_id: e.target.value }))}
              >
                <option value="">All palettes</option>
                {paletteOptions.map((palette) => (
                  <option key={palette.id} value={palette.id}>
                    {palette.label}
                  </option>
                ))}
              </select>
            </label>
          )}
        </div>

        {loading ? (
          <div className="admin-photo-library__loading">Loading…</div>
        ) : (
          <div className="admin-photo-library__table-wrap">
            <table className="admin-photo-library__table">
              <thead>
                <tr>
                  <th>Preview</th>
                  <th>Title</th>
                  <th>Tags</th>
                  <th>Alt Text</th>
                  <th>Type</th>
                  <th>Flags</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={item.photo_library_id}>
                    <td>
                      <a href={item.rel_path} target="_blank" rel="noreferrer">
                        <img src={item.rel_path} alt="" />
                      </a>
                    </td>
                    <td>
                      <input
                        type="text"
                        value={item.title || ""}
                        onChange={(e) => handleLibraryField(item.photo_library_id, "title", e.target.value)}
                      />
                    </td>
                    <td>
                      <input
                        type="text"
                        value={item.tags || ""}
                        onChange={(e) => handleLibraryField(item.photo_library_id, "tags", e.target.value)}
                      />
                    </td>
                    <td>
                      <input
                        type="text"
                        value={item.alt_text || ""}
                        onChange={(e) => handleLibraryField(item.photo_library_id, "alt_text", e.target.value)}
                      />
                    </td>
                    <td>
                      <div className="admin-photo-library__meta">
                        <div>{item.source_type}</div>
                        {item.source_id && <div className="muted">#{item.source_id}</div>}
                      </div>
                    </td>
                    <td>
                      <label className="admin-photo-library__check">
                        <input
                          type="checkbox"
                          checked={!!item.show_in_gallery}
                          onChange={(e) => handleLibraryField(item.photo_library_id, "show_in_gallery", e.target.checked)}
                        />
                        Gallery
                      </label>
                      <label className="admin-photo-library__check">
                        <input
                          type="checkbox"
                          checked={!!item.has_palette}
                          onChange={(e) => handleLibraryField(item.photo_library_id, "has_palette", e.target.checked)}
                        />
                        Palette
                      </label>
                    </td>
                    <td className="admin-photo-library__row-actions">
                      <button type="button" className="ghost" onClick={() => handleLibrarySave(item)}>
                        Save
                      </button>
                      {(item.source_type === "progression" || item.source_type === "article") && (
                        <button type="button" className="ghost danger" onClick={() => handleLibraryDelete(item)}>
                          Delete
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
                {!items.length && (
                  <tr>
                    <td colSpan={7} className="admin-photo-library__empty">No photos yet.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}
