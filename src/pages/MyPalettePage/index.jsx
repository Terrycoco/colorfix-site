import { useState, useEffect, useRef, useMemo } from "react";
import { useNavigate } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import { useAppState } from "@context/AppStateContext";
import { isAdmin } from "@helpers/authHelper";
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
    searchFilters,
    brandFiltersAppliedSeq,
    paletteCollapsed,
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
  const paletteFallback = (filteredPalette.length === 0 && srcPalette.length > 0)
    ? srcPalette
    : filteredPalette;

  const getHex = (sw) => {
    const color = sw?.color ?? sw;
    const raw = color?.hex6 || color?.hex || color?.rep_hex || color?.hex_code || "";
    if (!raw) return "#cccccc";
    return raw.startsWith("#") ? raw : `#${raw}`;
  };

  const paletteColorIds = useMemo(() => {
    const arr = Array.isArray(palette) ? palette : [];
    return arr
      .map((sw) => sw?.color?.id ?? sw?.id ?? null)
      .map((id) => (id == null ? null : Number(id)))
      .filter((id) => Number.isFinite(id) && id > 0);
  }, [palette]);

  const isPaletteEmpty = paletteFallback.length === 0;
  const navigate = useNavigate();
  const adminMode = isAdmin();

  // Results (friends / neutrals / similar / opposite)
  const [friends, setFriends] = useState([]);
  const [loading, setLoading] = useState(false);
  const [noResultsFound, setNoResultsFound] = useState(false);
  const [friendsMode, setFriendsMode] = useState("colors"); // 'colors' | 'neutrals' | 'all'
  const [resultsSort, setResultsSort] = useState("");

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
  const [controlsOpen, setControlsOpen] = useState(false);
  const [isMobile, setIsMobile] = useState(() => {
    if (typeof window === "undefined") return false;
    return window.matchMedia("(max-width: 768px)").matches;
  });

  const [saveModalOpen, setSaveModalOpen] = useState(false);
  const [saveForm, setSaveForm] = useState({
    brand: "de",
    nickname: "",
    notes: "",
    terry_fav: false,
    sent_to_email: "",
    client_name: "",
    client_email: "",
    client_phone: "",
    client_notes: "",
  });
  const [saveStatus, setSaveStatus] = useState({ loading: false, error: "", success: "" });
  const printUrl = "/print/my-palette";

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

  const sortedFriends = useMemo(() => {
    if (!resultsSort) return friends;
    const key = resultsSort;
    const getMetric = (item) => {
      const color = item?.color ?? item;
      const h = Number(color?.hcl_h ?? color?.h ?? color?.color_h ?? NaN);
      const c = Number(color?.hcl_c ?? color?.c ?? color?.color_c ?? NaN);
      const l = Number(color?.hcl_l ?? color?.l ?? color?.color_l ?? NaN);
      if (key === "hue") {
        if (!Number.isFinite(h)) return Number.POSITIVE_INFINITY;
        return (h % 360 + 360) % 360;
      }
      if (key === "chroma") return Number.isFinite(c) ? c : Number.POSITIVE_INFINITY;
      return Number.isFinite(l) ? l : Number.POSITIVE_INFINITY;
    };
    return [...(friends || [])].sort((a, b) => {
      const av = getMetric(a);
      const bv = getMetric(b);
      if (av !== bv) return av - bv;
      const an = String(a?.color?.name ?? a?.name ?? a?.color_code ?? "").toLowerCase();
      const bn = String(b?.color?.name ?? b?.name ?? b?.color_code ?? "").toLowerCase();
      return an.localeCompare(bn);
    });
  }, [friends, resultsSort]);

  function runAndRemember(endpoint, tol = null, mode = null) {
    setCurrentEndpoint(endpoint);
    setCurrentTol(tol);
    if (mode) {
      setFriendsMode(mode);
      modeRef.current = mode;
    }
    if (isMobile) setControlsOpen(false);
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
    if (typeof window === "undefined") return;
    const mq = window.matchMedia("(max-width: 768px)");
    const handler = (e) => setIsMobile(e.matches);
    handler(mq);
    mq.addEventListener("change", handler);
    return () => mq.removeEventListener("change", handler);
  }, []);

  useEffect(() => {
    if (!isMobile) {
      setControlsOpen(false);
    }
  }, [isMobile]);

  useEffect(() => {
    const ep = endpointRef.current;
    if (!ep) return;             // nothing has been run yet
    runQuery(ep, tolRef.current, modeRef.current);
  }, [activeBrandCodes.join(',')]);

  // expand palette if empty
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

  useEffect(() => {
    if (!isMobile) return;
    document.body.style.overflow = controlsOpen ? "hidden" : "";
    return () => {
      document.body.style.overflow = "";
    };
  }, [controlsOpen, isMobile]);

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

  const ControlsContent = () => (
    <div className="controls-grid controls-vertical">
      <div className="cell">
        <FuzzySearchColorSelect
          onSelect={onFuzzyPick}
          className="myp-fuzzy"
          placeholder="Enter a color"
          autoFocus={false}
          mobileBreakpoint={0}
        />
      </div>

      <div className="cell">
        <button onClick={handleFriends}>What Colors Go?</button>
      </div>

      <div className="cell">
        <button onClick={handleNeutrals}>What Neutrals Go?</button>
      </div>

      <div className="cell">
        <div className="hue-stack">
          <button className="hue-btn" onClick={handleSimilar}>Same Hue</button>
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

      <div className="cell">
        <div className="hue-stack">
          <button className="hue-btn" onClick={handleOpposites}>Opposite Hue</button>
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

      <div className="cell">
        <button type="button" onClick={handleBrowse}>
          See Designer Palettes
        </button>
      </div>

      <div className="cell">
        <button type="button" onClick={handleTranslate}>
          See In All Brands
        </button>
      </div>

      <div className="cell">
        <label className="include-close">
          <input
            type="checkbox"
            checked={includeNeighbors}
            onChange={(e) => setIncludeNeighbors(e.target.checked)}
          />
          Include close matches
        </label>
      </div>
    </div>
  );

  const openControlsPanel = () => {
    setControlsOpen(true);
  };
  const closeControlsPanel = () => setControlsOpen(false);
  const hidePalettePanel = isMobile && !isPaletteEmpty && paletteCollapsed;

  useEffect(() => {
    setSaveForm((prev) => ({
      ...prev,
      brand: prev.brand || activeBrandCodes[0] || "",
    }));
  }, [activeBrandCodes]);

  function handleSaveFieldChange(e) {
    const { name, value, type, checked } = e.target;
    setSaveForm((prev) => ({
      ...prev,
      [name]: type === "checkbox" ? checked : value,
    }));
  }

  function openSaveModal() {
    setSaveStatus({ loading: false, error: "", success: "" });
    setSaveModalOpen(true);
  }

  function closeSaveModal() {
    if (saveStatus.loading) return;
    setSaveModalOpen(false);
  }

  async function handleSaveSubmit(e) {
    e.preventDefault();
    const brandToUse = (saveForm.brand || activeBrandCodes[0] || "").trim();
    if (!brandToUse) {
      setSaveStatus({ loading: false, error: "Brand is required", success: "" });
      return;
    }
    if (!paletteColorIds.length) {
      setSaveStatus({ loading: false, error: "Add at least one color before saving", success: "" });
      return;
    }
    setSaveStatus({ loading: true, error: "", success: "" });
    try {
      const payload = {
        brand: brandToUse,
        color_ids: paletteColorIds,
        nickname: saveForm.nickname || null,
        notes: saveForm.notes || null,
        terry_fav: !!saveForm.terry_fav,
        sent_to_email: saveForm.sent_to_email || saveForm.client_email || null,
        client_name: saveForm.client_name || null,
        client_email: saveForm.client_email || null,
        client_phone: saveForm.client_phone || null,
        client_notes: saveForm.client_notes || null,
      };
      const res = await fetch(`${API_FOLDER}/v2/admin/saved-palette-save.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "include",
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || data?.ok === false) {
        throw new Error(data?.error || "Failed to save palette");
      }
      const newId = data?.data?.palette?.id ?? "";
      setSaveStatus({
        loading: false,
        error: "",
        success: newId ? `Saved palette #${newId}` : "Palette saved",
      });
    } catch (err) {
      setSaveStatus({ loading: false, error: err?.message || "Failed to save", success: "" });
    }
  }

  /* ---------- Render ---------- */
  return (
    <div className="mypage">
      <div
        id="palette-hero"
        ref={heroRef}
        style={{ display: hidePalettePanel ? "none" : undefined }}
      >
             <h1 className="myp-title">My Palette</h1>
        <div className="myp-header">
          <div className="myp-header-actions">
            {!isPaletteEmpty && (
              <button
                className="myp-clear-btn"
                onClick={handleClear}
                title="Clear palette"
                aria-label="Clear palette"
                disabled={isPaletteEmpty}
              >
                Clear
              </button>
            )}
            {adminMode && (
              <button className="myp-clear-btn" type="button" onClick={() => navigate(`/admin/saved-palettes`)}>
                Saved Palettes
              </button>
            )}
            {adminMode && !isPaletteEmpty && (
              <>
                <button className="myp-clear-btn" type="button" onClick={openSaveModal}>
                  Save
                </button>
                {!isMobile && (
                  <button className="myp-clear-btn" type="button" onClick={() => navigate("/print/my-palette")}>
                    Print
                  </button>
                )}
              </>
            )}
          </div>
     
          <div className="myp-header-spacer" aria-hidden="true" />
        </div>

        <section className="myp-top">
          {isPaletteEmpty ? (
            <div className="myp-empty">
              <p>You have no colors saved yet. Enter a color name to start your palette.</p>
              <FuzzySearchColorSelect
                onSelect={onFuzzyPick}
                className="myp-empty-fuzzy"
                mobileBreakpoint={0}
              />
            </div>
          ) : (
            <div className="myp-row">
              <SwatchGallery
                items={paletteFallback}
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

      {!isMobile && (
        <section className="myp-actions">
          <ControlsContent />
        </section>
      )}

      <section className="myp-friends-results">
        {loading ? (
          <div className="loading-spinner">Finding friends…</div>
        ) : (
          <>
            {noResultsFound && <div className="no-results">No results for the current filter.</div>}
            {friends.length > 0 && (
              <div className="myp-results-sort">
                <span className="myp-results-label">Sort by:</span>
                <button
                  type="button"
                  className={`myp-sort-btn${resultsSort === "hue" ? " is-active" : ""}`}
                  onClick={() => setResultsSort((prev) => (prev === "hue" ? "" : "hue"))}
                >
                  Hue
                </button>
                <button
                  type="button"
                  className={`myp-sort-btn${resultsSort === "chroma" ? " is-active" : ""}`}
                  onClick={() => setResultsSort((prev) => (prev === "chroma" ? "" : "chroma"))}
                >
                  Chroma
                </button>
                <button
                  type="button"
                  className={`myp-sort-btn${resultsSort === "lightness" ? " is-active" : ""}`}
                  onClick={() => setResultsSort((prev) => (prev === "lightness" ? "" : "lightness"))}
                >
                  Lightness
                </button>
              </div>
            )}
            <SwatchGallery
              className="sg-results sw-edge"
              items={sortedFriends}
              SwatchComponent={PaletteSwatch}
              swatchPropName="color"
              gap={16}
              aspectRatio="5 / 4"
              groupBy={resultsSort ? null : "group_header"}
              groupOrderBy={resultsSort ? null : "group_order"}
              showGroupHeaders={!resultsSort}
              onSelectColor={onResultsPick}
              emptyMessage=""
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

      {isMobile && (
        <>
          <button
            type="button"
            className={`myp-fab-add${controlsOpen ? " is-hidden" : ""}`}
            onClick={openControlsPanel}
            aria-label="Add colors"
          >
            +
          </button>
          <div className={`myp-drawer ${controlsOpen ? "open" : ""}`}>
            <div className="myp-drawer-header">
             
               <div type="button" className="drawer-back" onClick={closeControlsPanel} aria-label="Close tools">
                  ←
                </div>
                  
              <div className="drawer-title">
                  <div>Palette Tools</div>
              </div>

            </div>
            <div className="myp-drawer-body">
              <ControlsContent />
            </div>
          </div>
          {controlsOpen && <div className="myp-drawer-backdrop" onClick={closeControlsPanel} />}
        </>
      )}



      {adminMode && saveModalOpen && (
        <div className="myp-save-modal-backdrop" role="dialog" aria-modal="true">
          <div className="myp-save-modal">
            <div className="save-modal-head">
              <h2>Save Palette</h2>
              <button
                type="button"
                className="close-btn"
                onClick={closeSaveModal}
                aria-label="Close dialog"
              >
                ✕
              </button>
            </div>
            <form className="save-form" onSubmit={handleSaveSubmit}>
              <label>
                Brand Code
                <input
                  name="brand"
                  type="text"
                  value={saveForm.brand}
                  onChange={handleSaveFieldChange}
                  placeholder="e.g., de, sw"
                  required
                />
              </label>
              <label>
                Nickname
                <input
                  name="nickname"
                  type="text"
                  value={saveForm.nickname}
                  onChange={handleSaveFieldChange}
                />
              </label>
              <label>
                Notes
                <textarea
                  name="notes"
                  rows={3}
                  value={saveForm.notes}
                  onChange={handleSaveFieldChange}
                />
              </label>
              <label className="checkbox-row">
                <input
                  type="checkbox"
                  name="terry_fav"
                  checked={saveForm.terry_fav}
                  onChange={handleSaveFieldChange}
                />
                Mark as Terry Favorite
              </label>
              <label>
                Send to Email
                <input
                  type="email"
                  name="sent_to_email"
                  value={saveForm.sent_to_email}
                  onChange={handleSaveFieldChange}
                />
              </label>
              <hr />
              <h3>Client (optional)</h3>
              <label>
                Client Name
                <input
                  type="text"
                  name="client_name"
                  value={saveForm.client_name}
                  onChange={handleSaveFieldChange}
                />
              </label>
              <label>
                Client Email
                <input
                  type="email"
                  name="client_email"
                  value={saveForm.client_email}
                  onChange={handleSaveFieldChange}
                  placeholder="client@example.com"
                />
              </label>
              <label>
                Client Phone
                <input
                  type="text"
                  name="client_phone"
                  value={saveForm.client_phone}
                  onChange={handleSaveFieldChange}
                />
              </label>
              <label>
                Client Notes
                <textarea
                  name="client_notes"
                  rows={2}
                  value={saveForm.client_notes}
                  onChange={handleSaveFieldChange}
                />
              </label>
              {saveStatus.error && <div className="save-error">{saveStatus.error}</div>}
              {saveStatus.success && <div className="save-success">{saveStatus.success}</div>}
              <div className="save-actions">
                <button
                  type="button"
                  className="ghost"
                  onClick={closeSaveModal}
                  disabled={saveStatus.loading}
                >
                  Close
                </button>
                <button type="submit" className="primary" disabled={saveStatus.loading}>
                  {saveStatus.loading ? "Saving…" : "Save Palette"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
