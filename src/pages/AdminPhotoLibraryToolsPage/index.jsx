import { useCallback, useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import { buildImageUrl, getImageRefreshEnabled, withImageRefresh } from "@helpers/assetImage";
import ModalDialog from "@components/ModalDialog";
import "./admin-photo-library-tools.css";

const AUDIT_URL = `${API_FOLDER}/v2/admin/photo-library/audit-integrity.php?library_fix_list=1`;
const RECONCILE_URL = `${API_FOLDER}/v2/admin/photo-library/reconcile-filesystem.php`;
const LIST_URL = `${API_FOLDER}/v2/admin/photo-library/list.php`;
const UPDATE_URL = `${API_FOLDER}/v2/admin/photo-library/update.php`;
const DELETE_INACTIVE_URL = `${API_FOLDER}/v2/admin/photo-library/delete-inactive.php`;
const BACKFILL_EXTERIORS_URL = `${API_FOLDER}/v2/admin/photo-library/backfill-exteriors.php`;
const RETIRE_MISSING_URL = `${API_FOLDER}/v2/admin/photo-library/retire-missing.php`;
const DUPLICATES_URL = `${API_FOLDER}/v2/admin/photo-library/duplicate-candidates.php`;
const MERGE_URL = `${API_FOLDER}/v2/admin/photo-library/merge.php`;
const USAGE_URL = `${API_FOLDER}/v2/admin/photo-library/usage.php`;
const SAVED_PALETTE_PROBLEMS_URL = `${API_FOLDER}/v2/admin/saved-palette-problems.php`;
const PLAYLIST_PROBLEMS_URL = `${API_FOLDER}/v2/admin/playlist-problems.php`;
const ARTICLE_PROBLEMS_URL = `${API_FOLDER}/v2/admin/article-problems.php`;

const MODES = [
  { value: "broken", label: "Broken In Use" },
  { value: "saved_palette_problems", label: "Saved Palette Problems" },
  { value: "playlist_problems", label: "Playlist Problems" },
  { value: "article_problems", label: "Article Problems" },
  { value: "missing", label: "Missing Library Rows" },
  { value: "retired", label: "Retired" },
  { value: "duplicates", label: "Duplicates" },
];

function openLibrarySearch(photoLibraryId = "", relPath = "", openPath = "") {
  if (openPath) {
    window.location.href = openPath;
    return;
  }
  const params = new URLSearchParams();
  if (photoLibraryId) {
    params.set("photo_library_ids", String(photoLibraryId));
    params.set("q", String(photoLibraryId));
  } else if (relPath) {
    params.set("q", relPath);
  }
  window.location.href = `/admin/photo-library${params.toString() ? `?${params.toString()}` : ""}`;
}

export default function AdminPhotoLibraryToolsPage() {
  const [mode, setMode] = useState("broken");
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [imageRefreshEnabled] = useState(() => getImageRefreshEnabled());
  const [thumbNonce, setThumbNonce] = useState(() => String(Date.now()));
  const [mergeForm, setMergeForm] = useState({ fromId: "", toId: "" });
  const [mergePreview, setMergePreview] = useState(null);
  const [mergeBusy, setMergeBusy] = useState(false);
  const [mergeMessage, setMergeMessage] = useState({ type: "", text: "" });
  const [usageModal, setUsageModal] = useState(null);
  const [previewUrl, setPreviewUrl] = useState("");
  const [retiringKey, setRetiringKey] = useState("");
  const [selectedMissingRows, setSelectedMissingRows] = useState({});

  async function loadBroken() {
    const res = await fetch(AUDIT_URL, { credentials: "include" });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load audit");
    return (data?.result?.items || []).map((item) => ({
      key: `broken:${item.photo_library_id}`,
      thumb: item.rel_path || "",
      name: item.title || item.rel_path || `Photo #${item.photo_library_id}`,
      flagged: `Broken in ${item.total_usage_count || 0} place(s)`,
      photo_library_id: item.photo_library_id,
      rel_path: item.rel_path || "",
    }));
  }

  async function loadSavedPaletteProblems() {
    const res = await fetch(SAVED_PALETTE_PROBLEMS_URL, { credentials: "include" });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load saved palette problems");
    return (data?.result?.items || []).map((item) => ({
      key: item.key || `saved-problem:${item.photo_id}`,
      thumb: item.thumb || "",
      name: item.name || `Saved Palette #${item.saved_palette_id || "?"}`,
      flagged: item.flagged || "Invalid saved palette photo link",
      photo_library_id: item.photo_library_id,
      rel_path: item.rel_path || "",
      open_path: item.open_path || "",
      photo_type: item.photo_type || "",
    }));
  }

  async function loadPlaylistProblems() {
    const res = await fetch(PLAYLIST_PROBLEMS_URL, { credentials: "include" });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load playlist problems");
    return (data?.result?.items || []).map((item) => ({
      key: item.key || `playlist-problem:${item.photo_library_id || item.name || Math.random()}`,
      thumb: item.thumb || "",
      name: item.name || "Playlist problem",
      flagged: item.flagged || "Invalid playlist photo link",
      photo_library_id: item.photo_library_id,
      rel_path: item.rel_path || "",
      open_path: item.open_path || "",
    }));
  }

  async function loadArticleProblems() {
    const res = await fetch(ARTICLE_PROBLEMS_URL, { credentials: "include" });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load article problems");
    return (data?.result?.items || []).map((item) => ({
      key: item.key || `article-problem:${item.photo_library_id || item.name || Math.random()}`,
      thumb: item.thumb || "",
      name: item.name || "Article problem",
      flagged: item.flagged || "Invalid article photo link",
      photo_library_id: item.photo_library_id,
      rel_path: item.rel_path || "",
      open_path: item.open_path || "",
    }));
  }

  async function loadMissing() {
    const res = await fetch(`${RECONCILE_URL}?apply=0`, { credentials: "include" });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load filesystem audit");
    return (data?.result?.items || []).map((item, index) => ({
      key: `missing:${index}:${item.rel_path}`,
      thumb: item.rel_path || "",
      name: item.title || item.rel_path || "Untitled",
      flagged: `Missing library row (${item.source_type || "photo_base"})`,
      photo_library_id: null,
      rel_path: item.rel_path || "",
      source_type: item.source_type || "photo_base",
    }));
  }

  async function loadRetired() {
    const params = new URLSearchParams();
    params.set("inactive_only", "1");
    params.set("include_inactive", "1");
    params.set("limit", "500");
    const res = await fetch(`${LIST_URL}?${params.toString()}`, { credentials: "include" });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load retired rows");
    return (data?.items || []).map((item) => ({
      key: `retired:${item.photo_library_id}`,
      thumb: item.raw_rel_path || item.rel_path || item.image_url || "",
      name: item.title || item.filename || `Photo #${item.photo_library_id}`,
      flagged: "Retired",
      photo_library_id: item.photo_library_id,
      rel_path: item.raw_rel_path || item.rel_path || "",
    }));
  }

  async function loadDuplicates() {
    const res = await fetch(DUPLICATES_URL, { credentials: "include" });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load duplicate candidates");
    return Array.isArray(data?.result?.items)
      ? data.result.items
          .filter((group) => group && typeof group === "object")
          .map((group) => ({
            match_type: group.match_type || "exact",
            reason: group.reason || "",
            group_key: group.group_key || "",
            rel_path: group.rel_path || "",
            duplicate_count: Number(group.duplicate_count || 0),
            items: Array.isArray(group.items) ? group.items.filter((item) => item && typeof item === "object") : [],
          }))
      : [];
  }

  const loadMode = useCallback(async (nextMode = mode) => {
    setLoading(true);
    setError("");
    setNotice("");
    setThumbNonce(String(Date.now()));
    try {
      const nextRows =
        nextMode === "saved_palette_problems"
          ? await loadSavedPaletteProblems()
          : nextMode === "playlist_problems"
            ? await loadPlaylistProblems()
            : nextMode === "article_problems"
              ? await loadArticleProblems()
          : nextMode === "missing"
          ? await loadMissing()
          : nextMode === "retired"
            ? await loadRetired()
            : nextMode === "duplicates"
              ? await loadDuplicates()
              : await loadBroken();
      setRows(nextRows);
      if (nextMode === "missing") {
        setSelectedMissingRows((prev) => {
          const next = {};
          nextRows.forEach((row) => {
            if (prev[row.key]) next[row.key] = true;
          });
          return next;
        });
      }
    } catch (err) {
      setError(err?.message || "Failed to load tools");
      setRows([]);
    } finally {
      setLoading(false);
    }
  }, [mode]);

  useEffect(() => {
    void loadMode(mode);
  }, [mode, loadMode]);

  async function handleCreateMissingRows() {
    const selectedRows = rows.filter((row) => selectedMissingRows[row.key]);
    if (!selectedRows.length) return;
    if (!window.confirm(`Create Photo Library rows for ${selectedRows.length} selected file(s)?`)) return;
    setError("");
    setNotice("");
    setRetiringKey("__bulk_create__");
    let createdCount = 0;
    try {
      for (const row of selectedRows) {
        setRetiringKey(row.key);
        const params = new URLSearchParams();
        params.set("apply", "1");
        params.set("rel_path", row.rel_path);
        const res = await fetch(`${RECONCILE_URL}?${params.toString()}`, { credentials: "include" });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data?.ok) throw new Error(data?.error || `Create failed for ${row.rel_path}`);
        createdCount += Number(data?.result?.created || 0);
      }
      setSelectedMissingRows({});
      setNotice(`Created ${createdCount} missing library row(s).`);
      await loadMode("missing");
    } catch (err) {
      setError(err?.message || "Create missing rows failed");
    } finally {
      setRetiringKey("");
    }
  }

  async function handleDeleteRetired() {
    if (!window.confirm("Delete all retired photos that are no longer in use?")) return;
    setError("");
    setNotice("");
    try {
      const res = await fetch(DELETE_INACTIVE_URL, { method: "POST", credentials: "include" });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Delete retired failed");
      setNotice(`Deleted ${Number(data?.result?.deleted || 0)} retired row(s).`);
      await loadMode("retired");
    } catch (err) {
      setError(err?.message || "Delete retired failed");
    }
  }

  async function handleRestoreRow(row) {
    if (!row?.photo_library_id) return;
    setError("");
    setNotice("");
    try {
      const res = await fetch(UPDATE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          photo_library_id: Number(row.photo_library_id),
          is_inactive: false,
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Restore failed");
      setNotice(`Restored photo #${row.photo_library_id}.`);
      await loadMode("retired");
    } catch (err) {
      setError(err?.message || "Restore failed");
    }
  }

  async function handleSyncBasePhotos() {
    if (!window.confirm("Scan exterior base photos and add missing Photo Library rows?")) return;
    setError("");
    setNotice("");
    try {
      const res = await fetch(BACKFILL_EXTERIORS_URL, { method: "POST", credentials: "include" });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Sync base photos failed");
      setNotice(`Added ${Number(data?.added || 0)} base photo row(s). Skipped ${Number(data?.skipped || 0)}.`);
      await loadMode(mode);
    } catch (err) {
      setError(err?.message || "Sync base photos failed");
    }
  }

  async function handleSaveMissingRetires() {
    const selectedRows = rows.filter((row) => selectedMissingRows[row.key]);
    if (!selectedRows.length) return;
    if (!window.confirm(`Retire ${selectedRows.length} selected missing file(s)?`)) return;
    setError("");
    setNotice("");
    setRetiringKey("__bulk__");
    let retiredCount = 0;
    try {
      for (const row of selectedRows) {
        setRetiringKey(row.key);
        const res = await fetch(RETIRE_MISSING_URL, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            rel_path: row.rel_path,
            source_type: row.source_type || null,
            title: row.name || null,
          }),
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data?.ok) throw new Error(data?.error || `Retire failed for ${row.rel_path}`);
        retiredCount += 1;
      }
      setSelectedMissingRows({});
      setNotice(`Retired ${retiredCount} missing row(s).`);
      await loadMode("missing");
    } catch (err) {
      setError(err?.message || "Retire missing rows failed");
    } finally {
      setRetiringKey("");
    }
  }

  async function handleMergePreview(fromId, toId) {
    setError("");
    setNotice("");
    setMergePreview(null);
    setMergeMessage({ type: "", text: "" });
    setMergeBusy(true);
    try {
      const res = await fetch(MERGE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          from_photo_library_id: Number(fromId),
          to_photo_library_id: Number(toId),
          apply: false,
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Merge preview failed");
      setMergePreview(data.result || null);
      setMergeMessage({
        type: "ok",
        text: `Preview ready: move #${fromId} into #${toId}.`,
      });
    } catch (err) {
      setMergeMessage({ type: "error", text: err?.message || "Merge preview failed" });
      setError(err?.message || "Merge preview failed");
    } finally {
      setMergeBusy(false);
    }
  }

  async function handleMergeApply() {
    if (!mergePreview?.from_photo_library_id || !mergePreview?.to_photo_library_id) return;
    if (!window.confirm(`Consolidate #${mergePreview.from_photo_library_id} into #${mergePreview.to_photo_library_id}?`)) return;
    setError("");
    setNotice("");
    setMergeMessage({ type: "", text: "" });
    setMergeBusy(true);
    try {
      const res = await fetch(MERGE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          from_photo_library_id: Number(mergePreview.from_photo_library_id),
          to_photo_library_id: Number(mergePreview.to_photo_library_id),
          apply: true,
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Consolidate failed");
      setNotice(`Merged #${mergePreview.from_photo_library_id} into #${mergePreview.to_photo_library_id}.`);
      setMergeMessage({
        type: "ok",
        text: `Consolidated #${mergePreview.from_photo_library_id} into #${mergePreview.to_photo_library_id}. Source row retired.`,
      });
      setMergePreview(null);
      setMergeForm({ fromId: "", toId: "" });
      await loadMode(mode);
    } catch (err) {
      setMergeMessage({ type: "error", text: err?.message || "Consolidate failed" });
      setError(err?.message || "Consolidate failed");
    } finally {
      setMergeBusy(false);
    }
  }

  async function handleInvestigate(row) {
    setError("");
    try {
      const usageRes = await fetch(`${USAGE_URL}?photo_library_id=${encodeURIComponent(row.photo_library_id)}`, {
        credentials: "include",
      });
      const usageData = await usageRes.json().catch(() => ({}));
      if (!usageRes.ok || !usageData?.ok) {
        throw new Error(usageData?.error || "Failed to inspect photo usage");
      }
      setUsageModal({
        item: row,
        usages: Array.isArray(usageData.usages) ? usageData.usages : [],
      });
    } catch (err) {
      setError(err?.message || "Failed to inspect row");
    }
  }

  const modeLabel = useMemo(
    () => MODES.find((item) => item.value === mode)?.label || "Broken In Use",
    [mode]
  );

  const duplicateRows = useMemo(
    () => (mode === "duplicates" ? rows.filter((group) => Array.isArray(group?.items) && group.items.length > 0) : []),
    [mode, rows]
  );
  const pendingMissingRetireCount = useMemo(
    () => Object.values(selectedMissingRows).filter(Boolean).length,
    [selectedMissingRows]
  );

  const hasMergeIds = Boolean(mergeForm.fromId && mergeForm.toId);
  const canApplyMerge = Boolean(
    mergePreview &&
    String(mergePreview.from_photo_library_id) === String(mergeForm.fromId) &&
    String(mergePreview.to_photo_library_id) === String(mergeForm.toId)
  );

  function buildToolImageUrl(url, updatedAt = null) {
    const base = buildImageUrl(url, updatedAt, imageRefreshEnabled);
    if (!base) return "";
    const sep = base.includes("?") ? "&" : "?";
    return `${base}${sep}tools=${thumbNonce}`;
  }

  return (
    <div className="admin-photo-library-tools">
      <div className="admin-photo-library-tools__head">
        <div>
          <h1>Photo Library Tools</h1>
          <p>Audit and cleanup. Minimal list only.</p>
        </div>
        <div className="admin-photo-library-tools__head-actions">
          <a className="admin-photo-library-tools__btn" href="/admin/photo-library">Back To Library</a>
        </div>
      </div>

      <div className="admin-photo-library-tools__toolbar">
        <div className="admin-photo-library-tools__modebar">
          {MODES.map((item) => (
            <button
              key={item.value}
              type="button"
              className={`admin-photo-library-tools__mode${mode === item.value ? " is-active" : ""}`}
              onClick={() => {
                if (mode === item.value) {
                  void loadMode(item.value);
                  return;
                }
                setMode(item.value);
              }}
            >
              {item.label}
            </button>
          ))}
        </div>
        <div className="admin-photo-library-tools__actions">
          <button type="button" className="admin-photo-library-tools__btn" onClick={() => loadMode(mode)}>
            Refresh
          </button>
          {mode === "missing" ? (
            <>
              <button
                type="button"
                className="admin-photo-library-tools__btn admin-photo-library-tools__btn--primary"
                onClick={handleCreateMissingRows}
                disabled={!pendingMissingRetireCount || !!retiringKey}
              >
                {retiringKey === "__bulk_create__" ? "Creating Rows..." : `Create Rows${pendingMissingRetireCount ? ` (${pendingMissingRetireCount})` : ""}`}
              </button>
              <button
                type="button"
                className="admin-photo-library-tools__btn"
                onClick={handleSaveMissingRetires}
                disabled={!pendingMissingRetireCount || !!retiringKey}
              >
                {retiringKey === "__bulk__" ? "Saving Retires..." : `Save Retires${pendingMissingRetireCount ? ` (${pendingMissingRetireCount})` : ""}`}
              </button>
            </>
          ) : null}
          {mode === "retired" ? (
            <button type="button" className="admin-photo-library-tools__btn admin-photo-library-tools__btn--danger" onClick={handleDeleteRetired}>
              Delete Retired
            </button>
          ) : null}
          <button type="button" className="admin-photo-library-tools__btn" onClick={handleSyncBasePhotos}>
            Sync Base Photos
          </button>
        </div>
      </div>

      {error ? <div className="admin-photo-library-tools__message admin-photo-library-tools__message--error">{error}</div> : null}
      {notice ? <div className="admin-photo-library-tools__message admin-photo-library-tools__message--ok">{notice}</div> : null}

      <div className="admin-photo-library-tools__summary">
        <div>{modeLabel}</div>
        <div>{mode === "duplicates" ? duplicateRows.length : rows.length} row(s)</div>
      </div>

      <div className="admin-photo-library-tools__merge-box">
        <div className="admin-photo-library-tools__merge-head">Consolidate Photos</div>
        <div className="admin-photo-library-tools__merge-form">
          <input
            type="number"
            value={mergeForm.fromId}
            onChange={(e) => {
              setMergePreview(null);
              setMergeMessage({ type: "", text: "" });
              setMergeForm((prev) => ({ ...prev, fromId: e.target.value }));
            }}
            placeholder="Duplicate ID"
          />
          <span className="admin-photo-library-tools__merge-arrow">→</span>
          <input
            type="number"
            value={mergeForm.toId}
            onChange={(e) => {
              setMergePreview(null);
              setMergeMessage({ type: "", text: "" });
              setMergeForm((prev) => ({ ...prev, toId: e.target.value }));
            }}
            placeholder="Keep ID"
          />
          <button
            type="button"
            className="admin-photo-library-tools__btn admin-photo-library-tools__btn--primary"
            onClick={() => handleMergePreview(mergeForm.fromId, mergeForm.toId)}
            disabled={!hasMergeIds || mergeBusy}
          >
            {mergeBusy && !canApplyMerge ? "Previewing..." : "Preview"}
          </button>
          <button
            type="button"
            className="admin-photo-library-tools__btn"
            onClick={handleMergeApply}
            disabled={!canApplyMerge || mergeBusy}
          >
            {mergeBusy && canApplyMerge ? "Consolidating..." : "Consolidate"}
          </button>
        </div>
        {mergeMessage.text ? (
          <div
            className={`admin-photo-library-tools__merge-status admin-photo-library-tools__merge-status--${mergeMessage.type || "ok"}`}
          >
            {mergeMessage.text}
          </div>
        ) : null}
        {mergePreview ? (
          <div className="admin-photo-library-tools__merge-preview">
            <div>
              <strong>From #{mergePreview.from_photo_library_id}</strong>
              <div>{mergePreview.from?.title || "(untitled)"}</div>
              <div className="admin-photo-library-tools__merge-meta">{mergePreview.from?.rel_path || ""}</div>
              <div className="admin-photo-library-tools__merge-meta">Uses: {mergePreview.from?.usage_count || 0}</div>
            </div>
            <div>
              <strong>Keep #{mergePreview.to_photo_library_id}</strong>
              <div>{mergePreview.to?.title || "(untitled)"}</div>
              <div className="admin-photo-library-tools__merge-meta">{mergePreview.to?.rel_path || ""}</div>
              <div className="admin-photo-library-tools__merge-meta">Uses: {mergePreview.to?.usage_count || 0}</div>
            </div>
          </div>
        ) : null}
      </div>

      <div className="admin-photo-library-tools__table-wrap">
        <table className="admin-photo-library-tools__table">
          <thead>
            <tr>
              <th>{mode === "missing" ? "Select" : ""}</th>
              <th>Thumb</th>
              <th>Name</th>
              <th>Flagged</th>
              <th>ID</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr>
                <td colSpan={6} className="admin-photo-library-tools__empty">Loading…</td>
              </tr>
            ) : null}
            {!loading && ((mode === "duplicates" ? duplicateRows.length : rows.length) === 0) ? (
              <tr>
                <td colSpan={6} className="admin-photo-library-tools__empty">No rows.</td>
              </tr>
            ) : null}
            {!loading && mode === "duplicates" && duplicateRows.map((group) => ([
              <tr key={`group:${group.group_key || group.rel_path || group.reason}`} className="admin-photo-library-tools__group-row">
                <td colSpan={6} className="admin-photo-library-tools__group-cell">
                  {group.reason || "Duplicate candidate"} ({group.duplicate_count} rows)
                </td>
              </tr>,
              ...group.items.map((row) => (
                <tr key={`${group.rel_path}:${row.photo_library_id}`}>
                  <td />
                  <td className="admin-photo-library-tools__thumb-cell">
                    {row.rel_path ? (
                      <img
                        className="admin-photo-library-tools__thumb"
                        src={buildToolImageUrl(row.rel_path, row.updated_at || null)}
                        alt=""
                        onClick={() => setPreviewUrl(buildToolImageUrl(row.rel_path, row.updated_at || null))}
                      />
                    ) : (
                      <div className="admin-photo-library-tools__thumb admin-photo-library-tools__thumb--empty" />
                    )}
                  </td>
                  <td className="admin-photo-library-tools__name">
                    <div>{row.title || row.rel_path || `Photo #${row.photo_library_id}`}</div>
                    <div className="admin-photo-library-tools__sub">
                      {row.rel_path || group.group_key || ""}
                    </div>
                  </td>
                  <td className="admin-photo-library-tools__flag">
                    {`Uses: ${row.usage_count || 0}`}
                  </td>
                  <td className="admin-photo-library-tools__id">#{row.photo_library_id}</td>
                  <td className="admin-photo-library-tools__action">
                    <button
                      type="button"
                      className="admin-photo-library-tools__icon-btn"
                      title="Investigate usage"
                      aria-label={`Investigate usage for photo ${row.photo_library_id}`}
                      onClick={() => handleInvestigate(row)}
                    >
                      🔎
                    </button>
                    <button
                      type="button"
                      className="admin-photo-library-tools__link"
                      onClick={() => {
                        setMergePreview(null);
                        setMergeForm((prev) => ({ ...prev, fromId: String(row.photo_library_id) }));
                      }}
                    >
                      Merge This
                    </button>
                    <button
                      type="button"
                      className="admin-photo-library-tools__link"
                      onClick={() => {
                        setMergePreview(null);
                        setMergeForm((prev) => ({ ...prev, toId: String(row.photo_library_id) }));
                      }}
                    >
                      Keep This
                    </button>
                    <button
                      type="button"
                      className="admin-photo-library-tools__link"
                      onClick={() => openLibrarySearch(row.photo_library_id, row.rel_path)}
                    >
                      Open
                    </button>
                  </td>
                </tr>
              )),
            ]))}
            {!loading && rows.map((row) => (
              mode === "duplicates" ? null : (
              <tr key={row.key}>
                <td className="admin-photo-library-tools__select-cell">
                  {mode === "missing" ? (
                    <input
                      type="checkbox"
                      checked={!!selectedMissingRows[row.key]}
                      disabled={!!retiringKey}
                      onChange={(e) => {
                        setSelectedMissingRows((prev) => {
                          const next = { ...prev };
                          if (e.target.checked) {
                            next[row.key] = true;
                          } else {
                            delete next[row.key];
                          }
                          return next;
                        });
                      }}
                    />
                  ) : null}
                </td>
                <td className="admin-photo-library-tools__thumb-cell">
                  {row.thumb ? (
                    <img
                      className="admin-photo-library-tools__thumb"
                      src={buildToolImageUrl(row.thumb, null)}
                      alt=""
                      onClick={() => setPreviewUrl(buildToolImageUrl(row.thumb, null))}
                    />
                  ) : (
                    <div className="admin-photo-library-tools__thumb admin-photo-library-tools__thumb--empty" />
                  )}
                </td>
                <td className="admin-photo-library-tools__name">
                  <div>{row.name}</div>
                  {row.rel_path ? (
                    <div className="admin-photo-library-tools__sub">{row.rel_path}</div>
                  ) : null}
                </td>
                <td className="admin-photo-library-tools__flag">{row.flagged}</td>
                <td className="admin-photo-library-tools__id">{row.photo_library_id ? `#${row.photo_library_id}` : ""}</td>
                <td className="admin-photo-library-tools__action">
                  {row.photo_library_id ? (
                    <button
                      type="button"
                      className="admin-photo-library-tools__icon-btn"
                      title="Investigate usage"
                      aria-label={`Investigate usage for photo ${row.photo_library_id}`}
                      onClick={() => handleInvestigate(row)}
                    >
                      🔎
                    </button>
                  ) : null}
                  {mode === "retired" && row.photo_library_id ? (
                    <button
                      type="button"
                      className="admin-photo-library-tools__link"
                      onClick={() => handleRestoreRow(row)}
                    >
                      Restore
                    </button>
                  ) : null}
                  <button
                    type="button"
                    className="admin-photo-library-tools__link"
                    onClick={() => openLibrarySearch(row.photo_library_id, row.rel_path, row.open_path)}
                  >
                    Open
                  </button>
                </td>
              </tr>
              )
            ))}
          </tbody>
        </table>
      </div>

      {previewUrl && (
        <div
          className="admin-photo-library-tools__preview"
          role="button"
          tabIndex={0}
          onClick={() => setPreviewUrl("")}
          onKeyDown={(e) => {
            if (e.key === "Escape") setPreviewUrl("");
          }}
        >
          <img src={withImageRefresh(previewUrl, imageRefreshEnabled)} alt="" />
        </div>
      )}

      <ModalDialog
        open={Boolean(usageModal)}
        title={`Photo #${usageModal?.item?.photo_library_id || "?"}`}
        subtitle={usageModal?.item?.title || ""}
        onClose={() => setUsageModal(null)}
      >
        {usageModal?.usages?.length ? (
          <ul className="admin-photo-library-tools__usage-list">
            {usageModal.usages.map((usage, index) => (
              <li key={`${usage.usage_type || "usage"}-${usage.ref_id || index}-${index}`}>
                <div>{usage?.label || "Unknown usage"}</div>
                {usage?.detail ? (
                  <div className="admin-photo-library-tools__usage-detail">{usage.detail}</div>
                ) : null}
              </li>
            ))}
          </ul>
        ) : (
          <div className="admin-photo-library-tools__usage-empty">Not currently used anywhere.</div>
        )}
      </ModalDialog>
    </div>
  );
}
