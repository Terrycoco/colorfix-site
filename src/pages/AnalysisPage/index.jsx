import { useEffect, useMemo, useState } from "react";
import "./analysisPage.css";
import {API_FOLDER} from '@helpers/config';

// Hardcode the endpoint to reduce variables while testing.
// (Switch back to your config-based API_FOLDER later if you want.)
const ENDPOINT = `${API_FOLDER}/analysis-edges.php`;

const NEUTRAL_OPTIONS = [
  "Whites", "Blacks", "Grays", "Greiges", "Beiges", "Browns", "Creams", "Tans"
];

const HUE_OPTIONS = [
  "Reds","Reds/Oranges","Oranges","Oranges/Yellows",
  "Yellows","Yellows/Greens","Greens","Greens/Cyans",
  "Cyans","Cyans/Blues","Blues","Blues/Purples",
  "Purples","Purples/Magentas","Magentas","Magentas/Reds"
];

const SORT_OPTIONS = [
  { value: "count_desc", label: "Count (desc)" },
  { value: "delta_l_desc", label: "ΔL (desc)" },
  { value: "delta_h_abs_desc", label: "|Δh| (desc)" },
  { value: "delta_c_desc", label: "ΔC (desc)" },
  { value: "a_h_asc", label: "Anchor H (asc)" },
  { value: "b_h_asc", label: "Friend H (asc)" }
];

function toggleSort(key) {
  setSort(prev => {
    const isThis = prev.startsWith(`${key}_`);
    const nextDir =
      isThis ? (prev.endsWith('_desc') ? 'asc' : 'desc')
             : (key === 'support' || key === 'partners' ? 'desc' : 'asc'); // sensible defaults
    return `${key}_${nextDir}`;
  });
}


function chipTextColorFromL(l) {
  if (typeof l !== "number") return "#111";
  return l <= 45 ? "#fff" : "#111";
}
function hexToCss(hex6) {
  if (!hex6) return "#999";
  return `#${hex6}`;
}

