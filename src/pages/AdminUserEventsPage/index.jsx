import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import {
  DEFAULT_AUDIENCE_FILTER_OPTIONS,
  fetchAudienceOptions,
} from "@helpers/audienceOptions";
import ModalDialog from "@components/ModalDialog";
import "./admin-user-events.css";

const LIST_URL = `${API_FOLDER}/v2/admin/user-events/list.php`;
const CLEAR_URL = `${API_FOLDER}/v2/admin/user-events/clear.php`;

function formatPercent(value) {
  const num = Number(value || 0);
  return `${num.toFixed(1)}%`;
}

function formatEventTime(value, isoValue) {
  const iso = String(isoValue || "").trim();
  const raw = String(value || "").trim();
  if (!iso && !raw) return "—";

  const parsed = iso ? new Date(iso) : new Date(raw.replace(" ", "T"));
  if (Number.isNaN(parsed.getTime())) {
    return raw || iso;
  }

  return parsed.toLocaleString([], {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
    timeZone: Intl.DateTimeFormat().resolvedOptions().timeZone,
  });
}

function formatDateTimeLocalValue(date = new Date()) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  const hour = String(date.getHours()).padStart(2, "0");
  const minute = String(date.getMinutes()).padStart(2, "0");
  return `${year}-${month}-${day}T${hour}:${minute}`;
}

