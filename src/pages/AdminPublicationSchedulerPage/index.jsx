import { useEffect, useMemo, useRef, useState } from "react";
import { Clipboard } from "lucide-react";
import { API_FOLDER } from "@helpers/config";
import Toast from "@components/Toast";
import "./admin-publication-scheduler.css";

const API_BASE = `${API_FOLDER}/v2/admin/publication-scheduler`;
const SITE_ORIGIN = "https://colorfix.terrymarr.com";

export default function AdminPublicationSchedulerPage() {
  const [rows, setRows] = useState([]);
  const [selected, setSelected] = useState({});
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [toast, setToast] = useState(null);
  const [timeEditor, setTimeEditor] = useState(null);
  const [receiptRow, setReceiptRow] = useState(null);
  const [manualDetailsRow, setManualDetailsRow] = useState(null);
  const [timingsOpen, setTimingsOpen] = useState(false);
  const [timingChannels, setTimingChannels] = useState([]);
  const [timingEditor, setTimingEditor] = useState({
    publishing_channel_id: "",
    environment: "test",
    settings: {},
  });
  const topScrollRef = useRef(null);
  const tableWrapRef = useRef(null);
  const tableRef = useRef(null);
  const [tableScrollWidth, setTableScrollWidth] = useState(1800);
  const [filters, setFilters] = useState({
    environment: "all",
    schedule_status: "all",
    platform: "all",
    creator_job: "",
  });

  const selectedRows = useMemo(
    () => rows.filter((row) => selected[rowKey(row)]),
    [rows, selected]
  );

  const allVisibleSelected = rows.length > 0 && rows.every((row) => Boolean(selected[rowKey(row)]));

  useEffect(() => {
    loadQueue();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    function updateTableScrollWidth() {
      setTableScrollWidth(tableRef.current?.scrollWidth || 1800);
    }
    updateTableScrollWidth();
    window.addEventListener("resize", updateTableScrollWidth);
    return () => window.removeEventListener("resize", updateTableScrollWidth);
  }, [rows]);

  function showToast(type, text) {
    setToast({ type, text, id: Date.now() });
  }

  function updateFilter(key, value) {
    setFilters((current) => ({ ...current, [key]: value }));
  }

  function toggleSelected(rowId, checked) {
    setSelected((current) => ({ ...current, [rowId]: checked }));
  }

  function setVisibleSelection(checked, predicate = () => true) {
    setSelected((current) => {
      const next = { ...current };
      rows.forEach((row) => {
        if (!predicate(row)) return;
        const id = rowKey(row);
        if (checked) {
          next[id] = true;
        } else {
          delete next[id];
        }
      });
      return next;
    });
  }

  function isDeletableQueueRow(row) {
    return ["unscheduled", "waiting", "error", "cancelled", "skipped_duplicate"].includes(row.schedule_status || "unscheduled");
  }

  function selectVisibleDeletable() {
    setVisibleSelection(true, isDeletableQueueRow);
  }

  function syncHorizontalScroll(sourceRef, targetRef) {
    const source = sourceRef.current;
    const target = targetRef.current;
    if (!source || !target || target.scrollLeft === source.scrollLeft) return;
    target.scrollLeft = source.scrollLeft;
  }

  function openTimeEditor(row) {
    setTimeEditor({
      row,
      scheduled_at: toLocalInputValue(row.scheduled_at) || "",
      timezone: row.timezone || "America/Los_Angeles",
    });
  }

  async function loadQueue(nextFilters = filters, options = {}) {
    const clearAlerts = options.clearAlerts !== false;
    setLoading(true);
    if (clearAlerts) {
      setError("");
    }
    try {
      const params = new URLSearchParams();
      Object.entries(nextFilters).forEach(([key, value]) => {
        if (value !== "" && value !== "all") params.set(key, value);
      });
      const res = await fetch(`${API_BASE}/list.php?${params.toString()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load queue");
      setRows(Array.isArray(data.rows) ? data.rows : []);
    } catch (err) {
      setError(err?.message || "Failed to load queue");
    } finally {
      setLoading(false);
    }
  }

  async function postAction(path, payload) {
    setLoading(true);
    setError("");
    setMessage("");
    try {
      const res = await fetch(`${API_BASE}/${path}.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "include",
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Scheduler action failed");
      let nextMessage = "";
      let nextError = "";
      if (path === "run-due") {
        const claimed = data?.item?.claimed_count ?? 0;
        const results = Array.isArray(data?.item?.results) ? data.item.results : [];
        const failed = results.filter((result) => result?.status === "error");
        if (failed.length) {
          const detail = failureDetail(failed[0]);
          nextError = `Ran due queue. Claimed ${claimed}. Failed ${failed.length}${detail ? `: ${detail}` : "."}`;
        } else {
          nextMessage = `Ran due queue. Claimed ${claimed}.`;
        }
      } else if (path === "publish-now") {
        const item = data?.item || {};
        if (item.status === "published") {
          nextMessage = `Ran selected package #${item.package_id || ""}.`;
        } else if (item.status === "error") {
          const detail = failureDetail(item);
          nextError = `Selected package ${item.status}${detail ? `: ${detail}` : "."}`;
        } else {
          nextMessage = `Selected package ${item.status || "updated"}; see row error if it failed.`;
        }
      } else if (path === "cancel") {
        nextMessage = "Removed from scheduler queue. Package the job again if you want to add it back.";
      } else {
        nextMessage = "Scheduler updated.";
      }
      await loadQueue(filters, { clearAlerts: false });
      if (nextError) {
        setError(nextError);
        showToast("error", nextError);
      } else {
        setMessage(nextMessage);
        showToast("success", nextMessage);
      }
      return data;
    } catch (err) {
      const nextError = err?.message || "Scheduler action failed";
      setError(nextError);
      showToast("error", nextError);
      return null;
    } finally {
      setLoading(false);
    }
  }

  async function loadChannelTimings() {
    setLoading(true);
    setError("");
    try {
      const res = await fetch(`${API_BASE}/channel-timings.php`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load channel timings");
      const channels = Array.isArray(data.channels) ? data.channels : [];
      setTimingChannels(channels);
      setTimingEditor((current) => {
        const channelId = current.publishing_channel_id || channels[0]?.publishing_channel_id || "";
        const environment = current.environment || "test";
        const channel = channels.find((item) => String(item.publishing_channel_id) === String(channelId)) || channels[0];
        return {
          publishing_channel_id: channel?.publishing_channel_id || "",
          environment,
          settings: channel?.environments?.[environment] || {},
        };
      });
    } catch (err) {
      setError(err?.message || "Failed to load channel timings");
    } finally {
      setLoading(false);
    }
  }

  async function saveChannelTiming() {
    if (!timingEditor.publishing_channel_id) {
      setError("Choose a publishing channel.");
      return;
    }
    setLoading(true);
    setError("");
    setMessage("");
    try {
      const res = await fetch(`${API_BASE}/channel-timings.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "include",
        body: JSON.stringify(timingEditor),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to save channel timing");
      setMessage("Channel timing saved.");
      await loadChannelTimings();
      setTimingsOpen(false);
    } catch (err) {
      setError(err?.message || "Failed to save channel timing");
    } finally {
      setLoading(false);
    }
  }

  function openChannelTimings() {
    setTimingsOpen(true);
    loadChannelTimings();
  }

  function setTimingChannel(channelId, environment = timingEditor.environment) {
    const channel = timingChannels.find((item) => String(item.publishing_channel_id) === String(channelId));
    setTimingEditor({
      publishing_channel_id: channelId,
      environment,
      settings: channel?.environments?.[environment] || {},
    });
  }

  function patchTimingSettings(patch) {
    setTimingEditor((current) => ({
      ...current,
      settings: {
        ...current.settings,
        ...patch,
      },
    }));
  }

  async function scheduleRow(row, override = {}) {
    const rowId = row.package_id || row.publish_output_id;
    return postAction(row.queue_item_id ? "reschedule" : "schedule", {
      package_id: rowId,
      package_batch_id: row.package_batch_id,
      queue_item_id: row.queue_item_id,
      scheduled_at: override.scheduled_at || "",
      timezone: override.timezone || row.timezone || "America/Los_Angeles",
      priority: 100,
    });
  }

  async function saveTimeEditor() {
    if (!timeEditor?.row) return;
    const data = await scheduleRow(timeEditor.row, {
      scheduled_at: timeEditor.scheduled_at,
      timezone: timeEditor.timezone,
    });
    if (data?.ok) {
      setTimeEditor(null);
      if (isDueNow(timeEditor.scheduled_at)) {
        await postAction("run-due", { limit: 5 });
      }
    }
  }

  async function runNow(payload) {
    await postAction("publish-now", payload);
  }

  async function copyManualField(value, label = "Field") {
    const text = String(value || "");
    if (!text) return;
    try {
      if (!navigator.clipboard?.writeText) {
        window.prompt(`Copy ${label}:`, text);
        return;
      }
      await navigator.clipboard.writeText(text);
      showToast("success", `${label} copied.`);
    } catch {
      window.prompt(`Copy ${label}:`, text);
    }
  }

  async function markManualPublished(row, payload) {
    setLoading(true);
    setError("");
    setMessage("");
    try {
      const res = await fetch(`${API_BASE}/manual-published.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "include",
        body: JSON.stringify({
          package_id: row.package_id || row.publish_output_id,
          package_batch_id: row.package_batch_id,
          queue_item_id: row.queue_item_id,
          ...payload,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to mark package published");
      const item = data.item || {};
      const nextMessage = `Package #${item.package_id || row.package_id || row.publish_output_id} marked published manually.`;
      setMessage(nextMessage);
      showToast("success", nextMessage);
      setManualDetailsRow((current) => current ? {
        ...current,
        schedule_status: "published",
        publication_status: item.status || current.publication_status,
        external_id: item.external_id || current.external_id,
        external_url: item.external_url || current.external_url,
        published_at: item.published_at || current.published_at,
      } : current);
      await loadQueue(filters, { clearAlerts: false });
      return data;
    } catch (err) {
      const nextError = err?.message || "Failed to mark package published";
      setError(nextError);
      showToast("error", nextError);
      return null;
    } finally {
      setLoading(false);
    }
  }

  async function retryReceiptRow(row) {
    if (!row) return;
    const data = await postAction("publish-now", {
      package_id: row.package_id || row.publish_output_id,
      package_batch_id: row.package_batch_id,
      queue_item_id: row.queue_item_id,
    });
    if (data?.ok) {
      setReceiptRow(null);
    }
  }

  async function deleteSelectedWaiting() {
    const ids = selectedRows
      .filter(isDeletableQueueRow)
      .map((row) => row.package_id || row.publish_output_id)
      .filter(Boolean);
    if (!ids.length) {
      setError("Select at least one waiting row.");
      return;
    }
    const ok = window.confirm(`Delete ${ids.length} waiting publishing row${ids.length === 1 ? "" : "s"}? Generated assets and creator jobs will not be deleted. If this removes the last package in a test publish batch, its orphan publisher instance may also be deleted.`);
    if (!ok) return;

    const data = await postAction("delete-unscheduled", { package_ids: ids });
    if (data?.ok) {
      const deleted = Number(data.item?.deleted_count || 0);
      const blocked = Array.isArray(data.item?.blocked) ? data.item.blocked : [];
      const deletedInstances = Array.isArray(data.item?.deleted_playlist_instance_ids) ? data.item.deleted_playlist_instance_ids : [];
      const instanceMessage = deletedInstances.length
        ? ` Deleted orphan publisher instance${deletedInstances.length === 1 ? "" : "s"} #${deletedInstances.join(", #")}.`
        : "";
      setSelected({});
      setMessage(`Deleted ${deleted} waiting publishing row${deleted === 1 ? "" : "s"}.${instanceMessage}${blocked.length ? ` Blocked: ${blocked.join(" ")}` : ""}`);
    }
  }

  async function deleteVisibleWaiting() {
    const ids = rows
      .filter(isDeletableQueueRow)
      .map((row) => row.package_id || row.publish_output_id)
      .filter(Boolean);
    if (!ids.length) {
      setError("No visible waiting rows to delete.");
      return;
    }
    const ok = window.confirm(`Delete all ${ids.length} visible waiting publishing row${ids.length === 1 ? "" : "s"}? Generated assets and creator jobs will not be deleted. If this removes the last package in a test publish batch, its orphan publisher instance may also be deleted.`);
    if (!ok) return;

    const data = await postAction("delete-unscheduled", { package_ids: ids });
    if (data?.ok) {
      const deleted = Number(data.item?.deleted_count || 0);
      const blocked = Array.isArray(data.item?.blocked) ? data.item.blocked : [];
      const deletedInstances = Array.isArray(data.item?.deleted_playlist_instance_ids) ? data.item.deleted_playlist_instance_ids : [];
      const instanceMessage = deletedInstances.length
        ? ` Deleted orphan publisher instance${deletedInstances.length === 1 ? "" : "s"} #${deletedInstances.join(", #")}.`
        : "";
      setSelected({});
      setMessage(`Deleted ${deleted} visible waiting publishing row${deleted === 1 ? "" : "s"}.${instanceMessage}${blocked.length ? ` Blocked: ${blocked.join(" ")}` : ""}`);
    }
  }

  return (
    <div className="admin-publication-scheduler">
      <Toast toast={toast} onClose={() => setToast(null)} />
      <header className="scheduler-header">
        <div>
          <h1>Publication Scheduler</h1>
        </div>
        <div className="scheduler-header__actions">
          <a className="scheduler-link-button" href="/admin/packager">Back to Packager</a>
          <a className="scheduler-link-button" href="/admin/publisher">Published Assets</a>
          <button type="button" onClick={() => loadQueue()} disabled={loading}>Refresh</button>
          <button type="button" onClick={openChannelTimings} disabled={loading}>Channel Timings</button>
          <button type="button" className="danger" onClick={deleteSelectedWaiting} disabled={loading || selectedRows.length === 0}>Delete Selected Waiting</button>
        </div>
      </header>

      {timeEditor ? (
        <ScheduleTimeDialog
          editor={timeEditor}
          loading={loading}
          onClose={() => setTimeEditor(null)}
          onChange={(patch) => setTimeEditor((current) => ({ ...current, ...patch }))}
          onSave={saveTimeEditor}
        />
      ) : null}

      {timingsOpen ? (
        <ChannelTimingsDialog
          channels={timingChannels}
          editor={timingEditor}
          loading={loading}
          onClose={() => setTimingsOpen(false)}
          onChannelChange={setTimingChannel}
          onEnvironmentChange={(environment) => setTimingChannel(timingEditor.publishing_channel_id, environment)}
          onSettingsChange={patchTimingSettings}
          onSave={saveChannelTiming}
        />
      ) : null}

      {receiptRow ? (
        <PublishReceiptDialog
          row={receiptRow}
          loading={loading}
          onClose={() => setReceiptRow(null)}
          onRetry={() => retryReceiptRow(receiptRow)}
        />
      ) : null}

      {manualDetailsRow ? (
        <ManualPackageDetailsDialog
          row={manualDetailsRow}
          loading={loading}
          onClose={() => setManualDetailsRow(null)}
          onCopy={copyManualField}
          onMarkPublished={markManualPublished}
        />
      ) : null}

      <section className="scheduler-panel">
        <h2>Filters</h2>
        <div className="scheduler-form scheduler-form--filters">
          <label>
            Environment
            <select value={filters.environment} onChange={(e) => updateFilter("environment", e.target.value)}>
              <option value="all">All</option>
              <option value="test">Test</option>
              <option value="production">Production</option>
            </select>
          </label>
          <label>
            Schedule Status
            <select value={filters.schedule_status} onChange={(e) => updateFilter("schedule_status", e.target.value)}>
              <option value="all">All</option>
              <option value="unscheduled">Unscheduled</option>
              <option value="waiting">Waiting</option>
              <option value="in_progress">In progress</option>
              <option value="error">Error</option>
              <option value="published">Published</option>
              <option value="cancelled">Cancelled</option>
              <option value="skipped_duplicate">Skipped duplicate</option>
            </select>
          </label>
          <label>
            Platform
            <select value={filters.platform} onChange={(e) => updateFilter("platform", e.target.value)}>
              <option value="all">All</option>
              <option value="pinterest">Pinterest</option>
              <option value="youtube">YouTube</option>
            </select>
          </label>
          <label>
            Creator Job
            <input
              value={filters.creator_job}
              onChange={(e) => updateFilter("creator_job", e.target.value)}
              placeholder="Job ID or title"
            />
          </label>
        </div>
        <div className="scheduler-panel__actions scheduler-panel__actions--below">
          <button type="button" onClick={() => loadQueue()} disabled={loading}>Apply Filters</button>
        </div>
      </section>

      <section className="scheduler-panel scheduler-panel--queue">
        {message ? <div className="scheduler-alert scheduler-alert--success">{message}</div> : null}
        {error ? <div className="scheduler-alert scheduler-alert--error">{error}</div> : null}
        <div className="scheduler-panel__header">
          <div>
            <h2>Queue</h2>
            <p>Prepared publishing rows wait here. The worker releases one due job per channel/environment and mixes creator jobs when possible.</p>
          </div>
          <div className="scheduler-panel__actions">
            <button type="button" onClick={() => setVisibleSelection(true)} disabled={loading || rows.length === 0}>
              Select All Visible
            </button>
            <button type="button" onClick={selectVisibleDeletable} disabled={loading || rows.length === 0}>
              Select Visible Waiting
            </button>
            <button type="button" className="danger" onClick={deleteVisibleWaiting} disabled={loading || rows.length === 0}>
              Delete Visible Waiting
            </button>
            <button type="button" onClick={() => setSelected({})} disabled={loading || selectedRows.length === 0}>
              Clear Selection
            </button>
            <button
              type="button"
              disabled={loading || selectedRows.length !== 1}
              onClick={() => runNow({
                package_id: selectedRows[0]?.package_id || selectedRows[0]?.publish_output_id,
                package_batch_id: selectedRows[0]?.package_batch_id,
                queue_item_id: selectedRows[0]?.queue_item_id,
              })}
            >
              Run Selected Now
            </button>
          </div>
        </div>
        <div
          className="scheduler-table-scrollbar"
          ref={topScrollRef}
          onScroll={() => syncHorizontalScroll(topScrollRef, tableWrapRef)}
          aria-label="Queue horizontal scroll"
        >
          <div style={{ width: `${tableScrollWidth}px` }} />
        </div>
        <div
          className="scheduler-table-wrap"
          ref={tableWrapRef}
          onScroll={() => syncHorizontalScroll(tableWrapRef, topScrollRef)}
        >
          <table className="scheduler-table" ref={tableRef}>
            <thead>
              <tr>
                <th>
                  <label className="scheduler-use-all">
                    <input
                      type="checkbox"
                      checked={allVisibleSelected}
                      onChange={(event) => setVisibleSelection(event.target.checked)}
                    />
                    Use
                  </label>
                </th>
                <th>Queue</th>
                <th>Preview</th>
                <th>Publishing Job</th>
                <th>Job</th>
                <th>Channel Track</th>
                <th>Env</th>
                <th>Title</th>
                <th>Queue Status</th>
                <th>Job Status</th>
                <th>Attempts</th>
                <th>Receipt</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 ? (
                <tr>
                  <td colSpan="13" className="scheduler-empty">
                    {loading ? "Loading queue..." : "No publishing jobs are ready for scheduling yet."}
                  </td>
                </tr>
              ) : rows.map((row) => {
                const id = rowKey(row);
                const rowStatusClass = rowClassName(row);
                return (
                <tr
                  key={id}
                  className={rowStatusClass}
                  onDoubleClick={() => {
                    if (isWaitingRow(row)) {
                      setManualDetailsRow(row);
                    } else {
                      setReceiptRow(row);
                    }
                  }}
                  title="Double-click for details"
                >
                  <td>
                    <input
                      type="checkbox"
                      checked={Boolean(selected[id])}
                      onChange={(e) => toggleSelected(id, e.target.checked)}
                    />
                  </td>
                  <td>
                    <button
                      type="button"
                      className="scheduler-time-button"
                      onClick={() => openTimeEditor(row)}
                      disabled={loading}
                    >
                      {formatScheduleTime(toLocalInputValue(row.scheduled_at)) || (row.schedule_status === "waiting" ? "Waiting" : "Set Time")}
                    </button>
                  </td>
                  <td>{row.media_url || row.image_url ? <img className="scheduler-thumb" src={row.media_url || row.image_url} alt="" /> : "-"}</td>
                  <td>Batch #{row.package_batch_id || "-"}<br />Package #{row.package_id || row.publish_output_id || "-"}</td>
                  <td>{row.creator_job_id ? `#${row.creator_job_id}` : "-"}<br />{row.creator_job_title || ""}</td>
                  <td>{row.channel_label || row.platform || "-"}</td>
                  <td>{row.environment || "-"}</td>
                  <td>{row.title || "-"}</td>
                  <td><StatusPill value={row.schedule_status || "unscheduled"} /></td>
                  <td><StatusPill value={row.publication_status || "-"} /></td>
                  <td>{row.attempt_count || 0}/{row.max_attempts || 3}</td>
                  <td className={receiptCellClass(row)}>
                    {receiptLabel(row)}
                    <div className="scheduler-receipt-hint">{isWaitingRow(row) ? "Manual upload details available" : "Double-click for details"}</div>
                  </td>
                  <td className="scheduler-actions-cell">
                    {isWaitingRow(row) ? (
                      <button type="button" onClick={() => setManualDetailsRow(row)} disabled={loading}>
                        Details
                      </button>
                    ) : null}
                    <button type="button" onClick={() => openTimeEditor(row)} disabled={loading}>
                      Set Time
                    </button>
                    <button
                      type="button"
                      onClick={() => runNow({
                        package_id: row.package_id || row.publish_output_id,
                        package_batch_id: row.package_batch_id,
                        queue_item_id: row.queue_item_id,
                      })}
                      disabled={loading}
                    >
                      Run Now
                    </button>
                    {row.queue_item_id ? (
                      <button
                        type="button"
                        className="danger"
                        onClick={() => postAction("cancel", { queue_item_id: row.queue_item_id })}
                        disabled={loading || row.schedule_status === "published"}
                      >
                        Remove
                      </button>
                    ) : null}
                  </td>
                </tr>
              );
              })}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}

function failureDetail(item) {
  const result = item?.result || item || {};
  return result.error_message || result.error_code || result.response_summary?.message || "";
}

function ChannelTimingsDialog({
  channels,
  editor,
  loading,
  onClose,
  onChannelChange,
  onEnvironmentChange,
  onSettingsChange,
  onSave,
}) {
  const settings = editor.settings || {};
  const currentChannel = channels.find((channel) => String(channel.publishing_channel_id) === String(editor.publishing_channel_id));
  return (
    <div className="scheduler-modal-backdrop" role="presentation">
      <div className="scheduler-modal scheduler-modal--wide" role="dialog" aria-modal="true" aria-label="Channel timings">
        <header className="scheduler-modal__header">
          <div>
            <h2>Channel Timings</h2>
            <p>These rules are applied per channel and environment when jobs are sent to the scheduler.</p>
          </div>
          <button type="button" onClick={onClose} disabled={loading}>Close</button>
        </header>
        <div className="scheduler-modal__body scheduler-modal__body--grid">
          <label>
            Channel
            <select
              value={editor.publishing_channel_id || ""}
              onChange={(event) => onChannelChange(event.target.value)}
            >
              {channels.length === 0 ? <option value="">No channels found</option> : null}
              {channels.map((channel) => (
                <option key={channel.publishing_channel_id} value={channel.publishing_channel_id}>
                  {channel.label || channel.channel_key || channel.platform}
                </option>
              ))}
            </select>
          </label>
          <label>
            Environment
            <select
              value={editor.environment || "test"}
              onChange={(event) => onEnvironmentChange(event.target.value)}
            >
              <option value="test">Test</option>
              <option value="production">Production</option>
            </select>
          </label>
          <label>
            Max posts per day
            <input
              type="number"
              min="1"
              value={settings.max_posts_per_day ?? ""}
              onChange={(event) => onSettingsChange({ max_posts_per_day: event.target.value })}
            />
          </label>
          <label>
            Minimum spacing minutes
            <input
              type="number"
              min="0"
              value={settings.minimum_spacing_minutes ?? ""}
              onChange={(event) => onSettingsChange({ minimum_spacing_minutes: event.target.value })}
            />
          </label>
          <label>
            Window start
            <input
              type="time"
              value={settings.publishing_window_start || "08:00"}
              onChange={(event) => onSettingsChange({ publishing_window_start: event.target.value })}
            />
          </label>
          <label>
            Window end
            <input
              type="time"
              value={settings.publishing_window_end || "20:00"}
              onChange={(event) => onSettingsChange({ publishing_window_end: event.target.value })}
            />
          </label>
          <label>
            Lead minutes
            <input
              type="number"
              min="0"
              value={settings.lead_minutes ?? ""}
              onChange={(event) => onSettingsChange({ lead_minutes: event.target.value })}
            />
          </label>
          <label>
            Round to minutes
            <input
              type="number"
              min="1"
              value={settings.slot_round_minutes ?? ""}
              onChange={(event) => onSettingsChange({ slot_round_minutes: event.target.value })}
            />
          </label>
          <label className="scheduler-modal__full">
            Timezone
            <input
              value={settings.timezone || "America/Los_Angeles"}
              onChange={(event) => onSettingsChange({ timezone: event.target.value })}
            />
          </label>
          <div className="scheduler-modal__full scheduler-note">
            Track: {(currentChannel?.platform || "channel").toString()} / {editor.environment || "test"}. Different channels and environments can share the same clock time.
          </div>
        </div>
        <footer className="scheduler-modal__actions">
          <button type="button" onClick={onClose} disabled={loading}>Cancel</button>
          <button type="button" className="primary" onClick={onSave} disabled={loading || !editor.publishing_channel_id}>
            Save Channel Timing
          </button>
        </footer>
      </div>
    </div>
  );
}

function ScheduleTimeDialog({ editor, loading, onClose, onChange, onSave }) {
  const row = editor.row || {};
  return (
    <div className="scheduler-modal-backdrop" role="presentation">
      <div className="scheduler-modal" role="dialog" aria-modal="true" aria-label="Schedule publishing time">
        <header className="scheduler-modal__header">
          <div>
            <h2>Schedule Time</h2>
            <p>Package #{row.package_id || row.publish_output_id || "-"} · {row.title || "Untitled"}</p>
          </div>
          <button type="button" onClick={onClose} disabled={loading}>Close</button>
        </header>
        <div className="scheduler-modal__body">
          <label>
            Scheduled time
            <input
              type="datetime-local"
              value={editor.scheduled_at || ""}
              onChange={(event) => onChange({ scheduled_at: event.target.value })}
              autoFocus
            />
          </label>
          <label>
            Timezone
            <input
              value={editor.timezone || "America/Los_Angeles"}
              onChange={(event) => onChange({ timezone: event.target.value })}
            />
          </label>
          <dl className="scheduler-modal__details">
            <dt>Platform</dt>
            <dd>{row.platform || row.channel_label || "-"}</dd>
            <dt>Environment</dt>
            <dd>{row.environment || "-"}</dd>
            <dt>Status</dt>
            <dd>{row.schedule_status || "unscheduled"}</dd>
          </dl>
        </div>
        <footer className="scheduler-modal__actions">
          <button type="button" onClick={onClose} disabled={loading}>Cancel</button>
          <button type="button" className="primary" onClick={onSave} disabled={loading || !editor.scheduled_at}>
            Save Time
          </button>
        </footer>
      </div>
    </div>
  );
}

function ManualPackageDetailsDialog({ row, loading, onClose, onCopy, onMarkPublished }) {
  const [publishedUrl, setPublishedUrl] = useState(row.external_url || row.publisher_external_url || "");
  const [externalId, setExternalId] = useState(row.external_id || row.publisher_external_id || "");
  const [notes, setNotes] = useState("");
  const [result, setResult] = useState("");
  const platform = String(row.platform || "").toLowerCase();
  const title = platform === "youtube" ? "YouTube Studio Upload Details" : "Manual Publish Details";
  const fields = platform === "youtube" ? youtubeManualFields(row) : genericManualFields(row);

  async function submitManualPublished() {
    const data = await onMarkPublished(row, {
      external_url: publishedUrl,
      external_id: externalId,
      notes,
    });
    if (data?.ok) {
      setResult("Recorded. This package is now in Published Assets.");
      if (!externalId && data.item?.external_id) setExternalId(data.item.external_id);
      if (!publishedUrl && data.item?.external_url) setPublishedUrl(data.item.external_url);
    }
  }

  return (
    <div className="scheduler-modal-backdrop" role="presentation" onDoubleClick={onClose}>
      <div
        className="scheduler-modal scheduler-modal--manual"
        role="dialog"
        aria-modal="true"
        aria-label={title}
        onDoubleClick={(event) => event.stopPropagation()}
      >
        <header className="scheduler-modal__header">
          <div>
            <h2>{title}</h2>
            <p>Package #{row.package_id || row.publish_output_id || "-"} · {row.channel_label || row.platform || "Channel"}</p>
          </div>
          <button type="button" onClick={onClose} disabled={loading}>Close</button>
        </header>
        <div className="scheduler-modal__body scheduler-manual-body">
          <section className="scheduler-manual-section">
            <h3>{platform === "youtube" ? "YouTube Studio Fields" : "Channel Fields"}</h3>
            <div className="scheduler-manual-fields">
              {fields.map((field) => (
                <CopyField
                  key={field.label}
                  label={field.label}
                  value={field.value}
                  multiline={field.multiline}
                  href={field.href}
                  onCopy={onCopy}
                />
              ))}
            </div>
          </section>

          <section className="scheduler-manual-section">
            <h3>Record Channel Result</h3>
            <div className="scheduler-manual-result-grid">
              <label>
                Published URL
                <input
                  value={publishedUrl}
                  onChange={(event) => setPublishedUrl(event.target.value)}
                  placeholder={platform === "youtube" ? "https://www.youtube.com/watch?v=..." : "Published post URL"}
                />
              </label>
              <label>
                External ID
                <input
                  value={externalId}
                  onChange={(event) => setExternalId(event.target.value)}
                  placeholder={platform === "youtube" ? "YouTube video ID, optional if URL has v=" : "Optional"}
                />
              </label>
              <label className="scheduler-modal__full">
                Notes
                <textarea
                  value={notes}
                  onChange={(event) => setNotes(event.target.value)}
                  placeholder="Anything returned by the channel or worth remembering"
                />
              </label>
            </div>
            {result ? <div className="scheduler-alert scheduler-alert--success">{result}</div> : null}
          </section>
        </div>
        <footer className="scheduler-modal__actions">
          <button type="button" onClick={onClose} disabled={loading}>Close</button>
          <button
            type="button"
            className="primary"
            onClick={submitManualPublished}
            disabled={loading || (!publishedUrl.trim() && !externalId.trim())}
          >
            Mark Published
          </button>
        </footer>
      </div>
    </div>
  );
}

function CopyField({ label, value, multiline = false, href = "", onCopy }) {
  const text = String(value || "");
  return (
    <div className={multiline ? "scheduler-copy-field scheduler-copy-field--multiline" : "scheduler-copy-field"}>
      <div className="scheduler-copy-field__label">{label}</div>
      <div className="scheduler-copy-field__control">
        {multiline ? (
          <textarea value={text} readOnly />
        ) : (
          <input value={text} readOnly />
        )}
        <button
          type="button"
          className="scheduler-copy-field__button"
          onClick={() => onCopy(text, label)}
          disabled={!text}
          aria-label={`Copy ${label}`}
          title={`Copy ${label}`}
        >
          <Clipboard size={15} aria-hidden="true" />
        </button>
      </div>
      {href ? (
        <a className="scheduler-copy-field__link" href={href} target="_blank" rel="noreferrer">
          Open
        </a>
      ) : null}
    </div>
  );
}

function PublishReceiptDialog({ row, loading, onClose, onRetry }) {
  const failed = isErroredRow(row);
  const succeeded = isSuccessfulRow(row);
  const title = failed ? "Publish Error" : succeeded ? "Publish Receipt" : "Publish Details";
  const responseJson = row.published_response_payload_json || row.publisher_response_payload_json || "";
  const requestJson = row.publisher_request_payload_json || "";
  const externalUrl = row.external_url || row.publisher_external_url || "";
  const externalId = row.external_id || row.publisher_external_id || "";
  const errorText = receiptError(row);

  return (
    <div className="scheduler-modal-backdrop" role="presentation" onDoubleClick={onClose}>
      <div
        className="scheduler-modal scheduler-modal--receipt"
        role="dialog"
        aria-modal="true"
        aria-label={title}
        onDoubleClick={(event) => event.stopPropagation()}
      >
        <header className="scheduler-modal__header">
          <div>
            <h2>{title}</h2>
            <p>Package #{row.package_id || row.publish_output_id || "-"} · {row.title || "Untitled"}</p>
          </div>
          <button type="button" onClick={onClose} disabled={loading}>Close</button>
        </header>
        <div className="scheduler-modal__body scheduler-receipt-body">
          <dl className="scheduler-modal__details scheduler-receipt-details">
            <dt>Result</dt>
            <dd><StatusPill value={failed ? "error" : succeeded ? "published" : (row.schedule_status || row.publication_status || "unknown")} /></dd>
            <dt>Where</dt>
            <dd>{receiptWhere(row)}</dd>
            <dt>Channel</dt>
            <dd>{row.channel_label || row.platform || "-"}</dd>
            <dt>Environment</dt>
            <dd>{row.environment || "-"}</dd>
            <dt>Creator job</dt>
            <dd>{row.creator_job_id ? `#${row.creator_job_id}` : "-"} {row.creator_job_title || ""}</dd>
            <dt>Queue item</dt>
            <dd>{row.queue_item_id || "-"}</dd>
            <dt>Attempt</dt>
            <dd>{row.publisher_attempt_id ? `#${row.publisher_attempt_id}` : "-"} {row.publisher_attempt_status ? `(${row.publisher_attempt_status})` : ""}</dd>
            <dt>External ID</dt>
            <dd>{externalId || "-"}</dd>
            <dt>External URL</dt>
            <dd>{externalUrl ? <a href={externalUrl} target="_blank" rel="noreferrer">{externalUrl}</a> : "-"}</dd>
            <dt>Published</dt>
            <dd>{row.published_asset_published_at || row.published_at || row.completed_at || "-"}</dd>
          </dl>

          {errorText ? (
            <section className="scheduler-receipt-section scheduler-receipt-section--error">
              <h3>Error</h3>
              <p>{errorText}</p>
              {row.publisher_error_code || row.schedule_error_code || row.last_error_code ? (
                <p className="scheduler-receipt-code">
                  Code: {row.publisher_error_code || row.schedule_error_code || row.last_error_code}
                </p>
              ) : null}
            </section>
          ) : null}

          <section className="scheduler-receipt-section">
            <h3>Channel Response</h3>
            <pre className="scheduler-receipt-pre">{formatJson(responseJson) || "No channel response stored yet."}</pre>
          </section>

          <section className="scheduler-receipt-section">
            <h3>Request Payload</h3>
            <pre className="scheduler-receipt-pre">{formatJson(requestJson) || "No request payload stored yet."}</pre>
          </section>
        </div>
        <footer className="scheduler-modal__actions">
          {failed ? (
            <button type="button" className="primary" onClick={onRetry} disabled={loading}>
              Retry
            </button>
          ) : null}
          <button type="button" onClick={onClose} disabled={loading}>Close</button>
        </footer>
      </div>
    </div>
  );
}

function StatusPill({ value }) {
  const status = String(value || "-");
  return <span className={`scheduler-status-pill scheduler-status-pill--${statusClass(status)}`}>{status}</span>;
}

function youtubeManualFields(row) {
  const metadata = row.publication_metadata_json || {};
  const creatorMetadata = metadata.creator_metadata || {};
  const videoUrl = absoluteUrl(row.media_url || row.image_url || "");
  const localPath = metadata.local_mp4_path
    || metadata.local_file_path
    || creatorMetadata.local_mp4_path
    || creatorMetadata.local_file_path
    || row.media_path
    || creatorMetadata.rel_path
    || "";
  const tags = normalizeTags(creatorMetadata.tags || metadata.tags || ["ColorFix", "paint colors", "home makeover"]);
  const destinationUrl = row.tracked_destination_url || row.destination_url || row.canonical_destination_url || "";
  const descriptionParts = [
    row.description || creatorMetadata.description || "",
    destinationUrl ? `\nWatch the ColorFix palette/player page: ${destinationUrl}` : "",
  ].filter(Boolean);

  return [
    { label: "Video file URL", value: videoUrl, href: videoUrl },
    { label: "Local MP4 path", value: localPath },
    { label: "Title", value: row.title || creatorMetadata.video_title || row.creator_job_title || "" },
    { label: "Description", value: descriptionParts.join("\n"), multiline: true },
    { label: "Tags", value: tags.join(", ") },
    { label: "Visibility", value: metadata.privacy_status || "Private" },
    { label: "Made for kids", value: metadata.made_for_kids ? "Yes" : "No" },
    { label: "Package ID", value: row.package_id || row.publish_output_id || "" },
    { label: "Asset ID", value: row.asset_library_id || "" },
    { label: "Destination URL", value: destinationUrl, href: destinationUrl },
  ];
}

function genericManualFields(row) {
  const mediaUrl = absoluteUrl(row.media_url || row.image_url || "");
  const destinationUrl = row.tracked_destination_url || row.destination_url || row.canonical_destination_url || "";
  return [
    { label: "Media URL", value: mediaUrl, href: mediaUrl },
    { label: "Media path", value: row.media_path || "" },
    { label: "Title", value: row.title || "" },
    { label: "Description", value: row.description || "", multiline: true },
    { label: "Destination URL", value: destinationUrl, href: destinationUrl },
    { label: "Alt text", value: row.alt_text || "", multiline: true },
    { label: "Package ID", value: row.package_id || row.publish_output_id || "" },
    { label: "Asset ID", value: row.asset_library_id || "" },
  ];
}

function absoluteUrl(value) {
  const text = String(value || "").trim();
  if (!text) return "";
  if (/^https?:\/\//i.test(text)) return text;
  return `${SITE_ORIGIN}${text.startsWith("/") ? "" : "/"}${text}`;
}

function normalizeTags(value) {
  if (Array.isArray(value)) {
    return value.map((item) => String(item || "").trim()).filter(Boolean);
  }
  return String(value || "")
    .split(/[,#]/)
    .map((item) => item.trim())
    .filter(Boolean);
}

function rowKey(row) {
  return String(row.package_id || row.publish_output_id || row.package_batch_id || "");
}

function isWaitingRow(row) {
  return String(row.schedule_status || "").toLowerCase() === "waiting";
}

function isErroredRow(row) {
  const scheduleStatus = String(row.schedule_status || "").toLowerCase();
  const publicationStatus = String(row.publication_status || "").toLowerCase();
  const attemptStatus = String(row.publisher_attempt_status || "").toLowerCase();
  return scheduleStatus === "error"
    || ["error", "failed", "scheduled_retry_pending"].includes(publicationStatus)
    || attemptStatus === "failed"
    || Boolean(receiptError(row));
}

function isSuccessfulRow(row) {
  const scheduleStatus = String(row.schedule_status || "").toLowerCase();
  const publicationStatus = String(row.publication_status || row.published_asset_status || "").toLowerCase();
  const attemptStatus = String(row.publisher_attempt_status || "").toLowerCase();
  return scheduleStatus === "published"
    || ["published", "test_published"].includes(publicationStatus)
    || ["published", "test_published"].includes(attemptStatus)
    || Boolean(row.external_id || row.external_url || row.publisher_external_id || row.publisher_external_url);
}

function receiptError(row) {
  return row.publisher_error_message
    || row.schedule_error
    || row.last_error_message
    || "";
}

function receiptWhere(row) {
  if (row.publisher_error_message || row.publisher_error_code) return row.publisher_service || "Publisher";
  if (row.schedule_error || row.schedule_error_code) return "Scheduler";
  if (row.last_error_message || row.last_error_code) return "Package";
  if (isSuccessfulRow(row)) return row.publisher_service || row.platform || "Channel";
  return "Pending";
}

function receiptLabel(row) {
  const errorText = receiptError(row);
  if (errorText) return `Error: ${truncate(errorText, 120)}`;
  if (isSuccessfulRow(row)) {
    const externalId = row.external_id || row.publisher_external_id;
    return externalId ? `Published: ${externalId}` : "Published";
  }
  return "No receipt yet";
}

function receiptCellClass(row) {
  if (isErroredRow(row)) return "scheduler-receipt-cell scheduler-receipt-cell--error";
  if (isSuccessfulRow(row)) return "scheduler-receipt-cell scheduler-receipt-cell--success";
  return "scheduler-receipt-cell";
}

function rowClassName(row) {
  if (isErroredRow(row)) return "scheduler-row scheduler-row--error";
  if (isSuccessfulRow(row)) return "scheduler-row scheduler-row--success";
  return "scheduler-row";
}

function statusClass(status) {
  const value = String(status || "").toLowerCase();
  if (["error", "failed", "scheduled_retry_pending"].includes(value)) return "error";
  if (["published", "test_published"].includes(value)) return "success";
  if (["in_progress"].includes(value)) return "active";
  if (["waiting", "queued"].includes(value)) return "waiting";
  return "neutral";
}

function truncate(value, limit) {
  const text = String(value || "");
  return text.length > limit ? `${text.slice(0, limit - 1)}…` : text;
}

function formatJson(value) {
  if (!value) return "";
  if (typeof value === "object") return JSON.stringify(value, null, 2);
  try {
    return JSON.stringify(JSON.parse(value), null, 2);
  } catch {
    return String(value);
  }
}

function toLocalInputValue(value) {
  if (!value) return "";
  return String(value).replace(" ", "T").slice(0, 16);
}

function formatScheduleTime(value) {
  if (!value) return "";
  return String(value).replace("T", " ");
}

function isDueNow(value) {
  if (!value) return false;
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return false;
  return date.getTime() <= Date.now();
}
