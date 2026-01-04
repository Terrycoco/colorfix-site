import React, { useMemo, useRef, useState, useEffect } from "react";
import { createPortal } from "react-dom";
import { useLocation } from "react-router-dom";
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import CategoryDropdown from "@components/CategoryDropdown";
import { API_FOLDER } from "@helpers/config";
import PaletteInspector from "@components/PaletteInspector";
import AccordionSection from "./AccordionSection";
import PaletteRow from "./PaletteRow";
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
  apiPath = `${API_FOLDER}/browse-palettes.php`,
  onAddPalette,
}) {
  const location = useLocation();


  // Refs
  const pushedRef = useRef(false);
  const accHeaderRefs = useRef({});
  const lastScrollYRef = useRef(0);

  // Inputs (live)
  const [selectedColor, setSelectedColor] = useState(null);
  const [selectedFamily, setSelectedFamily] = useState(null);
  const [selectedLightness, setSelectedLightness] = useState(""); // "Light" | "Medium" | "Dark" | ""

  // Results (live)
  const [palettes, setPalettes] = useState([]);
  const [countsBySize, setCountsBySize] = useState({});
  const [totalCount, setTotalCount] = useState(0);

  // UI
  const [loading, setLoading] = useState(false);
  const [errorMsg, setErrorMsg] = useState("");
  const [hasRun, setHasRun] = useState(false);
  const [inspected, setInspected] = useState(null);

  // Size filter (checkboxes): 3, 4, 5+
  const [sizePicks, setSizePicks] = useState({
    three: true,
    four: true,
    fivePlus: false,
  });

  // Mode + sections
  const [mode, setMode] = useState("inline");
  const [sections, setSections] = useState({});
  const [loadingSections, setLoadingSections] = useState({});

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

  // Always open filters on mount / re-entry
  useEffect(() => {
    setCtrlOpen(true);
    try { sessionStorage.setItem(CTRL_KEY, "1"); } catch {}
  }, []);

  // Floating FAB shows whenever filters are closed on mobile (portal-rendered)
  const showFilterFab = isMobile() && !ctrlOpen;

  // Derived
  const hasColor = !!selectedColor?.cluster_id;
  const famLabel = selectedFamily?.name || null;
  const hasFamily = !!selectedFamily?.name;
  const hasLightness = !!selectedLightness;

  // Rule: Color OR (Family AND Lightness) — Lightness not required for neutrals

 const goEnabled = hasColor || (hasFamily && hasLightness);
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
    setMode(snap.mode || "inline");
    setPalettes(snap.palettes || []);
    setSections(snap.sections || {});
    setCountsBySize(snap.countsBySize || {});
    setTotalCount(snap.totalCount || 0);
    setSelectedColor(snap.selectedColor || null);
    setSelectedFamily(snap.selectedFamily || null);
    setSelectedLightness(snap.selectedLightness || "");
    setHasRun(!!snap.hasRun);
    setApplied(snap);

    requestAnimationFrame(() => {
      const y = Number(sessionStorage.getItem("browseScrollY") || "0");
      if (y) window.scrollTo(0, y);
    });
  }, []);

  // Applied vs live
  const appliedColor = applied?.selectedColor || null;
  const appliedFamLabel = applied?.selectedFamily?.name || null;
  const appliedLight = applied?.selectedLightness || "";

  const controlsMatchApplied =
    (appliedColor?.cluster_id ?? null) ===
      (selectedColor?.cluster_id ?? null) &&
    (appliedFamLabel ?? null) === (famLabel ?? null) &&
    (appliedLight ?? "") === (selectedLightness ?? "");

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
    const c = appliedColor?.name;
    const f = appliedFamLabel;
    const l = appliedLight;
    if ((applied?.totalCount || 0) > 0) return "";
    if (c && f && l)
      return `No palettes featuring ${c} and ${f} • ${l}. Try widening your search.`;
    if (c && f)
      return `No palettes featuring ${c} and ${f}. Try adding Light/Medium/Dark.`;
    if (f && l)
      return `No palettes in ${f} • ${l}. Try removing lightness or family.`;
    if (c) return `No palettes featuring ${c}. Try by family + lightness instead.`;
    return `No palettes yet. Pick a color or a family + lightness and tap Go.`;
  }, [applied?.hasRun, applied?.totalCount, appliedColor?.name, appliedFamLabel, appliedLight]);

  const nothingFound =
    !!applied?.hasRun && !loading && !errorMsg && Number(applied?.totalCount || 0) === 0;

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

    // anchor color
    if (color?.cluster_id) payload.exact_anchor_cluster_id = Number(color.cluster_id);

    // include_idea (family + optional lightness)
    const idea = {};
    const famName = (family?.name || "").trim();
    const famType = family?.type || "hue";
    const tone    = (lightnessOverride ?? selectedLightness) || "";

    // Treat "Show All" as no family filter
    const isShowAll = !famName || /^show all$/i.test(famName);

    if (!isShowAll) {
      if (famType === "neutral") idea.neutral_cats = famName;
      else                       idea.hue_cats     = famName;
    }

    // Lightness is optional, but if user picked it, send it
    if (tone) idea.lightness_cats = tone;

    if (Object.keys(idea).length) payload.include_idea = idea;

    return payload;
  }

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
      return {
        palette_id: p.palette_id ?? p.id ?? idx,
        size,
        members,
        meta: p.meta || {},
      };
    });
  }

  // Fetch main (robust)
  async function runSearch() {
    if (!goEnabled) return;

    setLoading(true);
    setErrorMsg("");

    const sentColor   = selectedColor || null;
    const sentFamily  = selectedFamily || null;
    const sentPayload = buildPayload(sentColor, sentFamily);
    console.log('payload:', sentPayload);
    const url         = `${apiPath}${apiPath.includes("?") ? "&" : "?"}_=${Date.now()}`;

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
        setPalettes([]); setCountsBySize({}); setTotalCount(0);
        setMode("inline"); setSections({});
        saveSnapshot({
          mode: "inline",
          palettes: [],
          sections: {},
          countsBySize: {},
          totalCount: 0,
          selectedColor: sentColor,
          selectedFamily: sentFamily,
          selectedLightness,
          hasRun: false,
        });
        return;
      }

      let json;
      try { json = raw ? JSON.parse(raw) : null; }
      catch { setErrorMsg("Bad JSON from server"); return; }
      if (!json) { setErrorMsg("Empty response from server"); return; }

      const items  = Array.isArray(json) ? json : (json.items || []);
      const counts = Array.isArray(json) ? {}  : (json.counts_by_size || {});
      const total  = Array.isArray(json) ? items.length : (json.total_count || items.length || 0);

      setCountsBySize(counts);
      setTotalCount(total);

      const pageLimit    = Number((json && json.limit) ?? sentPayload.limit ?? 60);
      const countsValues = Object.values(counts || {}).map(Number);
      const overPage     = total > pageLimit || countsValues.some((n) => n > pageLimit);

      if (overPage) {
        // Switch to accordion, but keep any visible inline content until the first section fills.
        setMode("accordion");

        const sizesToShow = [3, 4, 5, 6, 7]
          .filter((s) => isSizeSelected(s))
          .filter((s) => {
            const v = counts[s] ?? counts[String(s)] ?? 0;
            return Number(v || 0) > 0;
          });

        const seed = {};
        for (const s of sizesToShow) seed[s] = { items: [], nextOffset: 0, open: false };
        setSections(seed);

        saveSnapshot({
          mode: "accordion",
          palettes, // keep whatever is on screen until first section loads
          sections: seed,
          countsBySize: counts,
          totalCount: total,
          selectedColor: sentColor,
          selectedFamily: sentFamily,
          selectedLightness,
          hasRun: true,
        });

        if (sizesToShow.length > 0) {
          const firstSize = sizesToShow[0];

          // Open first section and immediately reflect a loading state
          setSections((s) => ({ ...s, [firstSize]: { ...(s[firstSize] || {}), open: true } }));
          setLoadingSections((m) => ({ ...m, [firstSize]: true }));

          // Kick the load on next tick so applied/snapshot state settles
          setTimeout(() => {
            loadSection(firstSize, 0).finally(() => {
              // Once first section has attempted to load, it's safe to clear old list
              setPalettes([]);
            });
          }, 0);
        }
      } else {
        setMode("inline");
        const normalized = normalizeItems(items);
        setPalettes(normalized);
        setSections({});
        saveSnapshot({
          mode: "inline",
          palettes: normalized,
          sections: {},
          countsBySize: counts,
          totalCount: total,
          selectedColor: sentColor,
          selectedFamily: sentFamily,
          selectedLightness,
          hasRun: true,
        });
      }

      // MOBILE: auto-collapse filters only when we actually have results
      if (isMobile() && Number(total) > 0) {
        setCtrlOpen(false);
        try { sessionStorage.setItem(CTRL_KEY, "0"); } catch {}
      }

      setHasRun(true);
    } catch (err) {
      if (err?.name === "AbortError") {
        setErrorMsg("Request timed out. Try again.");
      } else {
        setErrorMsg("Could not load palettes.");
        console.error("fetch error", err);
      }
      setPalettes([]); setCountsBySize({}); setTotalCount(0);
      setMode("inline"); setSections({});
      saveSnapshot({
        mode: "inline",
        palettes: [],
        sections: {},
        countsBySize: {},
        totalCount: 0,
        selectedColor: null,
        selectedFamily: null,
        selectedLightness: "",
        hasRun: false,
      });
    } finally {
      clearTimeout(timeoutId);
      setLoading(false);
    }
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

  // Scroll helpers
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

  // Accordion helpers (APPLIED context)
  async function loadSection(size, offset = 0) {
    saveScroll();
    if (loadingSections[size]) return; // guard double-click
    setLoadingSections((m) => ({ ...m, [size]: true }));

    try {
      const payload = buildPayload(
        applied?.selectedColor || null,
        applied?.selectedFamily || null,
        { size_min: size, size_max: size, limit: 48, offset },
        applied?.selectedLightness || selectedLightness || null
      );

      const r = await fetch(
        `${apiPath}${apiPath.includes("?") ? "&" : "?"}_=${Date.now()}`,
        {
          method: "POST",
          cache: "no-store",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload),
        }
      );

      if (!r.ok) {
        const rawErr = await r.text().catch(() => "");
        console.error("loadSection error", r.status, rawErr);
        return;
      }

      const j = await r.json();
      const page = Array.isArray(j) ? j : (j.items || []);
      const next = Array.isArray(j) ? null : (j.next_offset ?? null);
      const totalForSize = Array.isArray(j) ? page.length : (j.total_count ?? page.length);

      setSections((s) => {
        const prev   = s[size]?.items || [];
        const merged = offset ? [...prev, ...normalizeItems(page)] : normalizeItems(page);
        const nextOff = next != null ? next : (merged.length < totalForSize ? merged.length : null);

        const updated = {
          ...s,
          [size]: { items: merged, nextOffset: nextOff, open: true },
        };

        // Preserve counts/total in snapshot so headers don't disappear
        saveSnapshot({
          ...(applied || {}),
          mode: "accordion",
          palettes: [],
          sections: updated,
          countsBySize: (applied && applied.countsBySize) || countsBySize,
          totalCount:   (applied && applied.totalCount)   || totalCount,
          hasRun: true,
        });

        return updated;
      });
    } finally {
      setLoadingSections((m) => ({ ...m, [size]: false }));
      restoreScroll();
    }
  }

  function toggleSection(size) {
    saveScroll();
    setSections((prev) => {
      const sec = prev[size] || { items: [], nextOffset: null, open: false };
      const nextOpen = !sec.open;
      const next = { ...prev, [size]: { ...sec, open: nextOpen } };
      if (nextOpen && sec.items.length === 0) {
        setTimeout(() => loadSection(size, 0), 0);
      } else {
        restoreScroll();
      }
      return next;
    });
  }

