import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import PermissionStatus from "@components/PermissionStatus";
import "./admin-publishing.css";

const JOBS_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/list.php`;
const SAVE_PINTEREST_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/save.php`;
const MARK_PINTEREST_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/mark-published.php`;
const PLAYLISTS_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;
const INSTANCES_URL = `${API_FOLDER}/v2/admin/playlist-instances/list.php`;
const LANDING_PAGES_URL = `${API_FOLDER}/v2/admin/landing-pages/list.php`;

const assetTypes = [
  {
    value: "pinterest.before_after_pin",
    label: "Pinterest - Before/After Pin",
    enabled: true,
  },
  {
    value: "youtube.playlist_video",
    label: "YouTube - Playlist Video",
    enabled: false,
  },
  {
    value: "youtube.pptx_draft",
    label: "YouTube - PPTX Draft",
    enabled: false,
  },
];

const emptyForm = {
  asset_type: "pinterest.before_after_pin",
  playlist_id: "",
  playlist_instance_id: "",
  landing_page_id: "",
  title: "",
  description: "",
  board: "",
  external_url: "",
  library_asset_id: "",
  published_at: "",
  notes: "",
};

const importHelp = "playlist_id | library_asset_id | pinterest_url | title | board | published_at | instance_id";

export default function AdminPublishingPage() {
  const [jobs, setJobs] = useState([]);
  const [playlists, setPlaylists] = useState([]);
  const [instances, setInstances] = useState([]);
  const [landingPages, setLandingPages] = useState([]);
  const [form, setForm] = useState(emptyForm);
  const [q, setQ] = useState("");
  const [sort, setSort] = useState({ key: "created_at", direction: "desc" });
  const [openCreate, setOpenCreate] = useState(false);
  const [openImport, setOpenImport] = useState(false);
  const [importText, setImportText] = useState("");
  const [selectedRow, setSelectedRow] = useState(null);
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);

  const rows = useMemo(() => {
    return jobs.flatMap((job) =>
      (job.outputs || []).map((output) => ({
        ...output,
        publish_job_id: job.publish_job_id,
        source_type: job.source_type,
        source_id: job.source_id,
        job_status: job.status,
        job_title: job.title,
        playlist_title: job.playlist_title,
        instance_title: job.instance_display_title || job.instance_name || "",
      }))
    );
  }, [jobs]);

  const sortedRows = useMemo(() => {
    const direction = sort.direction === "asc" ? 1 : -1;
    return [...rows].sort((a, b) => {
      const av = sortValue(a, sort.key);
      const bv = sortValue(b, sort.key);
      if (av < bv) return -1 * direction;
      if (av > bv) return 1 * direction;
      return Number(b.publish_output_id || 0) - Number(a.publish_output_id || 0);
    });
  }, [rows, sort]);

  const selectedPlaylist = useMemo(() => {
    const id = Number(form.playlist_id || 0);
    return playlists.find((item) => Number(item.playlist_id || 0) === id) || null;
  }, [form.playlist_id, playlists]);

  useEffect(() => {
    fetchPlaylists();
    fetchLandingPages();
    fetchJobs();
  }, []);

  useEffect(() => {
    if (!form.playlist_id) {
      setInstances([]);
      setForm((prev) => ({ ...prev, playlist_instance_id: "" }));
      return;
    }
    fetchInstances(form.playlist_id);
  }, [form.playlist_id]);

  async function fetchJobs(nextQ = q) {
    setLoading(true);
    setError("");
    try {
      const params = new URLSearchParams();
      if (nextQ.trim()) params.set("q", nextQ.trim());
      params.set("_", String(Date.now()));
      const res = await fetch(`${JOBS_URL}?${params.toString()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load publishing records");
      setJobs(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load publishing records");
    } finally {
      setLoading(false);
    }
  }

  async function fetchPlaylists() {
    try {
      const res = await fetch(`${PLAYLISTS_URL}?_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load playlists");
      setPlaylists(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load playlists");
    }
  }

  async function fetchLandingPages() {
    try {
      const res = await fetch(`${LANDING_PAGES_URL}?_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load landing pages");
      setLandingPages(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load landing pages");
    }
  }

  async function fetchInstances(playlistId) {
    try {
      const params = new URLSearchParams({
        playlist_id: String(playlistId),
        active: "1",
        _: String(Date.now()),
      });
      const res = await fetch(`${INSTANCES_URL}?${params.toString()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load instances");
      setInstances(data.items || []);
    } catch (err) {
      setInstances([]);
      setError(err?.message || "Failed to load instances");
    }
  }

  function updateForm(field, value) {
    setForm((prev) => ({ ...prev, [field]: value }));
  }

  function changeSort(key) {
    setSort((prev) => ({
      key,
      direction: prev.key === key && prev.direction === "asc" ? "desc" : "asc",
    }));
  }

  function openNewAsset() {
    setForm(emptyForm);
    setStatus("");
    setError("");
    setOpenCreate(true);
  }

  function openImportExisting() {
    setImportText("");
    setStatus("");
    setError("");
    setOpenImport(true);
  }

  function usePlaylistText() {
    if (!selectedPlaylist) return;
    setForm((prev) => ({
      ...prev,
      title: prev.title || selectedPlaylist.headline || selectedPlaylist.title || "",
    }));
  }

  async function createAsset(event) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setStatus("");
    try {
      if (form.asset_type !== "pinterest.before_after_pin") {
        throw new Error("That asset creator is listed, but not wired yet.");
      }

      const payload = {
        ...form,
        playlist_id: Number(form.playlist_id || 0),
        playlist_instance_id: form.playlist_instance_id ? Number(form.playlist_instance_id) : null,
        landing_page_id: form.landing_page_id ? Number(form.landing_page_id) : null,
        library_asset_id: form.library_asset_id ? Number(form.library_asset_id) : null,
      };
      const res = await fetch(SAVE_PINTEREST_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to create asset record");
      setStatus(`Asset record created: ${data.item?.tracking_code || ""}`);
      setOpenCreate(false);
      setForm(emptyForm);
      setQ("");
      await fetchJobs("");
    } catch (err) {
      setError(err?.message || "Failed to create asset record");
    } finally {
      setSaving(false);
    }
  }

  async function importExistingPins(event) {
    event.preventDefault();
    const entries = parsePinImport(importText);
    if (!entries.length) {
      setError("Paste at least one existing pin row.");
      return;
    }

    setSaving(true);
    setError("");
    setStatus("");
    try {
      let count = 0;
      for (const entry of entries) {
        const res = await fetch(SAVE_PINTEREST_URL, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            playlist_id: entry.playlist_id,
            playlist_instance_id: entry.playlist_instance_id || null,
            title: entry.title,
            board: entry.board,
            external_url: entry.external_url,
            library_asset_id: entry.library_asset_id || null,
            published_at: entry.published_at,
            notes: "Imported existing Pinterest pin.",
          }),
        });
        const data = await res.json();
        if (!res.ok || !data?.ok) {
          throw new Error(data?.error || `Failed importing playlist ${entry.playlist_id}`);
        }
        count += 1;
      }
      setStatus(`Imported ${count} existing Pinterest pin${count === 1 ? "" : "s"}.`);
      setOpenImport(false);
      setImportText("");
      await fetchJobs("");
    } catch (err) {
      setError(err?.message || "Failed to import existing pins");
    } finally {
      setSaving(false);
    }
  }

  async function markPublished(row) {
    setSaving(true);
    setError("");
    setStatus("");
    try {
      const externalUrl = window.prompt("Pinterest pin URL", row.external_url || "");
      if (externalUrl === null) return;
      const res = await fetch(MARK_PINTEREST_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          publish_job_id: row.publish_job_id,
          publish_output_id: row.publish_output_id,
          external_url: externalUrl,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to mark published");
      setSelectedRow(null);
      setStatus("Output marked published.");
      await fetchJobs();
    } catch (err) {
      setError(err?.message || "Failed to mark published");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="admin-publishing">
      <header className="pubdb-header">
        <div>
          <h1>Publishing Assets</h1>
          <p>Asset records, channel outputs, tracking URLs, and publish status.</p>
        </div>
        <button type="button" className="pubdb-command pubdb-command--primary" onClick={openNewAsset}>
          New Asset
        </button>
      </header>

      <div className="pubdb-toolbar">
        <label>
          Search
          <input value={q} onChange={(event) => setQ(event.target.value)} />
        </label>
        <button type="button" className="pubdb-command" onClick={() => fetchJobs(q)} disabled={loading}>
          Find
        </button>
        <button type="button" className="pubdb-command" onClick={() => fetchJobs("")} disabled={loading}>
          Refresh
        </button>
        <button type="button" className="pubdb-command" onClick={openImportExisting}>
          Import Existing Pins
        </button>
      </div>

      {error ? <div className="pubdb-status pubdb-status--error">{error}</div> : null}
      {status ? <div className="pubdb-status pubdb-status--ok">{status}</div> : null}

      <section className="pubdb-grid-wrap">
        <table className="pubdb-grid">
          <thead>
            <tr>
              <SortableTh label="Created" keyName="created_at" sort={sort} onSort={changeSort} />
              <SortableTh label="Output ID" keyName="publish_output_id" sort={sort} onSort={changeSort} />
              <SortableTh label="Channel" keyName="channel_key" sort={sort} onSort={changeSort} />
              <SortableTh label="Asset Type" keyName="output_type" sort={sort} onSort={changeSort} />
              <SortableTh label="Library ID" keyName="library_asset_id" sort={sort} onSort={changeSort} />
              <th>Permission</th>
              <SortableTh label="Status" keyName="status" sort={sort} onSort={changeSort} />
              <SortableTh label="Playlist" keyName="playlist_title" sort={sort} onSort={changeSort} />
              <SortableTh label="Instance" keyName="instance_title" sort={sort} onSort={changeSort} />
              <SortableTh label="Tracking" keyName="tracking_code" sort={sort} onSort={changeSort} />
              <SortableTh label="Destination" keyName="destination_url" sort={sort} onSort={changeSort} />
              <SortableTh label="Live URL" keyName="external_url" sort={sort} onSort={changeSort} />
              <SortableTh label="Published" keyName="published_at" sort={sort} onSort={changeSort} />
            </tr>
          </thead>
          <tbody>
            {sortedRows.map((row) => (
              <tr key={row.publish_output_id} onClick={() => setSelectedRow(row)}>
                <td>{row.created_at || "-"}</td>
                <td>{row.publish_output_id}</td>
                <td>{row.channel_key}</td>
                <td>{row.output_type}</td>
                <td>{row.library_asset_id || "-"}</td>
                <td><PermissionStatus {...permissionProps(row)} /></td>
                <td>{row.status}</td>
                <td>
                  #{row.source_id} {row.playlist_title || row.job_title}
                </td>
                <td>{row.instance_title || "-"}</td>
                <td>{row.tracking_code || "-"}</td>
                <td>{row.destination_url || "-"}</td>
                <td>{row.external_url ? "yes" : "-"}</td>
                <td>{row.published_at || "-"}</td>
              </tr>
            ))}
            {!loading && sortedRows.length === 0 ? (
              <tr>
                <td colSpan={13} className="pubdb-empty">
                  No publishing assets yet.
                </td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </section>

      {openCreate ? (
        <AssetDialog
          form={form}
          playlists={playlists}
          instances={instances}
          landingPages={landingPages}
          selectedPlaylist={selectedPlaylist}
          saving={saving}
          onClose={() => setOpenCreate(false)}
          onSubmit={createAsset}
          onUpdate={updateForm}
          onUsePlaylistText={usePlaylistText}
        />
      ) : null}

      {openImport ? (
        <ImportDialog
          value={importText}
          saving={saving}
          onChange={setImportText}
          onClose={() => setOpenImport(false)}
          onSubmit={importExistingPins}
        />
      ) : null}

      {selectedRow ? (
        <RowDialog
          row={selectedRow}
          saving={saving}
          onClose={() => setSelectedRow(null)}
          onMarkPublished={() => markPublished(selectedRow)}
        />
      ) : null}
    </div>
  );
}

function ImportDialog({ value, saving, onChange, onClose, onSubmit }) {
  return (
    <div className="pubdb-modal-backdrop" role="presentation">
      <div className="pubdb-modal" role="dialog" aria-modal="true" aria-label="Import existing Pinterest pins">
        <header className="pubdb-modal__header">
          <h2>Import Existing Pins</h2>
          <button type="button" onClick={onClose}>Close</button>
        </header>
        <form className="pubdb-import" onSubmit={onSubmit}>
          <p>Paste one pin per line. Use pipes, tabs, or commas.</p>
          <code>{importHelp}</code>
          <textarea
            rows={10}
            value={value}
            onChange={(event) => onChange(event.target.value)}
            placeholder={"37 | 642 | https://www.pinterest.com/pin/... | Front door makeover | Exterior Paint | 2026-06-08"}
          />
          <div className="pubdb-modal__actions">
            <button type="button" onClick={onClose}>Cancel</button>
            <button type="submit" className="pubdb-command--primary" disabled={saving}>
              {saving ? "Importing..." : "Import"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function SortableTh({ label, keyName, sort, onSort }) {
  const active = sort.key === keyName;
  const marker = active ? (sort.direction === "asc" ? "▲" : "▼") : "";
  return (
    <th>
      <button type="button" className={active ? "pubdb-sort pubdb-sort--active" : "pubdb-sort"} onClick={() => onSort(keyName)}>
        <span>{label}</span>
        <span aria-hidden="true">{marker}</span>
      </button>
    </th>
  );
}

function sortValue(row, key) {
  if (key === "publish_output_id" || key === "source_id" || key === "library_asset_id") {
    return Number(row[key] || 0);
  }
  if (key === "created_at" || key === "published_at") {
    return Date.parse(row[key] || "") || 0;
  }
  if (key === "playlist_title") {
    return String(row.playlist_title || row.job_title || "").toLowerCase();
  }
  if (key === "external_url") {
    return row.external_url ? 1 : 0;
  }
  return String(row[key] || "").toLowerCase();
}

function parsePinImport(text) {
  return String(text || "")
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => {
      const delimiter = line.includes("|") ? "|" : line.includes("\t") ? "\t" : ",";
      const parts = line.split(delimiter).map((part) => part.trim());
      const hasLibraryId = /^\d+$/.test(parts[1] || "") && /^https?:\/\//i.test(parts[2] || "");
      if (hasLibraryId) {
        return {
          playlist_id: Number(parts[0] || 0),
          library_asset_id: Number(parts[1] || 0),
          external_url: parts[2] || "",
          title: parts[3] || "",
          board: parts[4] || "",
          published_at: parts[5] || "",
          playlist_instance_id: parts[6] ? Number(parts[6]) : null,
        };
      }
      return {
        playlist_id: Number(parts[0] || 0),
        external_url: parts[1] || "",
        title: parts[2] || "",
        board: parts[3] || "",
        published_at: parts[4] || "",
        playlist_instance_id: parts[5] ? Number(parts[5]) : null,
      };
    })
    .filter((entry) => entry.playlist_id > 0 && entry.external_url);
}

function AssetDialog({
  form,
  playlists,
  instances,
  landingPages,
  selectedPlaylist,
  saving,
  onClose,
  onSubmit,
  onUpdate,
  onUsePlaylistText,
}) {
  return (
    <div className="pubdb-modal-backdrop" role="presentation">
      <div className="pubdb-modal" role="dialog" aria-modal="true" aria-label="Create publishing asset">
        <header className="pubdb-modal__header">
          <h2>Create New Asset</h2>
          <button type="button" onClick={onClose}>Close</button>
        </header>
        <form className="pubdb-form" onSubmit={onSubmit}>
          <label>
            Asset type
            <select value={form.asset_type} onChange={(event) => onUpdate("asset_type", event.target.value)}>
              {assetTypes.map((type) => (
                <option key={type.value} value={type.value} disabled={!type.enabled}>
                  {type.label}{type.enabled ? "" : " (not wired yet)"}
                </option>
              ))}
            </select>
          </label>

          <label>
            Playlist
            <select
              value={form.playlist_id}
              onChange={(event) => onUpdate("playlist_id", event.target.value)}
              required
            >
              <option value="">Choose playlist</option>
              {playlists.map((playlist) => (
                <option key={playlist.playlist_id} value={playlist.playlist_id}>
                  #{playlist.playlist_id} {playlist.title}
                </option>
              ))}
            </select>
          </label>

          <label>
            Instance
            <select
              value={form.playlist_instance_id}
              onChange={(event) => onUpdate("playlist_instance_id", event.target.value)}
              disabled={!instances.length}
            >
              <option value="">Default active instance</option>
              {instances.map((instance) => (
                <option key={instance.playlist_instance_id} value={instance.playlist_instance_id}>
                  #{instance.playlist_instance_id} {instance.display_title || instance.instance_name}
                </option>
              ))}
            </select>
          </label>

          <label>
            Landing page
            <select
              value={form.landing_page_id}
              onChange={(event) => onUpdate("landing_page_id", event.target.value)}
            >
              <option value="">None - fallback to player URL</option>
              {landingPages.map((page) => (
                <option key={page.id} value={page.id}>
                  #{page.id} /s/{page.slug} ({page.status})
                </option>
              ))}
            </select>
          </label>

          <div className="pubdb-form__inline pubdb-form__wide">
            <label>
              Title
              <input
                value={form.title}
                onChange={(event) => onUpdate("title", event.target.value)}
                placeholder="Leave blank to use playlist/share title"
              />
            </label>
            <button type="button" onClick={onUsePlaylistText} disabled={!selectedPlaylist}>
              Use Playlist Text
            </button>
          </div>

          <label className="pubdb-form__wide">
            Description
            <textarea
              rows={3}
              value={form.description}
              onChange={(event) => onUpdate("description", event.target.value)}
            />
          </label>

          <label>
            Board
            <input value={form.board} onChange={(event) => onUpdate("board", event.target.value)} />
          </label>

          <label>
            Existing live URL
            <input value={form.external_url} onChange={(event) => onUpdate("external_url", event.target.value)} />
          </label>

          <label>
            Library asset ID
            <input
              value={form.library_asset_id}
              onChange={(event) => onUpdate("library_asset_id", event.target.value)}
              inputMode="numeric"
              placeholder="Optional"
            />
          </label>

          <label>
            Published at
            <input
              type="datetime-local"
              value={form.published_at}
              onChange={(event) => onUpdate("published_at", event.target.value)}
            />
          </label>

          <label className="pubdb-form__wide">
            Notes
            <textarea rows={2} value={form.notes} onChange={(event) => onUpdate("notes", event.target.value)} />
          </label>

          <div className="pubdb-modal__actions pubdb-form__wide">
            <button type="button" onClick={onClose}>Cancel</button>
            <button type="submit" className="pubdb-command--primary" disabled={saving || !form.playlist_id}>
              {saving ? "Creating..." : "Create"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function RowDialog({ row, saving, onClose, onMarkPublished }) {
  return (
    <div className="pubdb-modal-backdrop" role="presentation">
      <div className="pubdb-modal pubdb-modal--small" role="dialog" aria-modal="true" aria-label="Publishing asset">
        <header className="pubdb-modal__header">
          <h2>Output #{row.publish_output_id}</h2>
          <button type="button" onClick={onClose}>Close</button>
        </header>
        <dl className="pubdb-details">
          <dt>Channel</dt><dd>{row.channel_key}</dd>
          <dt>Type</dt><dd>{row.output_type}</dd>
          <dt>Library ID</dt><dd>{row.library_asset_id || "-"}</dd>
          <dt>Permission</dt><dd><PermissionStatus {...permissionProps(row)} showLabel /></dd>
          <dt>Status</dt><dd>{row.status}</dd>
          <dt>Playlist</dt><dd>#{row.source_id} {row.playlist_title || row.job_title}</dd>
          <dt>Tracking code</dt><dd>{row.tracking_code || "-"}</dd>
          <dt>Tracking URL</dt>
          <dd>{row.tracking_url ? <a href={row.tracking_url} target="_blank" rel="noreferrer">{row.tracking_url}</a> : "-"}</dd>
          <dt>Destination URL</dt>
          <dd>{row.destination_url ? <a href={row.destination_url} target="_blank" rel="noreferrer">{row.destination_url}</a> : "-"}</dd>
          <dt>Live URL</dt>
          <dd>{row.external_url ? <a href={row.external_url} target="_blank" rel="noreferrer">{row.external_url}</a> : "-"}</dd>
          <dt>Published at</dt><dd>{row.published_at || "-"}</dd>
        </dl>
        <div className="pubdb-modal__actions">
          <button type="button" onClick={onClose}>Close</button>
          {!row.external_url ? (
            <button type="button" className="pubdb-command--primary" onClick={onMarkPublished} disabled={saving}>
              Mark Published
            </button>
          ) : null}
        </div>
      </div>
    </div>
  );
}

function permissionProps(item = {}) {
  return {
    status: item.photo_permission_status,
    photoLibraryId: item.permission_photo_library_id,
    clientId: item.client_id,
    clientName: item.client_name,
    clientEmail: item.client_email,
  };
}
