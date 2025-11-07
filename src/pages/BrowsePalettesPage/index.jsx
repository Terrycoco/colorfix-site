import React, { useMemo, useRef, useState, useEffect } from "react";
import { createPortal } from "react-dom";
import { useLocation } from "react-router-dom";
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import CategoryDropdown from "@components/CategoryDropdown";
import { API_FOLDER } from "@helpers/config";
import PaletteInspector from "@components/PaletteInspector";
import PaletteRow from "./PaletteRow";
import TagMultiSelect from '@components/TagMultiSelect';
import "./browse-palettes.css";

// Mobile helper
const isMobile = () =>
  typeof window !== "undefined" &&
  (window.matchMedia?.("(max-width: 600px)").matches ||
    window.matchMedia?.("(pointer: coarse)").matches);

/** Portal FAB so it's always fixed to the viewport on iOS (no sticky glitches) */
function FilterFabPortal({ show, onClick }) {
  if (!show || typeof document === "undefined") return null;
  return createPortal(
    <button
      type="button"
      className="bpv1-filters-fab"
      onClick={onClick}
      aria-label="Show Filters"
    >
      ▾ Show Filters
    </button>,
    document.body
  );
}

export default function BrowsePalettesPage({
  apiPath = `${API_FOLDER}/v2/browse-palettes.php`,
  onAddPalette,
}) {
  const location = useLocation();

  // Parse URL once (hot entry)
  const qs0 = new URLSearchParams(location.search || "");
  const hotClusterIds = (qs0.get("clusters") || "")
    .split(",")
    .map((s) => parseInt(s, 10))
    .filter((n) => Number.isFinite(n) && n > 0);
  // Default for Browse: include_close ON unless URL explicitly says 0
  const [includeClose, setIncludeClose] = useState(qs0.get("include_close") !== "0");

  // Refs
  const pushedRef = useRef(false);
  const lastScrollYRef = useRef(0);
  // Section refs are keyed by `${group}-${size}`, e.g., "exact-4", "near-5"
  const sectionRefs = useRef({});

  // Inputs (live)
  const [selectedColor, setSelectedColor] = useState(null);
  const [selectedFamily, setSelectedFamily] = useState(null);
  const [selectedLightness, setSelectedLightness] = useState(""); // "Light" | "Medium" | "Dark" | ""

  // Results (live, inline)
  const [palettes, setPalettes] = useState([]);
  const [countsBySize, setCountsBySize] = useState({});
  const [totalCount, setTotalCount] = useState(0);
  const [nextOffset, setNextOffset] = useState(null);

  // UI
  const [loading, setLoading] = useState(false);
  const [errorMsg, setErrorMsg] = useState("");
  const [hasRun, setHasRun] = useState(false);
  const [inspected, setInspected] = useState(null);

  const [selectedTags, setSelectedTags] = useState([]);
const [tagModeAll, setTagModeAll] = useState(false); // false = ANY, true = ALL

  // Size filter (checkboxes): 3, 4, 5+
  const [sizePicks, setSizePicks] = useState({
    three: true,
    four: true,
    fivePlus: false,
  });

  // Snapshot (applied search)
  const [applied, setApplied] = useState(null);
  const SNAP_KEY = "bpv1-browse-snapshot-v1";

  function saveSnapshot(snap) {
    setApplied(snap);
    try { sessionStorage.setItem(SNAP_KEY, JSON.stringify(snap)); } catch {}
  }
  function loadSnapshot() {
    try {
      const raw = sessionStorage.getItem(SNAP_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch { return null; }
  }
  function clearSnapshot() {
    setApplied(null);
    try { sessionStorage.removeItem(SNAP_KEY); } catch {}
  }

  // Controls open/closed
  const CTRL_KEY = "bpv1-controls-open";
  const [ctrlOpen, setCtrlOpen] = useState(true);
  useEffect(() => {
    setCtrlOpen(true);
    try { sessionStorage.setItem(CTRL_KEY, "1"); } catch {}
  }, []);
  const showFilterFab = isMobile() && !ctrlOpen;

  // Derived
  const hasColor = !!selectedColor?.cluster_id;
  const famLabel = selectedFamily?.name || null;
  const hasFamily = !!selectedFamily?.name;
  const hasLightness = !!selectedLightness;
  const goEnabled = hasColor || selectedTags.length > 0;

  // Back/forward closes modal
  useEffect(() => {
    function onPopState() {
      if (pushedRef.current) {
        pushedRef.current = false;
        setInspected(null);
        restoreScroll();
      }
    }
    window.addEventListener("popstate", onPopState);
    return () => window.removeEventListener("popstate", onPopState);
  }, []);

  // One-shot restore after inspector
  useEffect(() => {
    const snap = loadSnapshot();
    const shouldRestore = sessionStorage.getItem("bpv1-restore-once") === "1";
    if (!snap || !shouldRestore) {
      clearSnapshot();
      return;
    }

    sessionStorage.removeItem("bpv1-restore-once");
    setPalettes(snap.palettes || []);
    setCountsBySize(snap.countsBySize || {});
    setTotalCount(snap.totalCount || 0);
    setNextOffset(snap.nextOffset ?? null);
    setSelectedColor(snap.selectedColor || null);
    setSelectedFamily(snap.selectedFamily || null);
    setSelectedLightness(snap.selectedLightness || "");
    setHasRun(!!snap.hasRun);

    requestAnimationFrame(() => {
      const y = Number(sessionStorage.getItem("browseScrollY") || "0");
      if (y) window.scrollTo(0, y);
    });
  }, []);

  const appliedColor = applied?.selectedColor || null;
  const appliedFamLabel = applied?.selectedFamily?.name || null;
  const appliedLight = applied?.selectedLightness || "";

  const countsLine = useMemo(() => {
    const cbs = applied?.countsBySize || {};
    if (!cbs || !Object.keys(cbs).length) return "";
    const order = [3, 4, 5, 6, 7];
    return order
      .filter((n) => (cbs[n] || cbs[String(n)]) > 0)
      .map((n) => {
        const c = cbs[n] ?? cbs[String(n)];
        return `${n}-color (${Number(c).toLocaleString()})`;
      })
      .join(" • ");
  }, [applied?.countsBySize]);

  const emptyMsg = useMemo(() => {
    if (!applied?.hasRun)
      return "No palettes yet. Pick a color or a family + lightness and tap Go.";
    if ((applied?.totalCount || 0) > 0) return "";
    const c = appliedColor?.name;
    const f = appliedFamLabel;
    const l = appliedLight;
    if (c && f && l)
      return `No palettes featuring ${c} and ${f} • ${l}. Try widening your search.`;
    if (c && f)
      return `No palettes featuring ${c} and ${f}. Try adding Light/Medium/Dark.`;
    if (f && l)
      return `No palettes in ${f} • ${l}. Try removing lightness or family.`;
    if (c) return `No palettes featuring ${c}. Try by family + lightness instead.`;
    return `No palettes yet. Pick a color or a family + lightness and tap Go.`;
  }, [applied?.hasRun, applied?.totalCount, appliedColor?.name, appliedFamLabel, appliedLight]);

  // Normalize + keep backend grouping metadata
  function normalizeItems(items) {
    return (items || []).map((p, idx) => {
      let members = [];
      if (Array.isArray(p.members)) {
        members = p.members;
      } else if (typeof p.member_pairs === "string") {
        members = p.member_pairs.split(",").map((pair) => {
          const [cid, hex] = pair.split(":");
          return {
            cluster_id: Number(cid),
            rep_hex: hex ? (hex.startsWith("#") ? hex : `#${hex}`) : null,
          };
        });
      } else if (Array.isArray(p.member_cluster_ids)) {
        members = p.member_cluster_ids.map((cid) => ({
          cluster_id: Number(cid),
          rep_hex: null,
        }));
      }
      const size =
        p.size ||
        (Array.isArray(p.members) && p.members.length) ||
        (Array.isArray(p.member_cluster_ids) && p.member_cluster_ids.length) ||
        members.length || 0;

      // NEW: pass through backend tags
      const group = (p.group === "near" || p.group === "exact") ? p.group : "exact";
      const displayAnchorClusterIds = Array.isArray(p.display_anchor_cluster_ids)
        ? p.display_anchor_cluster_ids.map((n) => Number(n))
        : null;
      const neighborsUsedDetail = Array.isArray(p.neighbors_used_detail) ? p.neighbors_used_detail : [];

      return {
        palette_id: p.palette_id ?? p.id ?? idx,
        size,
        members,
        group,
        display_anchor_cluster_ids: displayAnchorClusterIds,
        neighbors_used_detail: neighborsUsedDetail,
        meta: p.meta || {},
      };
    });
  }

  async function runSearch(overrides = null) {
    if (!overrides && !goEnabled) return;

    setLoading(true);
    setErrorMsg("");

    const sentColor  = selectedColor || null;
    const sentFamily = selectedFamily || null;

    const basePayload = buildPayload(sentColor, sentFamily);
    const sentPayload = overrides ? { ...basePayload, ...overrides } : basePayload;

    // Canonical boolean for TierB: include_close
    sentPayload.include_close = (overrides && typeof overrides.include_close === "boolean")
      ? overrides.include_close
      : includeClose;

    const url = `${apiPath}${apiPath.includes("?") ? "&" : "?"}_=${Date.now()}`;
    const controller = new AbortController();
    const timeoutId  = setTimeout(() => controller.abort(), 15000);

    try {
      const resp = await fetch(url, {
        method: "POST",
        cache: "no-store",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(sentPayload),
        signal: controller.signal,
      });

      const raw = await resp.text();
      if (!resp.ok) {
        setErrorMsg(`HTTP ${resp.status}`);
        setPalettes([]); setCountsBySize({}); setTotalCount(0); setNextOffset(null);
        saveSnapshot({
          palettes: [],
          countsBySize: {},
          totalCount: 0,
          nextOffset: null,
          selectedColor: sentColor,
          selectedFamily: sentFamily,
          selectedLightness,
          hasRun: false,
          include_close: sentPayload.include_close,
          exact_anchor_cluster_ids: sentPayload.exact_anchor_cluster_ids || [],
          ...(overrides || {}),
        });
        return;
      }

      let json;
      try { json = raw ? JSON.parse(raw) : null; } catch { setErrorMsg("Bad JSON from server"); return; }
      if (!json) { setErrorMsg("Empty response from server"); return; }

      const items  = Array.isArray(json) ? json : (json.items || []);
      const counts = Array.isArray(json) ? {}  : (json.counts_by_size || {});
      const total  = Array.isArray(json) ? items.length : (json.total_count || items.length || 0);
      const next   = Array.isArray(json) ? null : (json.next_offset ?? null);

      const normalized = normalizeItems(items);

      setCountsBySize(counts);
      setTotalCount(total);
      setPalettes(normalized);
      setNextOffset(next);

      if (isMobile() && Number(total) > 0) {
        setCtrlOpen(false);
        try { sessionStorage.setItem(CTRL_KEY, "0"); } catch {}
      }

      setHasRun(true);

      // Save snapshot
      saveSnapshot({
        palettes: normalized,
        countsBySize: counts,
        totalCount: total,
        nextOffset: next,
        selectedColor: sentColor,
        selectedFamily: sentFamily,
        selectedLightness,
        hasRun: true,
        include_close: sentPayload.include_close,
        exact_anchor_cluster_ids: sentPayload.exact_anchor_cluster_ids || [],
        ...(overrides || {}),
      });

    } catch (err) {
      setErrorMsg(err?.name === "AbortError" ? "Request timed out. Try again." : "Could not load palettes.");
      setPalettes([]); setCountsBySize({}); setTotalCount(0); setNextOffset(null);
      saveSnapshot({
        palettes: [],
        countsBySize: {},
        totalCount: 0,
        nextOffset: null,
        selectedColor: null,
        selectedFamily: null,
        selectedLightness: "",
        hasRun: false,
        include_close: (overrides && typeof overrides.include_close === "boolean") ? overrides.include_close : includeClose,
        ...(overrides || {}),
      });
    } finally {
      clearTimeout(timeoutId);
      setLoading(false);
    }
  }

  async function loadMoreInline() {
    if (nextOffset == null || loading) return;
    setLoading(true);
    setErrorMsg("");

    const basePayload = buildPayload(
      applied?.selectedColor || selectedColor,
      applied?.selectedFamily || selectedFamily,
      {
        limit: 60,
        offset: nextOffset
      },
      applied?.selectedLightness || selectedLightness
    );

    const overrides = {};
    if (Array.isArray(applied?.exact_anchor_cluster_ids)) {
      overrides.exact_anchor_cluster_ids = applied.exact_anchor_cluster_ids;
    }
    overrides.include_close =
      typeof applied?.include_close === "boolean" ? applied.include_close : includeClose;

    const sentPayload = { ...basePayload, ...overrides };

    try {
      const resp = await fetch(`${apiPath}${apiPath.includes("?") ? "&" : "?"}_=${Date.now()}`, {
        method: "POST",
        cache: "no-store",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(sentPayload),
      });

      if (!resp.ok) {
        setErrorMsg(`HTTP ${resp.status}`);
        return;
      }

      const json = await resp.json();
      const items = Array.isArray(json) ? json : (json.items || []);
      const next  = Array.isArray(json) ? null : (json.next_offset ?? null);

      const merged = [...palettes, ...normalizeItems(items)];
      setPalettes(merged);
      setNextOffset(next);

      saveSnapshot({
        ...(applied || {}),
        palettes: merged,
        nextOffset: next,
        hasRun: true,
        include_close: sentPayload.include_close,
      });
    } finally {
      setLoading(false);
    }
  }

  // Add this in BrowsePalettesPage (top-level component scope)
function handleMetaPatched(delta) {
  // Update the open inspector palette
  setInspected((prev) => (prev ? { ...prev, meta: { ...(prev.meta || {}), ...delta } } : prev));

  // Update the list row so the chips reflect immediately
  setPalettes((prev) =>
    prev.map((p) =>
      p.palette_id === (inspected?.palette_id || 0)
        ? { ...p, meta: { ...(p.meta || {}), ...delta } }
        : p
    )
  );

  // Also update the snapshot so a back/forward restore shows the new meta
  saveSnapshot({
    ...(applied || {}),
    palettes: (palettes || []).map((p) =>
      p.palette_id === (inspected?.palette_id || 0)
        ? { ...p, meta: { ...(p.meta || {}), ...delta } }
        : p
    ),
  });
}


  // HOT entry: /browse-palettes?clusters=123,456&include_close=1
  useEffect(() => {
    const qs = new URLSearchParams(location.search || "");
    const clustersCsv  = qs.get("clusters") || "";
    const includeCloseParam = qs.get("include_close"); // "1" | "0" | null

    const clusterIds = clustersCsv
      .split(",")
      .map((s) => parseInt(s, 10))
      .filter((n) => Number.isFinite(n) && n > 0);

    if (clusterIds.length === 0) return;

    // HOT: show all sizes, close filter panel
    setSizePicks({ three: true, four: true, fivePlus: true });
    setCtrlOpen(false);
    try { sessionStorage.setItem(CTRL_KEY, "0"); } catch {}

    const inc = includeCloseParam === "0" ? false : true;
    setIncludeClose(inc);

    runSearch({
      exact_anchor_cluster_ids: clusterIds,
      include_close: inc,
    });
  }, []); // once

  function buildPayload(
    color = selectedColor,
    family = selectedFamily,
    overrides = {},
    lightnessOverride = null
  ) {
    // derive size_min/max from checkboxes
    let sizeMin = 3, sizeMax = 7;
    if (sizePicks.three && !sizePicks.four && !sizePicks.fivePlus) { sizeMin = 3; sizeMax = 3; }
    else if (!sizePicks.three && sizePicks.four && !sizePicks.fivePlus) { sizeMin = 4; sizeMax = 4; }
    else if (!sizePicks.three && !sizePicks.four && sizePicks.fivePlus) { sizeMin = 5; sizeMax = 7; }
    else {
      const chosen = [];
      if (sizePicks.three) chosen.push(3);
      if (sizePicks.four) chosen.push(4);
      if (sizePicks.fivePlus) chosen.push(5, 6, 7);
      sizeMin = Math.min(...chosen);
      sizeMax = Math.max(...chosen);
    }

    const payload = {
      size_min: sizeMin,
      size_max: sizeMax,
      include_counts: true,
      limit: 60,
      offset: 0,
      ...overrides,
    };

    // anchor clusters: prefer selected color's cluster; else hot IDs from URL
    if (color?.cluster_id) {
      payload.exact_anchor_cluster_ids = [Number(color.cluster_id)];
    } else if (hotClusterIds.length) {
      payload.exact_anchor_cluster_ids = hotClusterIds;
    }

    // include_idea (family + optional lightness)
    const idea = {};
    const famName = (family?.name || "").trim();
    const famType = family?.type || "hue";
    const tone    = (lightnessOverride ?? selectedLightness) || "";
    const isShowAll = !famName || /^show all$/i.test(famName);

    if (!isShowAll) {
      if (famType === "neutral") idea.neutral_cats = famName;
      else                       idea.hue_cats     = famName;
    }
    if (tone) idea.lightness_cats = tone;

    if (Object.keys(idea).length) payload.include_idea = idea;
    if (selectedTags.length > 0) {
  if (tagModeAll) payload.include_tags_all = selectedTags;
  else payload.include_tags_any = selectedTags;
}

    return payload;
  }

  // Selected sizes helper
  function isSizeSelected(size) {
    if (size === 3 && sizePicks.three) return true;
    if (size === 4 && sizePicks.four) return true;
    if (size >= 5 && sizePicks.fivePlus) return true;
    return false;
  }

  // Anchor count → heading pluralization
  const anchorCount =
    (applied?.exact_anchor_cluster_ids?.length ?? 0) ||
    (selectedColor?.cluster_id ? 1 : (hotClusterIds?.length || 0));
  const colorWord = anchorCount > 1 ? "Colors" : "Color";
  const colorWordLower = anchorCount > 1 ? "colors" : "color";

  // Bucket by group → size
  const grouped = useMemo(() => {
    const acc = { exact: {}, near: {} };
    for (const p of palettes) {
      const g = p.group === "near" ? "near" : "exact";
      (acc[g][p.size] ||= []).push(p);
    }
    // Ensure stable sort order inside each size bucket
    for (const g of ["exact", "near"]) {
      for (const size of Object.keys(acc[g])) {
        acc[g][size].sort((a, b) => (a.palette_id ?? 0) - (b.palette_id ?? 0));
      }
    }
    return acc;
  }, [palettes]);

  // Count for a given size (keeps your existing behavior)
  const countFor = (size) => {
    const a = applied?.countsBySize;
    const live = countsBySize;
    const fromApplied = a && (a[size] ?? a[String(size)]);
    const fromLive    = live && (live[size] ?? live[String(size)]);
    if (fromApplied != null) return Number(fromApplied);
    if (fromLive != null)    return Number(fromLive);
    // fallback: total across both groups for this size
    const exactLen = (grouped.exact[size]?.length || 0);
    const nearLen  = (grouped.near[size]?.length  || 0);
    return exactLen + nearLen;
  };

  // Jump-to prefers exact group for that size, else near
  function jumpTo(size) {
    const keyExact = `exact-${size}`;
    const keyNear  = `near-${size}`;
    const el = sectionRefs.current[keyExact] || sectionRefs.current[keyNear];
    if (!el) return;
    const y = el.getBoundingClientRect().top + window.scrollY - 56;
    window.scrollTo({ top: y, behavior: "smooth" });
  }

  // Scroll helpers for inspector
  function saveScroll() {
    lastScrollYRef.current =
      window.pageYOffset ||
      document.documentElement.scrollTop ||
      document.body.scrollTop ||
      0;
  }
  function restoreScroll() {
    const y = lastScrollYRef.current || 0;
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        window.scrollTo(0, y);
      });
    });
  }

  // Modal open/close
  function openInspector(p) {
    saveScroll();
    sessionStorage.setItem("browseScrollY", String(window.scrollY));
    setInspected(p);
    sessionStorage.setItem("bpv1-restore-once", "1");
    if (!pushedRef.current) {
      window.history.pushState({ inspector: true, pid: p.palette_id }, "", `#palette-${p.palette_id}`);
      pushedRef.current = true;
    }
  }
  function closeInspector() {
    if (pushedRef.current) {
      window.history.back();
    } else {
      setInspected(null);
      restoreScroll();
    }
  }

  function toggleSize(key) {
    setSizePicks((s) => ({ ...s, [key]: !s[key] }));
    clearSnapshot();
    setHasRun(false);
  }

  return (
    <div className="bpv1-page">
      {/* Viewport-level FAB (portal) — only on mobile + when filters are closed */}
      <FilterFabPortal
        show={showFilterFab}
        onClick={() => {
          setCtrlOpen(true);
          try { sessionStorage.setItem(CTRL_KEY, "1"); } catch {}
          window.scrollTo({ top: 0, behavior: "smooth" });
        }}
      />

      <h3>Browse Designer Palettes</h3>

      <div className={`bpv1-controls ${ctrlOpen ? "is-open" : "is-closed"}`}>
        <div id="bpv1-control-panels" className="bpv1-control-panels">
          {/* row 1 */}
    
<div className="bpv1-toolbar">
  <div className="bpv1-field">
    <FuzzySearchColorSelect
      onSelect={(c) => { setSelectedColor(c || null); clearSnapshot(); setHasRun(false); }}
    />
  </div>

  <div className="bpv1-field">
    <TagMultiSelect
      selected={selectedTags}
      onChange={(tags) => { setSelectedTags(tags); clearSnapshot(); setHasRun(false); }}
    />
  </div>

  <div className="bpv1-field">
    <label style={{ display: "flex", alignItems: "center", gap: 6 }}>
      <input
        type="checkbox"
        checked={tagModeAll}
        onChange={(e) => setTagModeAll(e.target.checked)}
      />
      match all tags
    </label>
  </div>
</div>

          {/* row 2 */}
          <div className="bpv1-toolbar bpv1-toolbar--row2">
            <label>Palette Size</label>
            <div className="bpv1-field bpv1-sizes">
              <label className="bpv1-sizechk">
                <input type="checkbox" checked={!!sizePicks.three} onChange={() => toggleSize("three")} /> 3
              </label>
              <label className="bpv1-sizechk">
                <input type="checkbox" checked={!!sizePicks.four} onChange={() => toggleSize("four")} /> 4
              </label>
              <label className="bpv1-sizechk">
                <input type="checkbox" checked={!!sizePicks.fivePlus} onChange={() => toggleSize("fivePlus")} /> 5+
              </label>
            </div>

            <div className="bpv1-actions">
              <button className="bpv1-go" onClick={() => runSearch()} disabled={!goEnabled || loading} type="button">
                {loading ? "Searching…" : "Go"}
              </button>
            </div>
          </div>
        </div>

        {/* Desktop toggle (below panels). Hidden on mobile; mobile uses FAB. */}
        <button
          type="button"
          className="bpv1-controls-toggle"
          aria-controls="bpv1-control-panels"
          aria-expanded={ctrlOpen}
          onClick={() => {
            setCtrlOpen((v) => {
              const next = !v;
              try { sessionStorage.setItem(CTRL_KEY, next ? "1" : "0"); } catch {}
              return next;
            });
          }}
        >
          <span className="bpv1-controls-caret" aria-hidden="true" />
          <span className="bpv1-controls-label">
            {ctrlOpen ? "Hide Filters" : "Show Filters"}
          </span>
        </button>
      </div>

      {/* Error */}
      {errorMsg && (
        <div className="bpv1-error" role="alert">
          {errorMsg}
        </div>
      )}

      {/* Summary + Jump-to */}
      {(applied?.hasRun || hasRun) && totalCount > 0 && (
        <div className="bpv1-summary" style={{ display: "flex", alignItems: "center", gap: 12, flexWrap: "wrap" }}>
          <span>Showing results</span>
          {countsLine && <span className="bpv1-summary-counts"> · {countsLine}</span>}
          <button
            className="bpv1-summary-edit"
            type="button"
            onClick={() => {
              setCtrlOpen(true);
              try { sessionStorage.setItem(CTRL_KEY, "1"); } catch {}
              window.scrollTo({ top: 0, behavior: "smooth" });
            }}
          >
            Edit filters
          </button>

          {/* Jump-to toolbar */}
          <div className="bpv1-jumpto" style={{display:'flex',gap:'8px',flexWrap:'wrap',marginLeft:'auto'}}>
            <span style={{opacity:0.7}}>Jump to:</span>
            {[3,4,5,6,7]
              .filter((s) => isSizeSelected(s))
              .filter((s) => countFor(s) > 0)
              .map((s) => (
                <button
                  key={s}
                  type="button"
                  className="bpv1-go"
                  onClick={() => jumpTo(s)}
                  style={{padding:'6px 10px'}}
                  aria-label={`Jump to ${s}-color sections`}
                >
                  {s}-color ({countFor(s).toLocaleString()})
                </button>
              ))
            }
          </div>
        </div>
      )}

      {/* Empty state */}
      {(!loading && (applied?.hasRun || hasRun) && totalCount === 0) && (
        <div className="bpv1-empty">{emptyMsg}</div>
      )}

      {/* Results: grouped by EXACT vs NEAR, then by SIZE */}
      <div className="bpv1-results">
      {["exact", "near"].map((groupKey) => {
          // Only render the group if it has at least one selected-size bucket with items
          const sizes = [3,4,5,6,7].filter((s) => isSizeSelected(s) && (grouped[groupKey][s]?.length || 0) > 0);
          if (!sizes.length) return null;

          const groupTitle =
            groupKey === "exact"
              ? `Palettes With Your ${colorWord}`
              : `Palettes With Close Matches to Your ${colorWord}`;

          // If exact group is empty but near exists, we could optionally show a one-liner above near.
          // Keeping it simple per your note.

          return (
            <section key={`group-${groupKey}`} className="bpv1-group">
              <h3 className="bpv1-group-title" style={{marginTop:24}}>
                {groupTitle}
              </h3>

              {sizes.map((size) => (
                <section
                  key={`${groupKey}-${size}`}
                  id={`bpv1-${groupKey}-size-${size}`}
                  ref={(el) => { sectionRefs.current[`${groupKey}-${size}`] = el || undefined; }}
                  className="bpv1-group"
                >
                  <h4 className="bpv1-group-title">
                    {size}-color palettes{" "}
                    <span className="bpv1-count">
                      {(grouped[groupKey][size]?.length || 0).toLocaleString()}
                    </span>
                  </h4>
                  <div className="bpv1-group-list">
                    {(grouped[groupKey][size] || []).map((p, idx) => (
                      <PaletteRow
                        key={p.palette_id ?? `${groupKey}-${size}-${idx}`}
                        palette={p}
                        onClick={() => openInspector(p)}
                      />
                    ))}
                  </div>
                </section>
              ))}
            </section>
          );
        })}

        {/* Inline pagination */}
        {nextOffset != null && (
          <div className="bpv1-loadmore" style={{textAlign:'center', margin:'16px 0 8px'}}>
            <button
              type="button"
              className="bpv1-go"
              disabled={loading}
              onClick={loadMoreInline}
            >
              {loading ? "Loading…" : "Load more"}
            </button>
          </div>
        )}
      </div>

      {/* Inspector */}
      {inspected && (
        <PaletteInspector
          palette={inspected}
          onClose={closeInspector}
          onPatched={handleMetaPatched} 
          onTranslate={(p) => {
            console.log("Translate this palette to Dunn-Edwards:", p);
          }}
          topOffset={56}
        />
      )}
    </div>
  );
}