function toggleSize(key) {
  setSizePicks((s) => ({ ...s, [key]: !s[key] }));
  clearSnapshot();
  setHasRun(false);
}

  // Helper: is a given size allowed by the current picks?
  function isSizeSelected(size) {
    if (size === 3 && sizePicks.three) return true;
    if (size === 4 && sizePicks.four) return true;
    if (size >= 5 && sizePicks.fivePlus) return true;
    return false;
  }

  function loadMore(size) {
    const sec = sections[size] || {};
    let off = sec.nextOffset;
    if (off == null) off = (sec.items?.length || 0);
    if (off != null && !loadingSections[size]) loadSection(size, off);
  }

  // Handlers (clear snapshot on filter changes)
  function handlePickColor(colorOrNull) {
    setSelectedColor(colorOrNull || null);
    clearSnapshot();
    setHasRun(false);
    if (isMobile()) setCtrlOpen(true); // stay open while adjusting
  }
  function handlePickFamily(cat) {
    setHasRun(false);
    if (!cat || /^show all$/i.test(String(cat.name || ""))) {
      setSelectedFamily(null);
      clearSnapshot();
      if (isMobile()) setCtrlOpen(true);
      return;
    }
    const name = String(cat.name || "");
    const type = cat.type === "neutral" ? "neutral" : "hue";
    setSelectedFamily({ name, type });
    clearSnapshot();
    if (isMobile()) setCtrlOpen(true);
  }
  function handlePickLightness(e) {
    setSelectedLightness(e.target.value || "");
    clearSnapshot();
    setHasRun(false);
    if (isMobile()) setCtrlOpen(true);
  }

  // ---- Safe counters and grouping ----
  const countFor = (size) => {
    const a = applied?.countsBySize;
    const live = countsBySize;
    const fromApplied = a && (a[size] ?? a[String(size)]);
    const fromLive    = live && (live[size] ?? live[String(size)]);
    if (fromApplied != null) return Number(fromApplied);
    if (fromLive != null)    return Number(fromLive);
    return palettes.filter((p) => p.size === size).length; // inline fallback
  };

  const groups = useMemo(() => {
    const g = {};
    for (const p of palettes) (g[p.size] ||= []).push(p);
    return g;
  }, [palettes]);

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
        {/* Collapsible content */}
        <div id="bpv1-control-panels" className="bpv1-control-panels">
          {/* row 1 */}
          <div className="bpv1-toolbar">
            <div className="bpv1-field">
              <FuzzySearchColorSelect mobileBreakpoint={0} onSelect={handlePickColor} />
            </div>
            <div className="bpv1-field">
              <CategoryDropdown onSelect={handlePickFamily} useShowAll={true} />
            </div>
            <div className="bpv1-field">
              <select
                className="bpv1-select"
                value={selectedLightness}
                onChange={handlePickLightness}
                aria-label="Lightness"
              >
                <option value="">Lightness…</option>
                <option value="Light">Light</option>
                <option value="Medium">Medium</option>
                <option value="Dark">Dark</option>
              </select>
            </div>
          </div>

          {/* row 2 */}
          <div className="bpv1-toolbar bpv1-toolbar--row2">
            <label>Palette Size</label>
           <div className="bpv1-field bpv1-sizes">
  <label className="bpv1-sizechk">
    <input
      type="checkbox"
      checked={!!sizePicks.three}
      onChange={() => toggleSize("three")}
    /> 3
  </label>
  <label className="bpv1-sizechk">
    <input
      type="checkbox"
      checked={!!sizePicks.four}
      onChange={() => toggleSize("four")}
    /> 4
  </label>
  <label className="bpv1-sizechk">
    <input
      type="checkbox"
      checked={!!sizePicks.fivePlus}
      onChange={() => toggleSize("fivePlus")}
    /> 5+
  </label>