export default function AdminUserEventsPage() {
  const [query, setQuery] = useState("");
  const [audience, setAudience] = useState("all");
  const [source, setSource] = useState("all");
  const [includeInternal, setIncludeInternal] = useState(false);
  const [audienceOptions, setAudienceOptions] = useState(DEFAULT_AUDIENCE_FILTER_OPTIONS);
  const [items, setItems] = useState([]);
  const [totals, setTotals] = useState({
    playlist_open_count: 0,
    replay_click_count: 0,
    watch_next_click_count: 0,
  });
  const [baseline, setBaseline] = useState({
    cutoff_at: "",
    cutoff_at_iso: "",
  });
  const [baselineModalOpen, setBaselineModalOpen] = useState(false);
  const [baselineInput, setBaselineInput] = useState(formatDateTimeLocalValue());
  const [reloadKey, setReloadKey] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [clearing, setClearing] = useState(false);

  useEffect(() => {
    let ignore = false;

    fetchAudienceOptions()
      .then((options) => {
        if (ignore || options.length === 0) return;
        setAudienceOptions([{ value: "all", label: "All audiences" }, ...options]);
      })
      .catch(() => {
        if (!ignore) {
          setAudienceOptions(DEFAULT_AUDIENCE_FILTER_OPTIONS);
        }
      });

    return () => {
      ignore = true;
    };
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    let ignore = false;

    async function load() {
      setLoading(true);
      setError("");
      try {
        const params = new URLSearchParams();
        if (query.trim()) params.set("q", query.trim());
        if (audience) params.set("audience", audience);
        if (source && source !== "all") params.set("source", source);
        if (includeInternal) params.set("include_internal", "1");
        params.set("_", String(Date.now()));

        const res = await fetch(`${LIST_URL}?${params.toString()}`, {
          credentials: "include",
          signal: controller.signal,
        });
        const data = await res.json();
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load event summary");
        if (ignore) return;
        setItems(Array.isArray(data.items) ? data.items : []);
        setTotals({
          playlist_open_count: Number(data?.totals?.playlist_open_count || 0),
          replay_click_count: Number(data?.totals?.replay_click_count || 0),
          watch_next_click_count: Number(data?.totals?.watch_next_click_count || 0),
        });
        setBaseline({
          cutoff_at: String(data?.baseline?.cutoff_at || ""),
          cutoff_at_iso: String(data?.baseline?.cutoff_at_iso || ""),
        });
      } catch (err) {
        if (ignore || err?.name === "AbortError") return;
        setError(err?.message || "Failed to load event summary");
      } finally {
        if (!ignore) setLoading(false);
      }
    }

    load();

    return () => {
      ignore = true;
      controller.abort();
    };
  }, [query, audience, source, includeInternal, reloadKey]);

  const totalReplayRate = useMemo(() => {
    if (!totals.playlist_open_count) return 0;
    return (totals.replay_click_count / totals.playlist_open_count) * 100;
  }, [totals]);

  const totalWatchNextRate = useMemo(() => {
    if (!totals.playlist_open_count) return 0;
    return (totals.watch_next_click_count / totals.playlist_open_count) * 100;
  }, [totals]);

  async function handleSetBaseline() {
    setClearing(true);
    setError("");
    try {
      const res = await fetch(CLEAR_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          confirm: "CLEAR_USER_EVENTS",
          cutoff_at: baselineInput ? baselineInput.replace("T", " ") + ":00" : "",
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to clear events");
      setBaseline({
        cutoff_at: String(data?.baseline?.cutoff_at || ""),
        cutoff_at_iso: String(data?.baseline?.cutoff_at_iso || ""),
      });
      setBaselineModalOpen(false);
      setReloadKey((prev) => prev + 1);
    } catch (err) {
      setError(err?.message || "Failed to clear events");
    } finally {
      setClearing(false);
    }
  }

  return (
    <div className="admin-user-events">
      <aside className="admin-user-events__sidebar">
        <div className="admin-user-events__sidebar-header">
          <h1>View Counts</h1>
          <div className="admin-user-events__baseline-summary">
            <span className="admin-user-events__baseline-summary-label">Baseline</span>
            <span className="admin-user-events__baseline-summary-value">
              {baseline.cutoff_at || baseline.cutoff_at_iso
                ? formatEventTime(baseline.cutoff_at, baseline.cutoff_at_iso)
                : "All time"}
            </span>
          </div>
          <button
            type="button"
            className="admin-user-events__danger admin-user-events__danger--compact"
            onClick={() => {
              setBaselineInput(formatDateTimeLocalValue());
              setBaselineModalOpen(true);
            }}
            disabled={clearing}
          >
            {clearing ? "Setting Baseline..." : "Set New Baseline"}
          </button>
        </div>

        <div className="admin-user-events__filters">
          <input
            type="search"
            placeholder="Search by instance or playlist"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
          <select value={audience} onChange={(e) => setAudience(e.target.value)}>
            {audienceOptions.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
          <select value={source} onChange={(e) => setSource(e.target.value)}>
            <option value="all">All sources</option>
            <option value="email">Email</option>
            <option value="share">Share</option>
            <option value="qr">QR</option>
            <option value="watch_next">Watch Next</option>
            <option value="direct">Direct / none</option>
          </select>
          <label className="admin-user-events__checkbox">
            <input
              type="checkbox"
              checked={includeInternal}
              onChange={(e) => setIncludeInternal(e.target.checked)}
            />
            Include internal/admin tests
          </label>
        </div>

        <div className="admin-user-events__totals">
          <div className="admin-user-events__total-card">
            <div className="admin-user-events__total-label">Opens</div>
            <div className="admin-user-events__total-value">{totals.playlist_open_count}</div>
          </div>
          <div className="admin-user-events__total-card">
            <div className="admin-user-events__total-label">Replays</div>
            <div className="admin-user-events__total-value">{totals.replay_click_count}</div>
            <div className="admin-user-events__total-meta">{formatPercent(totalReplayRate)} of opens</div>
          </div>
          <div className="admin-user-events__total-card">
            <div className="admin-user-events__total-label">Watch Next</div>
            <div className="admin-user-events__total-value">{totals.watch_next_click_count}</div>
            <div className="admin-user-events__total-meta">{formatPercent(totalWatchNextRate)} of opens</div>
          </div>
        </div>
      </aside>

      <main className="admin-user-events__main">
        {error ? <div className="admin-user-events__message admin-user-events__message--error">{error}</div> : null}

        <section className="admin-user-events__panel">
          <div className="admin-user-events__panel-header">
            <h2>Playlist Funnel</h2>
            <div className="admin-user-events__panel-subtitle">
              {loading ? "Loading..." : `${items.length} tracked source row${items.length === 1 ? "" : "s"}`}
            </div>
          </div>

          {baseline.cutoff_at || baseline.cutoff_at_iso ? (
            <div className="admin-user-events__panel-subtitle">
              Tracking since {formatEventTime(baseline.cutoff_at, baseline.cutoff_at_iso)}
            </div>
          ) : null}

          {loading ? (
            <div className="admin-user-events__empty">Loading view counts…</div>
          ) : items.length === 0 ? (
            <div className="admin-user-events__empty">No tracked playlist events yet.</div>
          ) : (
            <div className="admin-user-events__table-wrap">
              <table className="admin-user-events__table">
                <thead>
                  <tr>
                    <th>Instance</th>
                    <th>Playlist</th>
                    <th>Audience</th>
                    <th>Source</th>
                    <th>Opens</th>
                    <th>Replays</th>
                    <th>Watch Next</th>
                    <th>Replay %</th>
                    <th>Next %</th>
                    <th>Last Event</th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((item) => (
                    <tr key={`${item.playlist_instance_id}:${item.source || "direct"}`}>
                      <td>
                        <span className="admin-user-events__instance-link">
                          #{item.playlist_instance_id} {item.instance_name || item.display_title || "Untitled instance"}
                        </span>
                        {item.display_title ? (
                          <div className="admin-user-events__secondary">{item.display_title}</div>
                        ) : null}
                      </td>
                      <td>
                        <div>#{item.playlist_id} {item.playlist_title || "Untitled playlist"}</div>
                      </td>
                      <td>{item.audience || "any"}</td>
                      <td>{item.source || "direct"}</td>
                      <td>{item.playlist_open_count}</td>
                      <td>{item.replay_click_count}</td>
                      <td>{item.watch_next_click_count}</td>
                      <td>{formatPercent(item.replay_rate)}</td>
                      <td>{formatPercent(item.watch_next_rate)}</td>
                      <td>{formatEventTime(item.last_event_at, item.last_event_at_iso)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      </main>

      <ModalDialog
        open={baselineModalOpen}
        onClose={() => setBaselineModalOpen(false)}
        title="Set New Baseline"
        subtitle="Choose when tracking should start. Existing events will be preserved."
        width="520px"
      >
        <div className="admin-user-events__baseline-modal">
          <label className="admin-user-events__baseline-label">
            Start counting from
            <input
              type="datetime-local"
              value={baselineInput}
              onChange={(e) => setBaselineInput(e.target.value)}
            />
          </label>
          <div className="admin-user-events__baseline-help">
            Leave this blank to show all-time view counts.
          </div>
          <div className="admin-user-events__baseline-actions">
            <button
              type="button"
              className="admin-user-events__secondary"
              onClick={() => setBaselineInput("")}
            >
              Clear Date
            </button>
            <button
              type="button"
              className="admin-user-events__secondary"
              onClick={() => setBaselineInput(formatDateTimeLocalValue())}
            >
              Use Now
            </button>
            <button
              type="button"
              className="admin-user-events__danger"
              onClick={handleSetBaseline}
              disabled={clearing}
            >
              {clearing ? "Saving..." : "OK"}
            </button>
          </div>
        </div>
      </ModalDialog>
    </div>
  );
}
