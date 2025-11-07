import { useEffect, useMemo, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import PhotoRenderer from "@components/PhotoRenderer";
import { API_FOLDER } from "@helpers/config";
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import "./photopreview.css?v=3";


/* ---------- URL query helper ---------- */
function useQuery() {
  const { search } = useLocation();
  return useMemo(() => new URLSearchParams(search), [search]);
}

/* ---------- Embedded search bits (small, local) ---------- */
function SearchBar({ initialQ = "", initialTags = "", onSearch }) {
  const [q, setQ] = useState(initialQ);
  const [tagsText, setTagsText] = useState(initialTags);
  function submit() {
    onSearch && onSearch({ q: q.trim(), tagsText: tagsText.trim() });
  }
  return (
    <div className="photo-searchbar">
      <div className="psb-field">
        <label className="psb-label">Text</label>
        <input
          className="psb-input"
          type="text"
          placeholder="address, note, asset_id…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          onKeyDown={(e) => { if (e.key === "Enter") submit(); }}
        />
      </div>
      <div className="psb-field">
        <label className="psb-label">Tags</label>
        <input
          className="psb-input"
          type="text"
          placeholder="comma or | separated (e.g., adobe,white)"
          value={tagsText}
          onChange={(e) => setTagsText(e.target.value)}
          onKeyDown={(e) => { if (e.key === "Enter") submit(); }}
        />
      </div>
      <div className="psb-actions">
        <button className="psb-btn psb-primary" onClick={submit}>Search</button>
        <button
          className="psb-btn"
          onClick={() => {
            setQ("");
            setTagsText("");
            onSearch && onSearch({ q: "", tagsText: "" });
          }}
        >
          Clear
        </button>
      </div>
    </div>
  );
}

function PhotoGrid({ items = [], onPick, emptyText = "No results" }) {
  if (!items.length) return <div className="photo-grid-empty">{emptyText}</div>;
  return (
    <div className="photo-grid">
      {items.map((item) => (
        <div
          key={item.asset_id}
          className="photo-card"
          onClick={() => onPick && onPick(item)}
          role="button"
          tabIndex={0}
          onKeyDown={(e) => { if (e.key === "Enter") onPick && onPick(item); }}
        >
          <div className="photo-thumb-wrap">
            <img className="photo-thumb" src={item.thumb_url} alt="" />
          </div>
          <div className="photo-meta">
            <div className="photo-title">{item.title || item.asset_id}</div>
            <div className="photo-tags">
              {(item.tags || []).map((t) => (
                <span key={t} className="photo-tag">{t}</span>
              ))}
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}

/* ---------- Reusable controls panel ---------- */
function ControlsPanel({
  asset,
  selectedByRole,
  onPickColor,
  onApply,
  onClearAll,
}) {
  return (
    <div className="panel">
      <div className="panel-title">Assign Colors</div>
      <div className="actions">
        <button type="button" className="btn primary" onClick={onApply}>Apply Colors</button>
        <button type="button" className="btn" onClick={onClearAll}>Clear All</button>
      </div>

      {(asset.masks || []).map((m) => (
        <div className="row" key={m.role}>
          <div className="role-picker">
            <FuzzySearchColorSelect
              detail="swatch"
              onSelect={(c) => onPickColor(m.role, c)}
              label={m.role}
            />
          </div>
        </div>
      ))}

      <div className="actions">
        <button type="button" className="btn primary" onClick={onApply}>Apply Colors</button>
        <button type="button" className="btn" onClick={onClearAll}>Clear All</button>
      </div>
    </div>
  );
}

/* ---------- Page ---------- */
export default function AdminPhotoPreviewPage() {
  const query = useQuery();
  const navigate = useNavigate();
  const assetId = query.get("asset") || "";

  // search panel state
  const [findOpen, setFindOpen] = useState(!assetId); // open if no asset yet
  const [sLoading, setSLoading] = useState(false);
  const [sItems, setSItems] = useState([]);
  const [sTotal, setSTotal] = useState(0);
  const [sError, setSError] = useState("");
  const [sPage, setSPage] = useState(1);
  const [sQ, setSQ] = useState(query.get("q") || "");
  const [sTags, setSTags] = useState(query.get("tags") || "");

  // asset + assignments state
  const [loading, setLoading] = useState(false);
  const [asset, setAsset] = useState(null);
  const [error, setError] = useState("");
  const [assignments, setAssignments] = useState({});
  const [viewMode, setViewMode] = useState("after"); // before | after | prepared
  const [selectedByRole, setSelectedByRole] = useState({}); // role -> color

  // debug
  const [debugOpen, setDebugOpen] = useState(false);
  const [debugPayload, setDebugPayload] = useState(null);
  const [debugResponse, setDebugResponse] = useState(null);

  // responsive controls drawer
  const [controlsOpen, setControlsOpen] = useState(true);
  useEffect(() => {
    // default closed on small screens, open on large
    const mm = window.matchMedia("(max-width: 899px)");
    const setByMq = () => setControlsOpen(!mm.matches);
    setByMq();
    mm.addEventListener("change", setByMq);
    return () => mm.removeEventListener("change", setByMq);
  }, []);

  /* ---------- Search handlers ---------- */
  function doSearch({ q, tagsText }, p = 1) {
    setSLoading(true);
    setSError("");

    const params = new URLSearchParams();
    if (q) params.set("q", q);
    if (tagsText) params.set("tags", tagsText);
    params.set("page", String(p));
    params.set("limit", "24");

    fetch(`${API_FOLDER}/v2/photos/search.php?${params.toString()}`, {
      credentials: "include",
      headers: { Accept: "application/json" },
    })
      .then((r) => r.json())
      .then((data) => {
        if (data.error) {
          setSError(data.error);
          setSItems([]);
          setSTotal(0);
          return;
        }
        const items = (data.items || []).map((it) => ({
          ...it,
          thumb_url: it.thumb_url || "",
        }));
        setSItems(items);
        setSTotal(data.total || 0);
        setSPage(data.page || p);
        setSQ(q || "");
        setSTags(tagsText || "");

        const nav = new URLSearchParams();
        if (assetId) nav.set("asset", assetId);
        if (q) nav.set("q", q);
        if (tagsText) nav.set("tags", tagsText);
        nav.set("page", String(data.page || p));
        navigate(`/admin/photo-preview?${nav.toString()}`, { replace: true });
      })
      .catch((e) => setSError(e?.message || "Search failed"))
      .finally(() => setSLoading(false));
  }

  function onPick(item) {
    const nav = new URLSearchParams();
    nav.set("asset", item.asset_id);
    if (sQ) nav.set("q", sQ);
    if (sTags) nav.set("tags", sTags);
    navigate(`/admin/photo-preview?${nav.toString()}`);
    setFindOpen(false);
    setSError("");
    setError("");
  }

  /* ---------- Asset fetch ---------- */
  useEffect(() => {
    if (!assetId) { setAsset(null); return; }
    setLoading(true);
    setError("");
    fetch(`${API_FOLDER}/v2/photos/get.php?asset_id=${encodeURIComponent(assetId)}`, {
      credentials: "include",
      headers: { Accept: "application/json" },
    })
      .then((r) => r.json())
      .then((data) => {
        if (data.error) { setError(data.error); setAsset(null); return; }
        const normalized = {
          asset_id: data.asset_id,
          width: data.width,
          height: data.height,
          repairedUrl: data.repaired_url || "",
          preparedUrl: data.prepared_url || "",
          masks: Array.isArray(data.masks)
            ? data.masks.map((m) => ({ role: m.role, url: m.url }))
            : [],
          precomputed: data.precomputed || undefined,
        };
        setAsset(normalized);
        setSelectedByRole({});
        setAssignments({});
        setViewMode("after");
      })
      .catch((e) => setError(e?.message || "Failed to fetch asset"))
      .finally(() => setLoading(false));
  }, [assetId]);

  async function applyColors() {
    try {
      setError("");

      const entries = Object.entries(selectedByRole)
        .filter(([, obj]) => obj && (Number.isInteger(obj.color_id) || !Number.isNaN(parseInt(obj.color_id, 10))))
        .map(([role, obj]) => ({ role, id: Number(obj.color_id) }));

      if (!entries.length) {
        setAssignments({});
        setViewMode("after");
        setDebugPayload({ ids: [] });
        setDebugResponse({ note: "No roles selected" });
        return;
      }

      const uniqueIds = Array.from(new Set(entries.map((e) => e.id)));
      setDebugPayload({ ids: uniqueIds });

      const responses = await Promise.all(
        uniqueIds.map(async (id) => {
          const url = `${API_FOLDER}/v2/get-color.php?id=${id}`;
          try {
            const r = await fetch(url, { credentials: "include", headers: { Accept: "application/json" } });
            const raw = await r.text();
            let data;
            try { data = JSON.parse(raw); } catch { data = { ok: false, _raw: raw }; }
            return { id, url, status: r.status, data };
          } catch (e) {
            return { id, url, status: 0, data: { ok: false, _err: e?.message || "fetch failed" } };
          }
        })
      );

      setDebugResponse(responses);

      const byId = {};
      for (const { id, data } of responses) {
        if (data?.ok && data.color) {
          const c = data.color;
          byId[id] = {
            hex6: (c.hex6 || "").toUpperCase(),
            L: Number(c.lab_l ?? c.L ?? 0),
            a: Number(c.lab_a ?? c.a ?? 0),
            b: Number(c.lab_b ?? c.b ?? 0),
          };
        }
      }

      const next = {};
      const missing = [];
      for (const { role, id } of entries) {
        if (byId[id]) next[role] = byId[id];
        else missing.push(`${role} (#${id})`);
      }

      setAssignments(next);
      if (missing.length) {
        setError(`Not found: ${missing.join(", ")}`);
      } else {
        setError("");
      }
      setViewMode("after");
    } catch (e) {
      setError(e?.message || "Failed to apply colors");
      setDebugResponse({ ok: false, error: e?.message || "applyColors exception" });
    }
  }

  function onPickColor(role, c) {
    setSelectedByRole((prev) => ({ ...prev, [role]: normalizePick(c) }));
  }
  function clearRole(role) {
    setSelectedByRole((prev) => ({ ...prev, [role]: null }));
  }
  function normalizePick(obj) {
    return obj ? { ...obj, color_id: obj.id } : null;
  }

  /* ---------- Initial search if we arrived w/ q/tags ---------- */
  useEffect(() => {
    if (!assetId && (sQ || sTags)) doSearch({ q: sQ, tagsText: sTags }, sPage || 1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const totalPages = Math.max(1, Math.ceil(sTotal / 24));

  return (
    <div className="admin-photo-preview">
      {debugOpen && (
        <div className="debug-box">
          <div className="debug-title">get-color debug</div>
          <div className="debug-sub">Payload sent:</div>
          <pre>{JSON.stringify(debugPayload, null, 2) || "(none)"}</pre>
          <div className="debug-sub">Response:</div>
          <pre>{JSON.stringify(debugResponse, null, 2) || "(none)"}</pre>
        </div>
      )}

      <div className="title">Admin Photo Preview</div>
      <div className="app-bar">
        <div className="left">
          <button className="btn" onClick={() => setFindOpen(!findOpen)}>
            {findOpen ? "Hide Finder" : "Find Photo"}
          </button>
          <button className="btn" onClick={() => setDebugOpen(!debugOpen)}>
            {debugOpen ? "Hide Debug" : "Show Debug"}
          </button>
        </div>
        <div className="right">
          <div className="view-switch">
            <button
              className={"tab" + (viewMode === "before" ? " is-active" : "")}
              onClick={() => setViewMode("before")}
            >
              Before
            </button>
            <button
              className={"tab" + (viewMode === "prepared" ? " is-active" : "")}
              onClick={() => setViewMode("prepared")}
              title="Admin-only"
            >
              Prepared
            </button>
            <button
              className={"tab" + (viewMode === "after" ? " is-active" : "")}
              onClick={() => setViewMode("after")}
            >
              After
            </button>
          </div>
        </div>
      </div>

      {findOpen && (
        <div className="finder">
          <SearchBar
            initialQ={sQ}
            initialTags={sTags}
            onSearch={(payload) => doSearch(payload, 1)}
          />
          {sLoading && <div className="notice">Loading…</div>}
          {sError && <div className="error">{sError}</div>}
          <PhotoGrid items={sItems} onPick={onPick} emptyText="No photos matched." />
          {totalPages > 1 && (
            <div className="pager">
              <button
                className="btn"
                disabled={sPage <= 1}
                onClick={() => doSearch({ q: sQ, tagsText: sTags }, sPage - 1)}
              >
                Prev
              </button>
              <div className="page-info">{sPage} / {totalPages}</div>
              <button
                className="btn"
                disabled={sPage >= totalPages}
                onClick={() => doSearch({ q: sQ, tagsText: sTags }, sPage + 1)}
              >
                Next
              </button>
            </div>
          )}
        </div>
      )}

      <div className="content">
        {!assetId && !findOpen && <div className="notice">Use “Find Photo” to select an image.</div>}
        {loading && <div className="notice">Loading asset…</div>}
        {error && <div className="error">{error}</div>}

        {asset && (
          <div className="preview-grid">
            <div className="renderer-col">
              <PhotoRenderer asset={asset} assignments={assignments} viewMode={viewMode} />
            </div>

            {/* Controls drawer (acts as sidebar on desktop, bottom-sheet on mobile) */}
            <aside className={`controls-drawer ${controlsOpen ? "open" : ""}`} aria-hidden={!controlsOpen}>
              <div className="drawer-header">
                <div className="drawer-title">Assign Colors</div>
                <button className="drawer-close" onClick={() => setControlsOpen(false)} aria-label="Close controls">✕</button>
              </div>

              <ControlsPanel
                asset={asset}
                selectedByRole={selectedByRole}
                onPickColor={(role, c) => {
                  if (!c) { clearRole(role); return; }
                  onPickColor(role, c);
                }}
                onApply={applyColors}
                onClearAll={() => setSelectedByRole({})}
              />

              <div className="panel">
                <div className="panel-title">Notes</div>
              </div>
            </aside>

            {/* Floating action button (mobile only) */}
            <button
              className="fab"
              onClick={() => setControlsOpen(true)}
              aria-label="Open controls"
            >
             Colors
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