</div>

            <div className="bpv1-actions">
              <button className="bpv1-go" onClick={runSearch} disabled={!goEnabled || loading} type="button">
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

      {/* Results */}
      {mode === "inline" ? (
        <div className="bpv1-results">
          {[3, 4, 5, 6, 7]
            .filter((s) => isSizeSelected(s))
            .filter((s) => (groups[s]?.length || 0) > 0)
            .map((size) => (
              <section key={size} className="bpv1-group">
                <h4 className="bpv1-group-title">
                  {size}-color palettes{" "}
                  <span className="bpv1-count">{countFor(size).toLocaleString()}</span>
                </h4>
                <div className="bpv1-group-list">
                  {groups[size].map((p, idx) => (
                    <PaletteRow
                      key={p.palette_id ?? idx}
                      palette={p}
                      onClick={() => openInspector(p)}
                    />
                  ))}
                </div>
              </section>
            ))}
        </div>
      ) : (
        <div className="bpv1-results">
          {[3, 4, 5, 6, 7]
            .filter((size) => isSizeSelected(size))
            .filter((size) => Number(countFor(size)) > 0)
            .map((size) => (
              <AccordionSection
                key={size}
                size={size}
                count={countFor(size)}
                items={sections[size]?.items || []}
                open={!!sections[size]?.open}
                loading={!!loadingSections[size]}
                onToggle={() => toggleSection(size)}
                loadMore={loadMore}
                nextOffset={sections[size]?.nextOffset}
                setInspected={openInspector}
                accHeaderRef={(el) => { accHeaderRefs.current[size] = el || undefined; }}
              />
            ))}
        </div>
      )}

      {inspected && (
        <PaletteInspector
          palette={inspected}
          onClose={closeInspector}
          onTranslate={(p) => {
            console.log("Translate this palette to Dunn-Edwards:", p);
          }}
          topOffset={56}
        />
      )}
    </div>
  );
}
