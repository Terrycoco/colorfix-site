import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import PermissionStatus from "@components/PermissionStatus";
import "./admin-asset-library.css";

const LIST_URL = `${API_FOLDER}/v2/admin/asset-library/list.php`;
const DELETE_URL = `${API_FOLDER}/v2/admin/asset-library/delete.php`;

const defaultFilters = {
  q: "",
  asset_kind: "",
  source_type: "",
  sort: "newest",
  include_inactive: false,
};

const kindOptions = [
  { value: "", label: "All kinds" },
  { value: "image", label: "Images" },
  { value: "video", label: "Videos" },
  { value: "document", label: "Documents" },
  { value: "audio", label: "Audio" },
  { value: "other", label: "Other" },
];

const sortOptions = [
  { value: "newest", label: "Newest first" },
  { value: "oldest", label: "Oldest first" },
  { value: "id_desc", label: "ID high-low" },
  { value: "id_asc", label: "ID low-high" },
  { value: "title", label: "Title" },
  { value: "kind", label: "Kind" },
];

export default function AdminAssetLibraryPage() {
  const [filters, setFilters] = useState(defaultFilters);
  const [searchInput, setSearchInput] = useState("");
  const [items, setItems] = useState([]);
  const [selectedItem, setSelectedItem] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [deletingId, setDeletingId] = useState(null);

  const sourceTypes = useMemo(() => {
    const seen = new Set();
    for (const item of items) {
      const value = String(item.source_type || "").trim();
      if (value) seen.add(value);
    }
    return [...seen].sort((a, b) => a.localeCompare(b));
  }, [items]);

  useEffect(() => {
    fetchAssets();
  }, []);

  async function fetchAssets(nextFilters = filters) {
    setLoading(true);
    setError("");
    try {
      const params = new URLSearchParams();
      if (nextFilters.q.trim()) params.set("q", nextFilters.q.trim());
      if (nextFilters.asset_kind) params.set("asset_kind", nextFilters.asset_kind);
      if (nextFilters.source_type) params.set("source_type", nextFilters.source_type);
      if (nextFilters.include_inactive) params.set("include_inactive", "1");
      params.set("sort", nextFilters.sort || "newest");
      params.set("limit", "200");
      params.set("_", String(Date.now()));

      const res = await fetch(`${LIST_URL}?${params.toString()}`, { credentials: "include" });
      const data = await parseJsonResponse(res, "Asset library");
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load library");
      setItems(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load library");
    } finally {
      setLoading(false);
    }
  }

  function updateFilter(key, value) {
    setFilters((prev) => ({ ...prev, [key]: value }));
  }

  function submitSearch(event) {
    event.preventDefault();
    const next = { ...filters, q: searchInput };
    setFilters(next);
    fetchAssets(next);
  }

  function refresh() {
    fetchAssets(filters);
  }

  async function deleteAsset(item) {
    if (!item?.asset_library_id) return;
    const label = item.title || item.rel_path || `Asset #${item.asset_library_id}`;
    if (!window.confirm(`Hard delete "${label}"? This is only allowed if it has not been published.`)) return;

    setDeletingId(item.asset_library_id);
    setError("");
    try {
      const res = await fetch(DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ asset_library_id: item.asset_library_id }),
      });
      const data = await parseJsonResponse(res, "Delete asset");
      if (!res.ok || !data?.ok) {
        const blockers = data?.item?.blockers?.length ? ` ${data.item.blockers.join("; ")}` : "";
        throw new Error((data?.error || "Failed to delete asset") + blockers);
      }
      setItems((prev) => prev.filter((row) => row.asset_library_id !== item.asset_library_id));
      setSelectedItem(null);
    } catch (err) {
      setError(err?.message || "Failed to delete asset");
    } finally {
      setDeletingId(null);
    }
  }

  async function deleteUnpublishedShown() {
    const ids = items.map((item) => Number(item.asset_library_id || 0)).filter(Boolean);
    if (!ids.length) {
      setError("No assets are currently shown.");
      return;
    }
    const ok = window.confirm(`Delete ${ids.length} asset${ids.length === 1 ? "" : "s"} currently shown? Published or locked assets will block the delete.`);
    if (!ok) return;

    setDeletingId("bulk");
    setError("");
    try {
      const res = await fetch(DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ asset_library_ids: ids }),
      });
      const data = await parseJsonResponse(res, "Delete unpublished assets");
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to delete unpublished assets");
      const deletedIds = new Set((data.item?.deleted_asset_ids || []).map(Number));
      setItems((prev) => prev.filter((row) => !deletedIds.has(Number(row.asset_library_id))));
      if (selectedItem && deletedIds.has(Number(selectedItem.asset_library_id))) {
        setSelectedItem(null);
      }
    } catch (err) {
      setError(err?.message || "Failed to delete unpublished assets");
    } finally {
      setDeletingId(null);
    }
  }

  return (
    <div className="admin-asset-library">
      <header className="assetlib-header">
        <div>
          <h1>Library</h1>
          <p>File catalog for images, videos, documents, generated assets, and future channel files.</p>
        </div>
        <button type="button" className="assetlib-command" onClick={refresh} disabled={loading}>
          Refresh
        </button>
      </header>

      <form className="assetlib-toolbar" onSubmit={submitSearch}>
        <label className="assetlib-search">
          Search
          <input
            value={searchInput}
            onChange={(event) => setSearchInput(event.target.value)}
            placeholder="Asset, job, playlist, title, tag, path"
          />
        </label>
        <label>
          Kind
          <select value={filters.asset_kind} onChange={(event) => updateFilter("asset_kind", event.target.value)}>
            {kindOptions.map((option) => (
              <option key={option.value} value={option.value}>{option.label}</option>
            ))}
          </select>
        </label>
        <label>
          Source
          <select value={filters.source_type} onChange={(event) => updateFilter("source_type", event.target.value)}>
            <option value="">All sources</option>
            {sourceTypes.map((sourceType) => (
              <option key={sourceType} value={sourceType}>{sourceType}</option>
            ))}
          </select>
        </label>
        <label>
          Sort
          <select value={filters.sort} onChange={(event) => updateFilter("sort", event.target.value)}>
            {sortOptions.map((option) => (
              <option key={option.value} value={option.value}>{option.label}</option>
            ))}
          </select>
        </label>
        <label className="assetlib-check">
          <input
            type="checkbox"
            checked={filters.include_inactive}
            onChange={(event) => updateFilter("include_inactive", event.target.checked)}
          />
          Include inactive
        </label>
        <button type="submit" className="assetlib-command assetlib-command--primary" disabled={loading}>
          Find
        </button>
        <button
          type="button"
          className="assetlib-command assetlib-command--danger"
          onClick={deleteUnpublishedShown}
          disabled={loading || deletingId === "bulk" || !items.length}
          title="Deletes unpublished assets currently shown. Published or locked assets are blocked."
        >
          {deletingId === "bulk" ? "Deleting..." : `Delete Unpublished Shown (${items.length})`}
        </button>
      </form>

      {error ? <div className="assetlib-status assetlib-status--error">{error}</div> : null}

      <section className="assetlib-grid-wrap">
        <table className="assetlib-grid">
          <thead>
            <tr>
              <th>Asset ID</th>
              <th>Preview</th>
              <th>Kind</th>
              <th>Pin Type</th>
              <th>Creator Job</th>
              <th>Source Playlist</th>
              <th>Permission</th>
              <th>MIME</th>
              <th>Title</th>
              <th>Source</th>
              <th>Created</th>
              <th>State</th>
            </tr>
          </thead>
          <tbody>
            {items.map((item) => (
              <tr key={item.asset_library_id} onClick={() => setSelectedItem(item)}>
                <td>{item.asset_library_id}</td>
                <td>
                  <AssetPreview item={item} />
                </td>
                <td>{item.asset_kind || "-"}</td>
                <td>{pinTypeLabel(item.pin_type)}</td>
                <td>{creatorJobLabel(item)}</td>
                <td>{sourcePlaylistLabel(item)}</td>
                <td>
                  <PermissionStatus {...permissionProps(item)} />
                </td>
                <td>{item.mime_type || "-"}</td>
                <td>{item.title || "-"}</td>
                <td>{sourceLabel(item)}</td>
                <td>{item.created_at || "-"}</td>
                <td>{stateLabel(item)}</td>
              </tr>
            ))}
            {!loading && items.length === 0 ? (
              <tr>
                <td colSpan={12} className="assetlib-empty">
                  No library assets found.
                </td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </section>

      {selectedItem ? (
        <AssetDialog
          item={selectedItem}
          deleting={deletingId === selectedItem.asset_library_id}
          onDelete={() => deleteAsset(selectedItem)}
          onClose={() => setSelectedItem(null)}
        />
      ) : null}
    </div>
  );
}

function AssetPreview({ item }) {
  const isImage = String(item.asset_kind || "").toLowerCase() === "image";
  if (isImage && item.public_url) {
    return <img className="assetlib-thumb" src={item.public_url} alt={item.title || ""} loading="lazy" />;
  }
  return <span className="assetlib-file-badge">{String(item.asset_kind || "file").toUpperCase()}</span>;
}

function AssetDialog({ item, deleting = false, onDelete, onClose }) {
  return (
    <div className="assetlib-modal-backdrop" role="presentation">
      <div className="assetlib-modal" role="dialog" aria-modal="true" aria-label="Library asset">
        <header className="assetlib-modal__header">
          <h2>Library Asset #{item.asset_library_id}</h2>
          <button type="button" onClick={onClose}>Close</button>
        </header>
        <div className="assetlib-modal__body">
          <div className="assetlib-modal__preview">
            <AssetPreview item={item} />
          </div>
          <dl className="assetlib-details">
            <dt>Kind</dt><dd>{item.asset_kind || "-"}</dd>
            <dt>Permission</dt><dd><PermissionStatus {...permissionProps(item)} showLabel /></dd>
            <dt>MIME</dt><dd>{item.mime_type || "-"}</dd>
            <dt>Pin type</dt><dd>{pinTypeLabel(item.pin_type)}</dd>
            <dt>Creator job</dt><dd>{creatorJobLabel(item)}</dd>
            <dt>Source playlist</dt><dd>{sourcePlaylistLabel(item)}</dd>
            <dt>Source photos</dt><dd>{sourcePhotoSummary(item)}</dd>
            <dt>Title</dt><dd>{item.title || "-"}</dd>
            <dt>Tags</dt><dd>{item.tags || "-"}</dd>
            <dt>Alt text</dt><dd>{item.alt_text || "-"}</dd>
            <dt>Path</dt><dd>{item.rel_path || "-"}</dd>
            <dt>Public URL</dt>
            <dd>{item.public_url ? <a href={item.public_url} target="_blank" rel="noreferrer">{item.public_url}</a> : "-"}</dd>
            <dt>Source</dt><dd>{sourceLabel(item)}</dd>
            <dt>Size</dt><dd>{sizeLabel(item)}</dd>
            <dt>Created</dt><dd>{item.created_at || "-"}</dd>
            <dt>Updated</dt><dd>{item.updated_at || "-"}</dd>
            <dt>State</dt><dd>{stateLabel(item)}</dd>
          </dl>
        </div>
        <div className="assetlib-modal__actions">
          <button type="button" className="assetlib-danger" onClick={onDelete} disabled={deleting}>
            {deleting ? "Deleting..." : "Delete Asset"}
          </button>
          <button type="button" onClick={onClose}>Close</button>
        </div>
      </div>
    </div>
  );
}

function permissionProps(item = {}) {
  return {
    status: item.photo_permission_status,
    photoLibraryId: item.permission_photo_library_id || item.legacy_photo_library_id,
    clientId: item.client_id,
    clientName: item.client_name,
    clientEmail: item.client_email,
  };
}

async function parseJsonResponse(res, label) {
  const text = await res.text();
  try {
    return JSON.parse(text);
  } catch {
    const preview = text.replace(/\s+/g, " ").slice(0, 160);
    throw new Error(`${label} returned non-JSON (${res.status} ${res.statusText}): ${preview}`);
  }
}

function sourceLabel(item) {
  const sourceType = item.source_type || "-";
  return item.source_id ? `${sourceType} #${item.source_id}` : sourceType;
}

function creatorJobLabel(item) {
  const jobId = Number(item.creator_job_id || (item.source_type === "asset_creator_job" ? item.source_id : 0) || 0);
  if (!jobId) return "-";
  const title = String(item.creator_job_title || "").trim();
  return title ? `#${jobId} ${title}` : `#${jobId}`;
}

function sourcePlaylistLabel(item) {
  const playlistId = Number(item.source_playlist_id || 0);
  const title = String(item.source_playlist_title || "").trim();
  if (!playlistId && !title) return "-";
  if (!playlistId) return title;
  return title ? `#${playlistId} ${title}` : `#${playlistId}`;
}

function pinTypeLabel(pinType) {
  const value = String(pinType || "").trim();
  if (value === "composite") return "Composite";
  if (value === "idea") return "Idea";
  if (value === "idea_palette") return "Idea + Palette";
  return value || "-";
}

function sourcePhotoSummary(item) {
  const metadata = parseMetadata(item.metadata_json);
  const beforeId = Number(metadata?.before?.photo_library_id || 0);
  const afterId = Number(metadata?.after?.photo_library_id || metadata?.asset?.photo_library_id || 0);
  const directId = Number(item.legacy_photo_library_id || metadata?.photo_library_id || 0);
  const parts = [];
  if (beforeId) parts.push(`Before #${beforeId}`);
  if (afterId) parts.push(`After #${afterId}`);
  if (!parts.length && directId) parts.push(`Photo #${directId}`);
  return parts.length ? parts.join(" / ") : "-";
}

function parseMetadata(value) {
  if (!value) return {};
  if (typeof value === "object") return value;
  try {
    const parsed = JSON.parse(value);
    return parsed && typeof parsed === "object" ? parsed : {};
  } catch {
    return {};
  }
}

function stateLabel(item) {
  if (item.is_retired) return "Retired";
  if (item.is_inactive) return "Inactive";
  return "Active";
}

function sizeLabel(item) {
  const width = Number(item.width || 0);
  const height = Number(item.height || 0);
  const duration = Number(item.duration_seconds || 0);
  const parts = [];
  if (width && height) parts.push(`${width} x ${height}`);
  if (duration) parts.push(`${duration}s`);
  if (item.file_size_bytes) parts.push(`${item.file_size_bytes} bytes`);
  return parts.length ? parts.join(" / ") : "-";
}
