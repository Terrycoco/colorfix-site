import { useEffect, useMemo, useRef, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import Toast from "@components/Toast";
import "./admin-publication-scheduler.css";

const API_BASE = `${API_FOLDER}/v2/admin/publication-scheduler`;

export default function AdminPublicationSchedulerPage() {
  const [rows, setRows] = useState([]);
  const [selected, setSelected] = useState({});
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [toast, setToast] = useState(null);
  const [timeEditor, setTimeEditor] = useState(null);
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

  function selectVisibleUnscheduled() {
    setVisibleSelection(true, (row) => ["unscheduled", "waiting"].includes(row.schedule_status || "unscheduled"));
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
        const failed = results.filter((result) => result?.status === "failed");
        if (failed.length) {
          const detail = failureDetail(failed[0]);
          nextError = `Ran due queue. Claimed ${claimed}. Failed ${failed.length}${detail ? `: ${detail}` : "."}`;
        } else {
          nextMessage = `Ran due queue. Claimed ${claimed}.`;
        }
      } else if (path === "publish-now") {
        const item = data?.item || {};
        if (item.status === "completed") {
          nextMessage = `Ran selected publishing job #${item.publishing_asset_id || item.publishing_job_id || ""}.`;
        } else if (["failed", "retry_scheduled"].includes(item.status)) {
          const detail = failureDetail(item);
          nextError = `Selected publishing job ${item.status}${detail ? `: ${detail}` : "."}`;
        } else {
          nextMessage = `Selected publishing job ${item.status || "updated"}; see row error if it failed.`;
        }
      } else if (path === "cancel") {
        nextMessage = "Removed from scheduler queue. The publishing job is still available if you want to enqueue it again.";
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
    const rowId = row.publishing_asset_id || row.publish_output_id || row.publishing_job_id;
    return postAction(row.publication_schedule_id ? "reschedule" : "schedule", {
      publishing_asset_id: rowId,
      publishing_job_id: row.publishing_job_id,
      publication_schedule_id: row.publication_schedule_id,
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

  async function scheduleSelected() {
    if (selectedRows.length === 0) {
      setError("Select at least one publishing job.");
      return;
    }

    const unscheduledRows = selectedRows.filter((row) => (row.schedule_status || "unscheduled") === "unscheduled");
    if (unscheduledRows.length === 0) {
      setError("Selected rows are already in the scheduler queue.");
      return;
    }

    for (let index = 0; index < unscheduledRows.length; index += 1) {
      const row = unscheduledRows[index];
      // eslint-disable-next-line no-await-in-loop
      await postAction("schedule", {
        publishing_asset_id: row.publishing_asset_id || row.publish_output_id || row.publishing_job_id,
        publishing_job_id: row.publishing_job_id,
        scheduled_at: "",
        timezone: row.timezone || "America/Los_Angeles",
        priority: 100,
      });
    }
  }

  async function runNow(payload) {
    await postAction("publish-now", payload);
  }

  async function deleteSelectedUnscheduled() {
    const ids = selectedRows
      .filter((row) => (row.schedule_status || "unscheduled") === "unscheduled")
      .map((row) => row.publishing_asset_id || row.publish_output_id)
      .filter(Boolean);
    if (!ids.length) {
      setError("Select at least one unscheduled row.");
      return;
    }
    const ok = window.confirm(`Delete ${ids.length} unscheduled publishing row${ids.length === 1 ? "" : "s"}? Generated assets and creator jobs will not be deleted.`);
    if (!ok) return;

    const data = await postAction("delete-unscheduled", { publishing_asset_ids: ids });
    if (data?.ok) {
      const deleted = Number(data.item?.deleted_count || 0);
      const blocked = Array.isArray(data.item?.blocked) ? data.item.blocked : [];
      setSelected({});
      setMessage(`Deleted ${deleted} unscheduled publishing row${deleted === 1 ? "" : "s"}.${blocked.length ? ` Blocked: ${blocked.join(" ")}` : ""}`);
    }
  }

  async function deleteVisibleUnscheduled() {
    const ids = rows
      .filter((row) => (row.schedule_status || "unscheduled") === "unscheduled")
      .map((row) => row.publishing_asset_id || row.publish_output_id)
      .filter(Boolean);
    if (!ids.length) {
      setError("No visible unscheduled rows to delete.");
      return;
    }
    const ok = window.confirm(`Delete all ${ids.length} visible unscheduled publishing row${ids.length === 1 ? "" : "s"}? Generated assets and creator jobs will not be deleted.`);
    if (!ok) return;

    const data = await postAction("delete-unscheduled", { publishing_asset_ids: ids });
    if (data?.ok) {
      const deleted = Number(data.item?.deleted_count || 0);
      const blocked = Array.isArray(data.item?.blocked) ? data.item.blocked : [];
      setSelected({});
      setMessage(`Deleted ${deleted} visible unscheduled publishing row${deleted === 1 ? "" : "s"}.${blocked.length ? ` Blocked: ${blocked.join(" ")}` : ""}`);
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
          <button type="button" onClick={() => loadQueue()} disabled={loading}>Refresh</button>
          <button type="button" onClick={openChannelTimings} disabled={loading}>Channel Timings</button>
          <button type="button" className="primary" onClick={() => postAction("run-due", { limit: 5 })} disabled={loading}>Run Due Now</button>
          <button type="button" className="danger" onClick={deleteSelectedUnscheduled} disabled={loading || selectedRows.length === 0}>Delete Selected Unscheduled</button>
          <button type="button" className="primary" onClick={scheduleSelected} disabled={loading}>Enqueue Selected</button>
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
              <option value="scheduled">Manual time</option>
              <option value="processing">Processing</option>
              <option value="retry_scheduled">Retry scheduled</option>
              <option value="failed">Failed</option>
              <option value="completed">Completed</option>
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
            <button type="button" onClick={selectVisibleUnscheduled} disabled={loading || rows.length === 0}>
              Select Visible Unscheduled
            </button>
            <button type="button" className="danger" onClick={deleteVisibleUnscheduled} disabled={loading || rows.length === 0}>
              Delete Visible Unscheduled
            </button>
            <button type="button" onClick={() => setSelected({})} disabled={loading || selectedRows.length === 0}>
              Clear Selection
            </button>
            <button
              type="button"
              disabled={loading || selectedRows.length !== 1}
              onClick={() => runNow({
                publishing_asset_id: selectedRows[0]?.publishing_asset_id || selectedRows[0]?.publish_output_id,
                publishing_job_id: selectedRows[0]?.publishing_job_id,
                publication_schedule_id: selectedRows[0]?.publication_schedule_id,
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
                <th>Error</th>
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
                return (
                <tr key={id}>
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
                  <td>Job #{row.publishing_job_id}<br />Asset #{row.publishing_asset_id || row.publish_output_id || "-"}</td>
                  <td>{row.asset_creator_job_id ? `#${row.asset_creator_job_id}` : "-"}<br />{row.creator_job_title || ""}</td>
                  <td>{row.channel_label || row.platform || "-"}</td>
                  <td>{row.environment || "-"}</td>
                  <td>{row.title || "-"}</td>
                  <td>{row.schedule_status || "unscheduled"}</td>
                  <td>{row.publication_status || "-"}</td>
                  <td>{row.attempt_count || 0}/{row.max_attempts || 3}</td>
                  <td>{row.schedule_error || row.last_error_message || "-"}</td>
                  <td className="scheduler-actions-cell">
                    <button type="button" onClick={() => openTimeEditor(row)} disabled={loading}>
                      {row.publication_schedule_id ? "Set Time" : "Enqueue"}
                    </button>
                    <button
                      type="button"
                      onClick={() => runNow({
                        publishing_asset_id: row.publishing_asset_id || row.publish_output_id || row.publishing_job_id,
                        publishing_job_id: row.publishing_job_id,
                        publication_schedule_id: row.publication_schedule_id,
                      })}
                      disabled={loading}
                    >
                      Run Now
                    </button>
                    {row.publication_schedule_id ? (
                      <button
                        type="button"
                        className="danger"
                        onClick={() => postAction("cancel", { publication_schedule_id: row.publication_schedule_id })}
                        disabled={loading || row.schedule_status === "completed"}
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
            <p>Asset #{row.publishing_asset_id || row.publish_output_id || "-"} · {row.title || "Untitled"}</p>
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

function rowKey(row) {
  return String(row.publishing_asset_id || row.publish_output_id || row.publishing_job_id || "");
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
