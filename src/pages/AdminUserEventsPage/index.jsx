import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import {
  DEFAULT_AUDIENCE_FILTER_OPTIONS,
  fetchAudienceOptions,
} from "@helpers/audienceOptions";
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

  const parsed = iso ? new Date(iso) : new Date(raw);
  if (Number.isNaN(parsed.getTime())) {
    return raw || iso;
  }

  return parsed.toLocaleString([], {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
}

export default function AdminUserEventsPage() {
  const [query, setQuery] = useState("");
  const [audience, setAudience] = useState("all");
  const [audienceOptions, setAudienceOptions] = useState(DEFAULT_AUDIENCE_FILTER_OPTIONS);
  const [includeInternal, setIncludeInternal] = useState(false);
  const [items, setItems] = useState([]);
  const [totals, setTotals] = useState({
    playlist_open_count: 0,
    hire_terry_cta_visible_count: 0,
    hire_terry_cta_click_count: 0,
    watch_next_click_count: 0,
  });
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
          hire_terry_cta_visible_count: Number(data?.totals?.hire_terry_cta_visible_count || 0),
          hire_terry_cta_click_count: Number(data?.totals?.hire_terry_cta_click_count || 0),
          watch_next_click_count: Number(data?.totals?.watch_next_click_count || 0),
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
  }, [query, audience, includeInternal]);

  const totalVisibleRate = useMemo(() => {
    if (!totals.playlist_open_count) return 0;
    return (totals.hire_terry_cta_visible_count / totals.playlist_open_count) * 100;
  }, [totals]);

  const totalClickRate = useMemo(() => {
    if (!totals.hire_terry_cta_visible_count) return 0;
    return (totals.hire_terry_cta_click_count / totals.hire_terry_cta_visible_count) * 100;
  }, [totals]);

  const totalWatchNextRate = useMemo(() => {
    if (!totals.playlist_open_count) return 0;
    return (totals.watch_next_click_count / totals.playlist_open_count) * 100;
  }, [totals]);

  async function handleClearEvents() {
    const ok = window.confirm(
      "Clear all tracked view-count data? This deletes playlist opens, CTA events, and Watch Next clicks."
    );
    if (!ok) return;

    setClearing(true);
    setError("");
    try {
      const res = await fetch(CLEAR_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ confirm: "CLEAR_USER_EVENTS" }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to clear events");
      setItems([]);
      setTotals({
        playlist_open_count: 0,
        hire_terry_cta_visible_count: 0,
        hire_terry_cta_click_count: 0,
        watch_next_click_count: 0,
      });
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
          <p>Playlist opens and Hire Terry funnel events.</p>
          <button
            type="button"
            className="admin-user-events__danger"
            onClick={handleClearEvents}
            disabled={clearing}
          >
            {clearing ? "Clearing..." : "Clear Tracking Data"}
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
          <label className="admin-user-events__checkbox">
            <input
              type="checkbox"
              checked={includeInternal}
              onChange={(e) => setIncludeInternal(e.target.checked)}
            />
            Include my admin views
          </label>
        </div>

        <div className="admin-user-events__totals">
          <div className="admin-user-events__total-card">
            <div className="admin-user-events__total-label">Opens</div>
            <div className="admin-user-events__total-value">{totals.playlist_open_count}</div>
          </div>
          <div className="admin-user-events__total-card">
            <div className="admin-user-events__total-label">Hire Seen</div>
            <div className="admin-user-events__total-value">{totals.hire_terry_cta_visible_count}</div>
            <div className="admin-user-events__total-meta">{formatPercent(totalVisibleRate)} of opens</div>
          </div>
          <div className="admin-user-events__total-card">
            <div className="admin-user-events__total-label">Hire Clicks</div>
            <div className="admin-user-events__total-value">{totals.hire_terry_cta_click_count}</div>
            <div className="admin-user-events__total-meta">{formatPercent(totalClickRate)} of Hire views</div>
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
              {loading ? "Loading..." : `${items.length} tracked playlist instance${items.length === 1 ? "" : "s"}`}
            </div>
          </div>

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
                    <th>Opens</th>
                    <th>Hire Seen</th>
                    <th>Hire Clicks</th>
                    <th>Watch Next</th>
                    <th>Seen %</th>
                    <th>Click %</th>
                    <th>Next %</th>
                    <th>Last Event</th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((item) => (
                    <tr key={item.playlist_instance_id}>
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
                      <td>{item.playlist_open_count}</td>
                      <td>{item.hire_terry_cta_visible_count}</td>
                      <td>{item.hire_terry_cta_click_count}</td>
                      <td>{item.watch_next_click_count}</td>
                      <td>{formatPercent(item.visible_rate)}</td>
                      <td>{formatPercent(item.click_through_rate)}</td>
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
    </div>
  );
}
