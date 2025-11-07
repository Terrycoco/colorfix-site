import { useState, useEffect, useRef, useMemo } from "react";
import { useNavigate } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import { useAppState } from "@context/AppStateContext";
import PaletteSwatch from "@components/Swatches/PaletteSwatch";
import SwatchGallery from "@components/SwatchGallery";
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import "./mypalette.css";

/* ---------- Helpers ---------- */
async function fetchJsonStrict(url) {
  const res = await fetch(url, { cache: "no-store" });
  const text = await res.text();
  if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 300)}`);
  try {
    return JSON.parse(text);
  } catch {
    throw new Error(`Invalid JSON: ${text.slice(0, 300)}`);
  }
}

function buildBrandsQS(searchFilters) {
  const src = (searchFilters?.brands ?? searchFilters?.brand) ?? [];
  let arr = [];
  if (Array.isArray(src)) arr = src;
  else if (src instanceof Set) arr = Array.from(src);
  else if (typeof src === "string" && src) arr = src.split(",");
  const codes = Array.from(
    new Set(arr.map((s) => String(s || "").trim().toLowerCase()).filter(Boolean))
  );
  return codes.length ? `&brands=${encodeURIComponent(codes.join(","))}` : "";
}

/* ---------- Component ---------- */
export default function MyPalettePage() {
  const {
    palette,
    addToPalette,
    clearPalette,
    setShowPalette,
    searchFilters,
    brandFiltersAppliedSeq,
  } = useAppState();

  // Compute active brand codes (supports .brands or .brand; array/Set/string)
  const brandsRaw = (searchFilters && (searchFilters.brands ?? searchFilters.brand)) ?? [];
  let brandsList = [];
  if (Array.isArray(brandsRaw)) brandsList = brandsRaw;
  else if (brandsRaw instanceof Set) brandsList = Array.from(brandsRaw);
  else if (typeof brandsRaw === "string" && brandsRaw) brandsList = brandsRaw.split(",");
// Live brand codes (supports .brands/.brand; array/Set/string)
const activeBrandCodes = useMemo(() => {
  const raw = (searchFilters && (searchFilters.brands ?? searchFilters.brand)) ?? [];
  let list = [];
  if (Array.isArray(raw)) list = raw;
  else if (raw instanceof Set) list = Array.from(raw);
  else if (typeof raw === 'string' && raw) list = raw.split(',');
  return Array.from(
    new Set(list.map(s => String(s || '').trim().toLowerCase()).filter(Boolean))
  );
}, [searchFilters]);

  // Live-filter palette on every render (no extra state/effect)
  const srcPalette = Array.isArray(palette) ? palette : [];
 const filteredPalette = activeBrandCodes.length
  ? srcPalette.filter(sw => {
      const b = (sw?.brand ?? sw?.color?.brand ?? '').toString().trim().toLowerCase();
      return activeBrandCodes.includes(b);
    })
  : srcPalette;

  const isEmpty = palette.length === 0;
  const navigate = useNavigate();

  // Results (friends / neutrals / similar / opposite)
  const [friends, setFriends] = useState([]);
  const [loading, setLoading] = useState(false);
  const [noResultsFound, setNoResultsFound] = useState(false);
  const [friendsMode, setFriendsMode] = useState("colors"); // 'colors' | 'neutrals' | 'all'

  // neighbor toggle + metadata returned by backend (per-anchor lines)
  const [includeNeighbors, setIncludeNeighbors] = useState(true);
  const [neighborsUsed, setNeighborsUsed] = useState(null);

  // Endpoint + tolerance + mode memory (for re-runs)
  const [currentEndpoint, setCurrentEndpoint] = useState(null);
  const [currentTol, setCurrentTol] = useState(null);
  const endpointRef = useRef(currentEndpoint);
  const tolRef = useRef(null);
  const modeRef = useRef(friendsMode);

  // Similar / Opposite tolerance inputs
  const [tolSame, setTolSame] = useState(0);
  const [tolOpp, setTolOpp] = useState(0);

  const heroRef = useRef(null);

  const CLEAR_ON = ["/v2/get-friends.php"];

  const selectAll = (e) => requestAnimationFrame(() => e.target.select());
  const keepSelection = (e) => e.preventDefault();

  // Anchor color IDs for queries (id, not cluster_id)
  const anchorColorIds = useMemo(() => {
    const arr = Array.isArray(palette) ? palette : [];
    const ids = arr
      .map((s) => s?.id ?? s?.color?.id ?? null)
      .map((v) => (v == null ? null : Number(v)))
      .filter((v) => Number.isFinite(v) && v > 0);
    return Array.from(new Set(ids));
  }, [palette]);

  async function runQuery(endpoint, tol = null, mode = null) {
    setLoading(true);
    setNoResultsFound(false);

    try {
      if (anchorColorIds.length === 0) {
        setFriends([]);
        setNoResultsFound(true);
        setNeighborsUsed(null);
        setLoading(false);
        return;
      }

      const isHue = /get-(similar-hue|opposite-hue)\.php$/.test(endpoint);
      const isFriends = /\/v2\/get-friends\.php$/.test(endpoint);

      const qs = new URLSearchParams();
      qs.set("ids", isHue ? String(anchorColorIds[0]) : anchorColorIds.join(","));
      if (isHue && tol != null) qs.set("tol", String(tol));
      if (isFriends) {
        const modeToSend = mode ?? friendsMode;
        qs.set("mode", modeToSend);
      }
      qs.set("_cb", String(Date.now())); // cache-buster

      const brandsQS = buildBrandsQS(searchFilters);
      const neighborQS = isFriends && includeNeighbors ? "&include_neighbors=1" : "";
      const url = `${API_FOLDER}/${endpoint}?${qs.toString()}${brandsQS}${neighborQS}`;

      const data = await fetchJsonStrict(url);
      const rows = Array.isArray(data?.items) ? data.items : (Array.isArray(data) ? data : []);

      setFriends(rows);
      setNoResultsFound(rows.length === 0);
      setNeighborsUsed(data && typeof data === "object" ? data.neighbors_used || null : null);
    } catch {
      setFriends([]);
      setNoResultsFound(true);
      setNeighborsUsed(null);
    } finally {
      setLoading(false);
    }
  }

  const neighborAddendum = useMemo(() => {
    if (!neighborsUsed) return [];
    const lines = [];
    for (const item of (Array.isArray(palette) ? palette : [])) {
      const anchorId = Number(item?.color?.id ?? item?.id ?? 0);
      if (!anchorId) continue;
      const list = neighborsUsed[anchorId];
      if (!Array.isArray(list) || list.length === 0) continue;
      const anchorName = item?.color?.name ?? item?.name ?? `Color ${anchorId}`;

      const chips = list.map((n, idx) => ({
        key: `${anchorId}-${n.cluster_id || n.color_id || idx}`,
        id: Number(n?.color_id ?? 0),
        label: n?.name
          ? `${n.name}${n.brand ? " (" + n.brand + ")" : ""}`
          : n?.hex || `#${n?.cluster_id ?? ""}`,
        hex: n?.hex || n?.rep_hex || null,
        fg: n?.fg || null,
        de: n?.de ?? n?.delta_e2000 ?? null,
      }));

      lines.push({ anchorName, chips });
    }
    return lines;
  }, [neighborsUsed, palette]);

  function runAndRemember(endpoint, tol = null, mode = null) {
    setCurrentEndpoint(endpoint);
    setCurrentTol(tol);
    if (mode) {
      setFriendsMode(mode);
      modeRef.current = mode;
    }
    return runQuery(endpoint, tol, mode ?? modeRef.current);
  }

  // keep refs in sync so effects can re-run with the latest state
  useEffect(() => {
    endpointRef.current = currentEndpoint;
  }, [currentEndpoint]);
  useEffect(() => {
    tolRef.current = currentTol;
  }, [currentTol]);
  useEffect(() => {
    modeRef.current = friendsMode;
  }, [friendsMode]);

  useEffect(() => {
  const ep = endpointRef.current;
  if (!ep) return;             // nothing has been run yet
  runQuery(ep, tolRef.current, modeRef.current);
}, [activeBrandCodes.join(',')]);

  // jump to top on first mount
  useEffect(() => {
    const el = document.getElementById("palette-hero") || document.getElementById("top");
    el?.scrollIntoView({ behavior: "smooth", block: "start" });
  }, []);

  // re-run current query when brand filters change (preserve mode!)
  useEffect(() => {
    const ep = endpointRef.current;
    if (!ep) return;
    runQuery(ep, tolRef.current, modeRef.current);
  }, [brandFiltersAppliedSeq]);

  // Sticky bar visibility (off on this page)
  useEffect(() => {
    setShowPalette(false);
    const target = document.getElementById("sticky-sentinel") || heroRef.current;
    if (!target) return;
    const BAR_H = 70;
    const io = new IntersectionObserver(
      ([entry]) => setShowPalette(!entry.isIntersecting),
      { root: null, rootMargin: `-${BAR_H}px 0px 0px 0px`, threshold: 0 }
    );
    io.observe(target);
    return () => io.disconnect();
  }, [setShowPalette]);

  /* ---------- Handlers ---------- */


  function onChipClick(chip) {
    if (chip?.id) navigate(`/color/${chip.id}`);
  }

  function handleTranslate() {
    const ids = [];
    const seen = new Set();
    for (const item of (Array.isArray(palette) ? palette : [])) {
      const sw = item?.color ?? item;
      const cid = Number(sw?.cluster_id ?? sw?.clusterId ?? 0);
      if (cid > 0 && !seen.has(cid)) {
        seen.add(cid);
        ids.push(cid);
      }
    }
    if (ids.length === 0) return;
    const params = new URLSearchParams();
    params.set("clusters", ids.join(","));
    navigate(`/palette/translate?${params.toString()}`);
  }

  function handleClear() {
    setFriends([]);
    clearPalette();
  }

  function onFuzzyPick(item) {
    if (!item?.id) return;
    addToPalette(item);
    requestAnimationFrame(() => {
      heroRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
    });
  }

  function onResultsPick(item) {
    if (!item?.id) return;
    const ep = endpointRef.current || "";
    const shouldClear = CLEAR_ON.some((s) => ep.endsWith(s));
    if (shouldClear) {
      setFriends([]);
      requestAnimationFrame(() => {
        heroRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });
      });
    }
  }

  const handleFriends = () => runAndRemember("/v2/get-friends.php", null, "colors");
  const handleNeutrals = () => runAndRemember("/v2/get-friends.php", null, "neutrals");
  const handleSimilar = () => runAndRemember("get-similar-hue.php", tolSame);
  const handleOpposites = () => runAndRemember("get-opposite-hue.php", tolOpp);

  const handleBrowse = () => {
    const arr = Array.isArray(palette) ? palette : [];
    const clusterIds = Array.from(
      new Set(
        arr
          .map(s => Number(s?.cluster_id ?? s?.color?.cluster_id))
          .filter(n => Number.isFinite(n) && n > 0)
      )
    );

    if (clusterIds.length === 0) {
      navigate("/browse-palettes");
      return;
    }

    const url = new URL("/browse-palettes", window.location.origin);
    url.searchParams.set("clusters", clusterIds.join(","));
    url.searchParams.set("include_close", "1");

    navigate(url.pathname + url.search);
  };

  /* ---------- Render ---------- */
  return (
    <div className="mypage">
      <div id="palette-hero" ref={heroRef}>
        <div className="myp-header">
          {palette?.length > 0 && (
            <button
              className="myp-clear-btn"
              onClick={handleClear}
              title="Clear palette"
              aria-label="Clear palette"
              disabled={isEmpty}
            >
              Clear
            </button>
          )}
          <h1 className="myp-title">My Palette</h1>
          <div className="myp-header-spacer" aria-hidden="true" />
        </div>

        <section className="myp-top">
          {isEmpty ? (
            <div className="myp-empty">Your palette is empty. Add a color below.</div>
          ) : (
            <div className="myp-row">
              <SwatchGallery
                items={filteredPalette}
                SwatchComponent={PaletteSwatch}
                swatchPropName="color"
                className="sg-palette"
                gap={14}
                aspectRatio="5 / 4"
                onSelectColor={onResultsPick}
              />
            </div>
          )}
        </section>
      </div>

      <div id="sticky-sentinel" aria-hidden="true" style={{ height: 1 }} />

      <section className="myp-actions controls-grid">
        <div className="cell area-fuzzy">
          <FuzzySearchColorSelect onSelect={onFuzzyPick} className="myp-fuzzy" />
        </div>

        <div className="cell area-colors">
          <button onClick={handleFriends}>What Colors Go?</button>
        </div>

        <div className="cell area-same">
          <div className="hue-stack">
            <button onClick={handleSimilar}>Same Hue</button>
            <input
              type="number"
              min="0"
              max="30"
              value={tolSame}
              onChange={(e) => setTolSame(Number(e.target.value))}
              className="hue-tolerance"
              placeholder="0"
              title="degree tolerance"
              onFocus={selectAll}
              onMouseUp={keepSelection}
              onPointerUp={keepSelection}
            />
          </div>
        </div>

        <div className="cell area-brands">
          <button type="button" onClick={handleBrowse}>
            See Palettes Based on Your Colors
          </button>
        </div>

        <div className="cell area-include">
          <label className="include-close">
            <input
              type="checkbox"
              checked={includeNeighbors}
              onChange={(e) => setIncludeNeighbors(e.target.checked)}
            />
            Include close matches
          </label>
        </div>

        <div className="cell area-neutrals">
          <button onClick={handleNeutrals}>What Neutrals Go?</button>
        </div>

        <div className="cell area-opposite">
          <div className="hue-stack">
            <button onClick={handleOpposites}>Opposite Hue</button>
            <input
              type="number"
              min="0"
              max="30"
              value={tolOpp}
              onChange={(e) => setTolOpp(Number(e.target.value))}
              className="hue-tolerance"
              placeholder="0"
              title="degree tolerance"
              onFocus={selectAll}
              onMouseUp={keepSelection}
              onPointerUp={keepSelection}
            />
          </div>
        </div>

        <div className="cell area-brands-2">
          <button type="button" onClick={handleTranslate}>See In All Brands</button>
        </div>
      </section>

      <section className="myp-friends-results">
        {loading ? (
          <div className="loading-spinner">Finding friends…</div>
        ) : (
          <>
            {noResultsFound && <div className="no-results">No results for the current filter.</div>}
            <SwatchGallery
              className="sg-results sw-edge"
              items={friends}
              SwatchComponent={PaletteSwatch}
              swatchPropName="color"
              gap={16}
              aspectRatio="5 / 4"
              groupBy="group_header"
              groupOrderBy="group_order"
              showGroupHeaders
              onSelectColor={onResultsPick}
            />
            {includeNeighbors && neighborAddendum.length > 0 && (
              <div className="neighbors-addendum">
                <h3 className="na-title">
                  Also checked a few close colors for each of your picks
                  {` (${neighborAddendum.reduce((n, g) => n + g.chips.length, 0)} total)`}
                </h3>
                {neighborAddendum.map((g, i) => (
                  <div key={i} className="na-row">
                    <div className="na-label">
                      Near <strong>{g.anchorName}</strong>:
                    </div>
                    <div className="na-list">
                      {g.chips.map((chip) => (
                        <a
                          key={chip.key}
                          href={chip.id ? `/color/${chip.id}` : undefined}
                          target="_blank"
                          rel="noopener noreferrer"
                          onClick={(e) => {
                            if (!chip.id) e.preventDefault();
                          }}
                          className={`na-chip${chip.id ? " na-chip--link" : ""}`}
                          style={{
                            backgroundColor: chip.hex ? "#" + chip.hex : "#ccc",
                            color: chip.fg || "#000",
                            borderColor:
                              chip.fg === "#000" ? "rgba(0,0,0,.15)" : "rgba(255,255,255,.35)",
                          }}
                          title={chip.de ? `ΔE ${chip.de.toFixed?.(1)}` : undefined}
                        >
                          <span className="na-text">{chip.label}</span>
                          {chip.de != null && (
                            <span className="na-de">ΔE {chip.de.toFixed?.(1)}</span>
                          )}
                        </a>
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </>
        )}
      </section>
    </div>
  );
}