function downloadCSV(filename, rows) {
  const header = [
    "anchor_hex6","a_h","a_c","a_l","a_neutral_cats","a_hue_cats",
    "friend_hex6","b_h","b_c","b_l","b_neutral_cats","b_hue_cats",
    "delta_h_signed","delta_h_abs","delta_c","delta_l","count"
  ];
  const esc = (v) => {
    if (v === null || v === undefined) return "";
    const s = String(v);
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };
  const lines = [header.join(",")].concat(
    rows.map(r => header.map(key => esc(r[key])).join(","))
  );
  const blob = new Blob([lines.join("\n")], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  URL.revokeObjectURL(url);
  a.remove();
}

export default function AnalysisPage() {
  const [anchorType, setAnchorType] = useState("neutral"); // 'neutral' | 'hue'
  const [anchorValue, setAnchorValue] = useState("Blacks");
  const [minCount, setMinCount] = useState(2);
  const [sort, setSort] = useState("count_desc");
  const [page, setPage] = useState(1);
  const [limit, setLimit] = useState(200);

  const [items, setItems] = useState([]);
  const [meta, setMeta] = useState(null);

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [debugOpen, setDebugOpen] = useState(false);
  const [lastUrl, setLastUrl] = useState("");
  const [lastStatus, setLastStatus] = useState(null);
  const [lastText, setLastText] = useState("");
  // assume you already have: const [meta, setMeta] = useState(null);
const total = meta?.total_items ?? 0;



  // NEW: header sort state
const [uiSort, setUiSort] = useState(null); // { key: 'count', dir: 'desc', type: 'num' }

function toggleSort(key, type) {
  setUiSort(prev => {
    if (!prev || prev.key !== key) {
      // default: numbers -> desc, strings -> asc
      const defaultDir = type === 'num' ? 'desc' : 'asc';
      return { key, dir: defaultDir, type };
    }
    // toggle dir
    const nextDir = prev.dir === 'asc' ? 'desc' : 'asc';
    return { key, dir: nextDir, type: prev.type };
  });
}

function SortHeader({ label, keyName, type }) {
  const active = uiSort?.key === keyName;
  const arrow = active ? (uiSort.dir === 'asc' ? '▲' : '▼') : '↕';
  return (
    <button className={"th-btn" + (active ? " th-btn--active" : "")}
            onClick={() => toggleSort(keyName, type)}
            title={`Sort by ${label}`}>
      <span className="th-label">{label}</span>
      <span className="th-arrow">{arrow}</span>
    </button>
  );
}

// NEW: apply client-side sort to what we render
const displayItems = useMemo(() => {
  if (!uiSort) return items;
  const { key, dir, type } = uiSort;
  const toVal = (v) => {
    if (v === null || v === undefined) return type === 'num' ? -Infinity : '';
    return type === 'num' ? Number(v) : String(v);
  };
  const sgn = dir === 'asc' ? 1 : -1;
  return [...items].sort((a, b) => {
    const A = toVal(a[key]);
    const B = toVal(b[key]);
    if (A < B) return -1 * sgn;
    if (A > B) return  1 * sgn;
    return 0;
  });
}, [items, uiSort]);


  // Build query string visible in UI for sanity
  const qs = useMemo(() => {
    const p = new URLSearchParams();
    p.set("anchor_type", anchorType);
    p.set("anchor_value", anchorValue);
    p.set("min_count", String(minCount));
    p.set("sort", sort);
    p.set("page", String(page));
    p.set("limit", String(limit));

    return p.toString();
  }, [anchorType, anchorValue, minCount, sort, page, limit]);

  async function runQuery() {
    setLoading(true);
    setError("");
    setLastText("");
    const url = `${ENDPOINT}?${qs}&ts=${Date.now()}`; 
    setLastUrl(url);
    setLastStatus(null);

    try {
      // Avoid credentials for now (keeps CORS simple while testing).
      console.log("[Analysis] Fetching:", url);
      const res = await fetch(url, {
        method: "GET",
        headers: { "Accept": "application/json" }
      });
      setLastStatus(res.status);

      if (!res.ok) {
        const txt = await res.text().catch(() => "");
        setLastText(txt);
        throw new Error(`HTTP ${res.status}${txt ? ` — ${txt.slice(0, 200)}` : ""}`);
      }

      // Sometimes servers send HTML on error; guard parse
      const text = await res.text();
      setLastText(text.slice(0, 3000));
      let data;
      try {
        data = JSON.parse(text);
      } catch (e) {
        throw new Error("Non-JSON response received from API.");
      }

      const safeItems = (Array.isArray(data.items) ? data.items : []).map(row => {
        const obj = { ...row };
        if (obj.delta_h_abs === undefined && obj.a_h !== undefined && obj.b_h !== undefined) {
          const signed = (( (obj.b_h - obj.a_h + 540) % 360) - 180);
          obj.delta_h_signed = signed;
          obj.delta_h_abs = Math.abs(signed);
        }
        if (obj.delta_c === undefined && obj.a_c !== undefined && obj.b_c !== undefined) {
          obj.delta_c = obj.b_c - obj.a_c;
        }
        if (obj.delta_l === undefined && obj.a_l !== undefined && obj.b_l !== undefined) {
          obj.delta_l = obj.b_l - obj.a_l;
        }
        return obj;
      });

      setItems(safeItems);
      setMeta(data.meta || null);
      console.log("[Analysis] Loaded rows:", safeItems.length);
    } catch (e) {
      console.error("[Analysis] Fetch error:", e);
      setError(String(e.message || e));
      setItems([]);
      setMeta(null);
    } finally {
      setLoading(false);
    }
  }

  function onChangeAnchorType(e) {
    const t = e.target.value;
    setAnchorType(t);
    setAnchorValue(t === "neutral" ? "Blacks" : "Greens");
  }

  function onExport() {
    if (!items.length) return;
    const filename = `analysis_${anchorType}_${anchorValue}_p${page}.csv`;
    downloadCSV(filename, items);
  }

  const totalItems = meta?.total_items ?? items.length;
  const totalPages = meta?.limit && meta?.total_items
    ? Math.max(1, Math.ceil(meta.total_items / meta.limit))
    : undefined;

  return (
    <div className="full-bleed">
    <div className="analysis full-bleed">
      <aside className="analysis__controls">
        <h2 className="analysis__title">Color Analysis</h2>

        <div className="field">
          <label>Anchor type</label>
          <select value={anchorType} onChange={onChangeAnchorType}>
            <option value="neutral">Neutral group</option>
            <option value="hue">Hue group</option>
          </select>
        </div>

        <div className="field">
          <label>Anchor value</label>
          <select value={anchorValue} onChange={(e) => setAnchorValue(e.target.value)}>
            {(anchorType === "neutral" ? NEUTRAL_OPTIONS : HUE_OPTIONS).map(v => (
              <option key={v} value={v}>{v}</option>
            ))}
          </select>
        </div>

        <div className="field">
          <label>Min count</label>
          <input
            type="number"
            min={0}
            step={1}
            value={minCount}
            onChange={(e) => setMinCount(Number(e.target.value || 0))}
          />
        </div>

        <div className="field">
          <label>Sort</label>
          <select value={sort} onChange={(e) => setSort(e.target.value)}>
            {SORT_OPTIONS.map(s => (
              <option key={s.value} value={s.value}>{s.label}</option>
            ))}
          </select>
        </div>

        <div className="field inline">
          <div>
            <label>Page</label>
            <input
              type="number"
              min={1}
              value={page}
              onChange={(e) => setPage(Math.max(1, Number(e.target.value || 1)))}
            />
          </div>
          <div>
            <label>Limit</label>
            <input
              type="number"
              min={25}
              step={25}
              value={limit}
              onChange={(e) => setLimit(Math.max(25, Number(e.target.value || 25)))}
            />
          </div>
        </div>

        <div className="actions">
          <button className="btn btn--primary" onClick={runQuery} disabled={loading}>
            {loading ? "Loading…" : "Run"}
          </button>
          <button className="btn btn--ghost" onClick={onExport} disabled={!items.length}>
            Export CSV
          </button>
        </div>

        <div className="meta">
          <div><strong>Rows:</strong> {items.length}</div>
          {typeof totalPages === "number" && (
            <div><strong>Page:</strong> {page} / {totalPages}</div>
          )}
          <div className="qs">
            <strong>Query:</strong>
            <code>?{qs}</code>
          </div>
          <button className="btn btn--ghost" onClick={() => setDebugOpen(v => !v)}>
            {debugOpen ? "Hide debug" : "Show debug"}
          </button>
        </div>
      </aside>

      <main className="analysis__main">
        {/* Sticky top toolbar */}
        <div className="analysis__toolbar">
             <div className="result-summary">
            <strong>{total.toLocaleString()}</strong> cluster pairs
            &nbsp;for&nbsp;
            <em>{anchorType === 'hue' ? 'hue' : 'neutral'}</em> “{anchorValue}”
            &nbsp;· min count ≥ {minCount}
            &nbsp;· page {page} of {totalPages}
          </div>
          <button className="btn btn--primary" onClick={runQuery} disabled={loading}>
            {loading ? "Loading…" : "Run Analysis"}
          </button>
          <button className="btn btn--ghost" onClick={onExport} disabled={!items.length}>
            Export CSV
          </button>
       
        </div>

        {error && <div className="error">{error}</div>}

        {debugOpen && (
          <div className="debug">
            <div><strong>URL</strong>: <code>{lastUrl}</code></div>
            <div><strong>Status</strong>: {lastStatus ?? "—"}</div>
            <details>
              <summary>Response preview</summary>
              <pre>{lastText}</pre>
            </details>
          </div>
        )}

        <div className="tableScroller">
          <table className="grid">
<colgroup>
  <col className="w-hex" />
  <col className="w-num" /><col className="w-num" /><col className="w-num" />
  <col className="w-catN" /><col className="w-hue" />
  <col className="w-hex" />
  <col className="w-num" /><col className="w-num" /><col className="w-num" />
  <col className="w-catN" /><col className="w-hue" />
  <col className="w-num" /><col className="w-num" /><col className="w-num" /><col className="w-num" />
  <col className="w-count" />
</colgroup>
     <thead>
  <tr>
    <th className="col-hex-head"><SortHeader label="Anchor"        keyName="anchor_hex6"   type="str" /></th>
    <th><SortHeader label="H"                keyName="a_h"           type="num" /></th>
    <th><SortHeader label="C"                keyName="a_c"           type="num" /></th>
    <th><SortHeader label="L"                keyName="a_l"           type="num" /></th>
    <th className="col-cat"><SortHeader label="neutral_cats" keyName="a_neutral_cats" type="str" /></th>
    <th className="col-cat"><SortHeader label="hue_cats"     keyName="a_hue_cats"     type="str" /></th>

    <th className="col-hex-head"><SortHeader label="Friend"         keyName="friend_hex6"    type="str" /></th>
    <th><SortHeader label="H"                keyName="b_h"           type="num" /></th>
    <th><SortHeader label="C"                keyName="b_c"           type="num" /></th>
    <th><SortHeader label="L"                keyName="b_l"           type="num" /></th>
    <th className="col-cat"><SortHeader label="neutral_cats" keyName="b_neutral_cats" type="str" /></th>
    <th className="col-cat"><SortHeader label="hue_cats"     keyName="b_hue_cats"     type="str" /></th>

    <th><SortHeader label="Δh"              keyName="delta_h_signed" type="num" /></th>
    <th><SortHeader label="|Δh|"            keyName="delta_h_abs"    type="num" /></th>
    <th><SortHeader label="ΔC"              keyName="delta_c"        type="num" /></th>
    <th><SortHeader label="ΔL"              keyName="delta_l"        type="num" /></th>
    <th><SortHeader label="Ct"           keyName="count"          type="num" /></th>
  </tr>
</thead>

          <tbody>
  {displayItems.length === 0 && !loading && (
    <tr><td colSpan={17} className="empty">No data yet. Adjust filters and Run.</td></tr>
  )}
  {displayItems.map((row, idx) => (
            
                <tr key={idx}>
                  <td className="col-hex">
                    <span
                      className="chip"
                      title={`#${row.anchor_hex6}`}
                      style={{
                        backgroundColor: hexToCss(row.anchor_hex6),
                        color: chipTextColorFromL(row.a_l)
                      }}
                    >
                      {row.anchor_hex6 || "—"}
                    </span>
                  </td>
                  <td>{row.a_h ?? ""}</td>
                  <td>{row.a_c ?? ""}</td>
                  <td>{row.a_l ?? ""}</td>
                  <td className="col-cat">{row.a_neutral_cats || ""}</td>
                  <td className="col-cat">{row.a_hue_cats || ""}</td>

                  <td className="col-hex">
                    <span
                      className="chip"
                      title={`#${row.friend_hex6}`}
                      style={{
                        backgroundColor: hexToCss(row.friend_hex6),
                        color: chipTextColorFromL(row.b_l)
                      }}
                    >
                      {row.friend_hex6 || "—"}
                    </span>
                  </td>
                  <td>{row.b_h ?? ""}</td>
                  <td>{row.b_c ?? ""}</td>
                  <td>{row.b_l ?? ""}</td>
                  <td className="col-cat">{row.b_neutral_cats || ""}</td>
                  <td className="col-cat">{row.b_hue_cats || ""}</td>

                  <td>{row.delta_h_signed ?? ""}</td>
                  <td>{row.delta_h_abs ?? ""}</td>
                  <td>{row.delta_c ?? ""}</td>
                  <td>{row.delta_l ?? ""}</td>
                  <td>{row.count ?? ""}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {/* Pager (only if server returns totals) */}
        {typeof totalPages === "number" && totalPages > 1 && (
          <div className="pager">
            <button
              className="btn btn--primary"
              onClick={() => setPage(p => Math.max(1, p - 1))}
              disabled={page <= 1 || loading}
            >
              ← Prev
            </button>
            <span className="spacer" />
            <button
              className="btn btn--primary"
              onClick={() => setPage(p => (p + 1))}
              disabled={loading || (totalPages && page >= totalPages)}
            >
              Next →
            </button>
          </div>
        )}
      </main>
    </div>
    </div>
  );
}
