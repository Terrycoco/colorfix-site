import React, { useEffect, useRef, useState } from "react";
import { API_FOLDER as API } from "@helpers/config";

const PASS_MS = 3500;
const MAX_K = 6;
const SAFETY = 200;

async function j(url, { timeoutMs = 8000 } = {}) {
  const ac = new AbortController();
  const to = setTimeout(() => ac.abort(), timeoutMs);
  try {
    const r = await fetch(url, { credentials: "same-origin", signal: ac.signal });
    if (!r.ok) throw new Error(`HTTP ${r.status}`);
    return await r.json();
  } finally {
    clearTimeout(to);
  }
}

function isLightHex(h) {
  if (!h || h.length < 6) return false;
  const r = parseInt(h.slice(0, 2), 16),
    g = parseInt(h.slice(2, 4), 16),
    b = parseInt(h.slice(4, 6), 16);
  return 0.2126 * r + 0.7152 * g + 0.0722 * b > 210;
}

export default function PaletteViewerPage() {
  // search state (type-ahead)
  const [q, setQ] = useState("");
  const [brand, setBrand] = useState("");
  const [hits, setHits] = useState([]);
  const [brands, setBrands] = useState([]);
  const [searching, setSearching] = useState(false);

  // chosen color (pivot)
  const [pivot, setPivot] = useState(null); // {cluster_id, rep_hex, label}

  // generator + results
  const [gen, setGen] = useState({ running: false, exhausted: false, pass: 0, inserted: 0, msg: "" });
  const [items, setItems] = useState([]);
  const [error, setError] = useState("");

  // filters
  const cats = ["", "White", "Black", "Gray", "Greige", "Beige", "Brown", "Magenta", "Red", "Orange", "Yellow", "Green", "Cyan", "Blue", "Purple"];
  const fams = cats;
  const [mustA, setMustA] = useState("");
  const [mustB, setMustB] = useState("");
  const [cat, setCat] = useState("");

  const stop = useRef(false);

  // ---------- type-ahead color search (works as before) ----------
  async function runSearch() {
    const qv = (q || "").trim();
    if (qv.length < 2) {
      setHits([]);
      setBrands([]);
      return;
    }
    setSearching(true);
    try {
      const url = `${API}/cluster-find.php?q=${encodeURIComponent(qv)}` + (brand ? `&brand=${encodeURIComponent(brand)}` : "");
      const data = await j(url);
      setHits(data?.items || []);
      setBrands(data?.brands || []);
    } catch {
      setHits([]);
      setBrands([]);
    } finally {
      setSearching(false);
    }
  }

  useEffect(() => {
    // live-update the dropdown as you type / toggle brand
    if (!q || q.trim().length < 2) {
      setHits([]);
      setBrands([]);
      return;
    }
    setSearching(true);
    const t = setTimeout(runSearch, 250);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q, brand]);

  function pick(r) {
    const label = r.brand && r.name ? `${r.name} — ${r.brand}` : r.name || r.rep_hex;
    setPivot({ cluster_id: r.cluster_id, rep_hex: r.rep_hex, label });
    setHits([]); // close dropdown
    // clear prior status/results
    setGen({ running: false, exhausted: false, pass: 0, inserted: 0, msg: "" });
    setItems([]);
    setError("");
  }

  // ---------- manual generator + fetch palettes (triggered by Search when a pivot is chosen) ----------
  async function generateAndLoad() {
    if (!pivot) return;

    setItems([]);
    setError("");
    setGen({ running: true, exhausted: false, pass: 0, inserted: 0, msg: "Generating…" });
    stop.current = false;

    let pass = 0,
      total = 0,
      exhausted = false;

    while (!stop.current && pass < SAFETY) {
      pass += 1;
      setGen((g) => ({ ...g, pass, msg: `Pass ${pass}…` }));

      let res;
      try {
        res = await j(
          `${API}/palette-controller.php?pivot=${pivot.cluster_id}&max_k=${MAX_K}&time_budget_ms=${PASS_MS}`
        );
      } catch (e) {
        setGen({ running: false, exhausted: false, pass, inserted: total, msg: String(e.message || e) });
        return;
      }
      total += Number(res?.palettes_inserted || 0);
      exhausted = !!res?.exhausted || !!res?.skipped;

      setGen({
        running: !exhausted,
        exhausted,
        pass,
        inserted: total,
        msg: exhausted ? "Exhausted" : `+${res?.palettes_inserted || 0} palettes`,
      });

      if (exhausted) break;
      await new Promise((r) => setTimeout(r, 150));
    }

    try {
      const reqFamilies = [mustA, mustB].filter(Boolean).join(",");
      let url = `${API}/palettes-list.php?member=${pivot.cluster_id}&sizes=3,4,5,6&limit=100&offset=0`;
      if (reqFamilies) url += `&with_all=${encodeURIComponent(reqFamilies)}`; // AND filter: e.g. White,Black
      if (cat) url += `&with=${encodeURIComponent(cat)}`; // optional single include
      const data = await j(url);
      setItems(data?.items || []);
    } catch (e) {
      setError(String(e.message || e));
    }
  }

  // ---------- detail modal ----------
  const [openId, setOpenId] = useState(null);
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);

  async function openDetail(id) {
    if (!pivot) return;
    setOpenId(id);
    setDetail(null);
    setDetailLoading(true);
    try {
      const d = await j(`${API}/palette-get.php?id=${id}&pivot=${pivot.cluster_id}`);
      setDetail(d);
    } catch (e) {
      setDetail({ ok: false, error: String(e.message || e) });
    } finally {
      setDetailLoading(false);
    }
  }

  // ---------- UI ----------
  return (
    <div
      style={{
        padding: "18px",
        color: "#e6edf3",
        background: "#0b1017",
        minHeight: "100vh",
        fontFamily: "system-ui, -apple-system, Segoe UI, Roboto, Helvetica",
      }}
    >
      <h1 style={{ margin: "0 0 12px" }}>Palette Viewer</h1>

      {/* top row: color finder + run search (which generates if a pivot exists) */}
      <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
          <input
          value={q}
          onChange={(e) => setQ(e.target.value)}
          onKeyDown={(e) => { if (e.key === "Enter") { pivot ? generateAndLoad() : runSearch(); } }}
          onFocus={() => { if (pivot && (!hits || hits.length === 0)) runSearch(); }}   // <— reopen list on click
          placeholder="Search color name or hex…"
          style={{
            flex: "0 0 360px",
            padding: "10px 12px",
            borderRadius: 10,
            border: "1px solid #243041",
            background: "#0f141d",
            color: "#c7d1dd",
          }}
        />
        <button
          onClick={() => {
            if (pivot) generateAndLoad();
            else runSearch();
          }}
          disabled={gen.running || (!pivot && (!q.trim() || searching))}
          title={pivot ? "Generate palettes for this color" : "Find colors by name/brand"}
          style={{
            padding: "10px 14px",
            borderRadius: 10,
            border: "1px solid #243041",
            background: "#2a3a55",
            color: "#e6edf3",
            cursor: "pointer",
          }}
        >
          Search
        </button>

        {/* requirements (set before pressing Search) */}
        <div style={{ display: "flex", alignItems: "center", gap: 6, marginLeft: 12 }}>
          <span style={{ fontSize: 12, color: "#8ea5be" }}>Must include</span>
          <select
            value={mustA}
            onChange={(e) => setMustA(e.target.value)}
            disabled={!pivot || gen.running}
            style={{ padding: "8px 10px", borderRadius: 8, background: "#0f141d", color: "#c7d1dd", border: "1px solid #243041" }}
            title={!pivot ? "Pick a color first" : undefined}
          >
            {fams.map((f) => (
              <option key={f || "Any"} value={f}>
                {f || "Any"}
              </option>
            ))}
          </select>
          <span style={{ fontSize: 12, color: "#8ea5be" }}>and</span>
          <select
            value={mustB}
            onChange={(e) => setMustB(e.target.value)}
            disabled={!pivot || gen.running}
            style={{ padding: "8px 10px", borderRadius: 8, background: "#0f141d", color: "#c7d1dd", border: "1px solid #243041" }}
            title={!pivot ? "Pick a color first" : undefined}
          >
            {fams.map((f) => (
              <option key={f || "Any2"} value={f}>
                {f || "Any"}
              </option>
            ))}
          </select>
        </div>

        {/* optional extra include */}
        <div style={{ marginLeft: "auto", display: "flex", alignItems: "center", gap: 8 }}>
          <label style={{ fontSize: 12, color: "#8ea5be" }}>Also includes</label>
          <select
            value={cat}
            disabled={!pivot || gen.running}
            onChange={(e) => setCat(e.target.value)}
            title={!pivot ? "Pick a color first" : undefined}
            style={{ padding: "8px 10px", borderRadius: 8, background: "#0f141d", color: "#c7d1dd", border: "1px solid #243041" }}
          >
            {cats.map((x) => (
              <option key={x || "Any"} value={x}>
                {x || "Any"}
              </option>
            ))}
          </select>
        </div>
      </div>

      {/* brand facets (click facet, then press Search again to refresh hits) */}
      {brands.length > 0 && (
        <div style={{ marginTop: 8, display: "flex", flexWrap: "wrap", gap: 6 }}>
          <button
            onClick={() => setBrand("")}
            style={{
              padding: "6px 10px",
              borderRadius: 999,
              border: "1px solid #243041",
              background: brand ? "#0f141d" : "#162031",
              color: "#d3dde9",
              cursor: "pointer",
            }}
          >
            All
          </button>
          {brands.map((b) => (
            <button
              key={b.brand || "Unknown"}
              onClick={() => setBrand(b.brand)}
              title={`${b.brand} (${b.hits})`}
              style={{
                padding: "6px 10px",
                borderRadius: 999,
                border: "1px solid #243041",
                background: brand === b.brand ? "#2a3a55" : "#0f141d",
                color: "#d3dde9",
                cursor: "pointer",
              }}
            >
              {b.brand} <span style={{ opacity: 0.7, marginLeft: 6 }}>{b.hits}</span>
            </button>
          ))}
        </div>
      )}

      {/* search dropdown (choose pivot) */}
      {!!hits.length && (
        <div
          style={{
            marginTop: 8,
            width: 640,
            background: "#0f141d",
            border: "1px solid #243041",
            borderRadius: 10,
            overflow: "hidden",
          }}
        >
          {hits.map((r) => (
            <button
              key={`${r.cluster_id}-${r.rep_hex}-${r.brand || ""}-${r.name || ""}`}
              type="button"
              onClick={() => pick(r)}
              style={{
                display: "flex",
                alignItems: "center",
                gap: 10,
                width: "100%",
                textAlign: "left",
                padding: "8px 10px",
                background: "transparent",
                border: "0",
                color: "#d3dde9",
                cursor: "pointer",
              }}
            >
              <span
                title={`#${r.rep_hex}`}
                style={{ width: 18, height: 18, borderRadius: 4, background: `#${r.rep_hex}`, border: "1px solid #243041" }}
              />
              <span style={{ flex: 1 }}>{r.brand ? `${r.name} — ${r.brand}` : r.name}</span>
              <span style={{ opacity: 0.65, fontSize: 12 }}>#{r.cluster_id}</span>
            </button>
          ))}
        </div>
      )}

      {/* pivot header + status */}
      <div style={{ marginTop: 16, display: "flex", alignItems: "center", gap: 12 }}>
        {pivot ? (
          <>
            <span
              title={`#${pivot.rep_hex}`}
              style={{ width: 24, height: 24, borderRadius: 6, background: `#${pivot.rep_hex}`, border: "1px solid #243041" }}
            />
            <div>
              <div style={{ fontWeight: 600 }}>{pivot.label}</div>
              <div style={{ fontSize: 12, color: "#8ea5be" }}>cluster #{pivot.cluster_id}</div>
            </div>
            <div
              style={{
                marginLeft: 12,
                padding: "8px 10px",
                border: "1px solid #243041",
                borderRadius: 10,
                background: "#0f141d",
                display: (gen.running || gen.pass > 0) ? "flex" : "none",
                alignItems: "center",
                gap: 10,
              }}
            >
              <strong>Generator:</strong>
              <span>{gen.exhausted ? "Exhausted — cache complete" : gen.running ? "Working…" : "Idle"}</span>
              {gen.pass > 0 && <span>· pass {gen.pass}</span>}
              {gen.inserted > 0 && <span>· +{gen.inserted}</span>}
            </div>
          </>
        ) : (
          <span style={{ color: "#8ea5be" }}>Pick a color, set requirements, then press Search.</span>
        )}
      </div>

      {/* list */}
      {error && <div style={{ marginTop: 12, color: "#ff8a8a" }}>{error}</div>}
      <div style={{ marginTop: 16, display: "grid", gap: 12 }}>
        {pivot &&
          items.map((it) => (
            <button
              key={it.id}
              type="button"
              onClick={() => openDetail(it.id)}
              style={{
                display: "flex",
                justifyContent: "space-between",
                alignItems: "center",
                padding: "10px 12px",
                border: "1px solid #243041",
                borderRadius: 12,
                background: "#0f141d",
                cursor: "pointer",
                textAlign: "left",
                width: "100%",
              }}
            >
              <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
                {(it.hexes || "")
                  .split(",")
                  .slice(0, 8)
                  .map((h, i) => {
            
                    return (
                      <span
                        key={i}
                        title={`#${h}`}
                        style={{
                          width: 96,
                          height: 96,
                          borderRadius: 12,
                          background: `#${h}`,
                          border: "1px solid #243041"
         
                        }}
                      />
                    );
                  })}
              </div>
              <div style={{ display: "flex", flexDirection: "column", gap: 6, minWidth: 110, alignItems: "flex-end" }}>
                <div style={{ fontSize: 12, color: "#8ea5be" }}>#{it.id}</div>
                <div>{it.size}-color</div>
                <div style={{ fontSize: 12, color: "#8ea5be" }}>
                  🎨 {it.chromatic_count} · ◻︎ {it.neutral_count}
                </div>
                <div style={{ fontSize: 12, color: "#8ea5be" }}>
                  {Number(it.has_white) ? "W" : ""} {Number(it.has_black) ? "B" : ""}
                </div>
              </div>
            </button>
          ))}
        {pivot && !items.length && gen.exhausted && (
          <div style={{ color: "#8ea5be" }}>No palettes matched (try adjusting requirements).</div>
        )}
      </div>

      {/* detail modal */}
      {openId && (
        <div
          role="dialog"
          aria-modal="true"
          onClick={() => setOpenId(null)}
          style={{
            position: "fixed",
            inset: 0,
            background: "rgba(0,0,0,.6)",
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            zIndex: 1000,
          }}
        >
          <div
            onClick={(e) => e.stopPropagation()}
            style={{
              width: "min(960px, 92vw)",
              maxHeight: "85vh",
              overflow: "auto",
              background: "#0f141d",
              border: "1px solid #243041",
              borderRadius: 14,
              padding: 16,
            }}
          >
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 12 }}>
              <h2 style={{ margin: 0 }}>Palette #{openId}</h2>
              <button
                onClick={() => setOpenId(null)}
                style={{ background: "transparent", border: 0, color: "#c7d1dd", fontSize: 18, cursor: "pointer" }}
              >
                ✕
              </button>
            </div>

            {detailLoading && <div style={{ color: "#8ea5be" }}>Loading…</div>}

            {detail && detail.ok && (
              <div style={{ display: "grid", gap: 12 }}>
                {detail.members.map((m, i) => {
                  const hex = m?.swatch?.hex6 || m.rep_hex;
                  const brand = m?.swatch?.brand || "";
                  const name = m?.swatch?.name || m.rep_hex;
                  return (
                    <div
                      key={i}
                      style={{
                        display: "flex",
                        gap: 12,
                        alignItems: "center",
                        border: "1px solid #243041",
                        borderRadius: 10,
                        padding: 10,
                      }}
                    >
                      <div
                        style={{
                          width: 120,
                          height: 120,
                          borderRadius: 12,
                          background: `#${hex}`,
                          border: "1px solid #243041"
                      
                        }}
                      />
                      <div>
                        <div style={{ fontWeight: 600 }}>{name}</div>
                        <div style={{ color: "#8ea5be" }}>{brand}</div>
                        <div style={{ color: "#8ea5be" }}>
                          #{hex} · cluster {m.cluster_id}
                          {m?.color_id ? ` · color ${m.color_id}` : ""}
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            )}

            {detail && !detail.ok && <div style={{ color: "#ff8a8a" }}>{detail.error || "Failed to load palette."}</div>}
          </div>
        </div>
      )}
    </div>
  );
}
