import { useState, useEffect, useRef, useMemo } from "react";
import { useNavigate } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import { useAppState } from "@context/AppStateContext";
import KickerDropdown from "@components/KickerDropdown";
import { isAdmin } from "@helpers/authHelper";
import PaletteSwatch from "@components/Swatches/PaletteSwatch";
import SwatchGallery from "@components/SwatchGallery";
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import EditableSwatch from "@components/EditableSwatch";
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
    reorderPalette,
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
    overwrite_id: "",
    nickname: "",
    notes: "",
    private_notes: "",
    terry_fav: false,
    kicker_id: "",
    palette_type: "exterior",
  });
  const [saveStatus, setSaveStatus] = useState({ loading: false, error: "", success: "" });
  const [savedPaletteId, setSavedPaletteId] = useState(null);
  const [rolesModalOpen, setRolesModalOpen] = useState(false);
  const [roleRows, setRoleRows] = useState([]);
  const [roleStatus, setRoleStatus] = useState({ loading: false, error: "", success: "" });
  const [savedPaletteOptions, setSavedPaletteOptions] = useState([]);
  const [savedPaletteOptionsStatus, setSavedPaletteOptionsStatus] = useState({
    loading: false,
    error: "",
  });
  const printUrl = "/print/my-palette";
  const [draggingId, setDraggingId] = useState(null);
  const [dragOverId, setDragOverId] = useState(null);
  const [showBackToTop, setShowBackToTop] = useState(false);

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

      const brandsQS = buildBrandsQS(searchFilters);
      const neighborQS = isFriends && includeNeighbors ? "&include_neighbors=1" : "";

      if (isHue) {
        const anchorList = (Array.isArray(palette) ? palette : [])
          .map((item) => item?.color ?? item)
          .filter((item) => item && Number(item?.id ?? 0) > 0);
        const groupPromises = anchorList.map(async (anchor, index) => {
          const qs = new URLSearchParams();
          qs.set("ids", String(anchor.id));
          if (tol != null) qs.set("tol", String(tol));
          qs.set("_cb", String(Date.now()) + "-" + index); // cache-buster
          const url = `${API_FOLDER}/${endpoint}?${qs.toString()}${brandsQS}`;
          const data = await fetchJsonStrict(url);
          const rows = Array.isArray(data?.items) ? data.items : (Array.isArray(data) ? data : []);
          const groupHeader = anchor?.name || `Color ${anchor.id}`;
          return rows.map((row) => ({
            ...row,
            group_header: groupHeader,
            group_order: index,
          }));
        });

        const grouped = await Promise.all(groupPromises);
        const combined = grouped.flat();
        setFriends(combined);
        setNoResultsFound(combined.length === 0);
        setNeighborsUsed(null);
        return;
      }

      const qs = new URLSearchParams();
      qs.set("ids", anchorColorIds.join(","));
      if (isFriends) {
        const modeToSend = mode ?? friendsMode;
        qs.set("mode", modeToSend);
      }
      qs.set("_cb", String(Date.now())); // cache-buster

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
    const sortItems = (items) =>
      [...items].sort((a, b) => {
        const av = getMetric(a);
        const bv = getMetric(b);
        if (av !== bv) return av - bv;
        const an = String(a?.color?.name ?? a?.name ?? a?.color_code ?? "").toLowerCase();
        const bn = String(b?.color?.name ?? b?.name ?? b?.color_code ?? "").toLowerCase();
        return an.localeCompare(bn);
      });

    const hasGroupHeaders = (friends || []).some((item) => item?.group_header);
    if (!hasGroupHeaders) {
      return sortItems(friends || []);
    }

    const grouped = new Map();
    (friends || []).forEach((item) => {
      const header = String(item?.group_header ?? "Other");
      const order = Number.isFinite(Number(item?.group_order)) ? Number(item.group_order) : 9999;
      if (!grouped.has(header)) {
        grouped.set(header, { order, items: [] });
      }
      grouped.get(header).items.push(item);
    });

    return Array.from(grouped.entries())
      .sort((a, b) => a[1].order - b[1].order || a[0].localeCompare(b[0]))
      .flatMap(([, group]) => sortItems(group.items));
  }, [friends, resultsSort]);

  const hasGroupHeaders = useMemo(
    () => (friends || []).some((item) => item?.group_header),
    [friends]
  );

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
    const onScroll = () => setShowBackToTop(window.scrollY > 500);
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
    return () => window.removeEventListener("scroll", onScroll);
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

  const getSwatchId = (swatch) => {
    const id = swatch?.id ?? swatch?.color?.id ?? null;
    return id == null ? null : Number(id);
  };

  const handleReorder = (dragId, overId) => {
    if (!dragId || !overId || dragId === overId) return;
    const list = Array.isArray(palette) ? [...palette] : [];
    const fromIndex = list.findIndex((item) => getSwatchId(item) === dragId);
    const toIndex = list.findIndex((item) => getSwatchId(item) === overId);
    if (fromIndex < 0 || toIndex < 0 || fromIndex === toIndex) return;
    const [moved] = list.splice(fromIndex, 1);
    list.splice(toIndex, 0, moved);
    reorderPalette(list);
  };

  const handleDragStart = (event, id) => {
    if (!id) return;
    event.dataTransfer.effectAllowed = "move";
    event.dataTransfer.setData("text/plain", String(id));
    setDraggingId(id);
  };

  const handleDragOver = (event, id) => {
    if (!id) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = "move";
    if (dragOverId !== id) setDragOverId(id);
  };

  const handleDrop = (event, id) => {
    event.preventDefault();
    const dragId = Number(event.dataTransfer.getData("text/plain"));
    handleReorder(dragId, id);
    setDragOverId(null);
    setDraggingId(null);
  };

  const handleDragEnd = () => {
    setDraggingId(null);
    setDragOverId(null);
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

  function handleOverwriteSelect(e) {
    const value = e.target.value;
    setSaveForm((prev) => {
      const next = { ...prev, overwrite_id: value };
      if (!value) {
        return next;
      }
      const match = savedPaletteOptions.find((item) => String(item.id) === String(value));
      if (match && match.nickname) {
        next.nickname = match.nickname;
      }
      return next;
    });
  }

  function openSaveModal() {
    setSaveStatus({ loading: false, error: "", success: "" });
    setSavedPaletteId(null);
    setSaveModalOpen(true);
  }

  function closeSaveModal() {
    if (saveStatus.loading) return;
    setSaveModalOpen(false);
  }

  useEffect(() => {
    if (!saveModalOpen) return;
    const brandToUse = (saveForm.brand || activeBrandCodes[0] || "").trim();
    if (!brandToUse) return;
    let isCancelled = false;

    async function loadSavedPalettes() {
      setSavedPaletteOptionsStatus({ loading: true, error: "" });
      try {
        const qs = new URLSearchParams();
        qs.set("brand", brandToUse);
        qs.set("limit", "200");
        const res = await fetch(`${API_FOLDER}/v2/admin/saved-palettes.php?${qs.toString()}`, {
          credentials: "include",
        });
        const data = await res.json();
        if (!res.ok || data?.ok === false) {
          throw new Error(data?.error || "Failed to load saved palettes");
        }
        const items = Array.isArray(data?.items) ? data.items : [];
        const options = items.map((item) => ({
          id: item.id,
          nickname: (item.nickname || "").trim(),
          label: (item.nickname || "").trim() || `Untitled #${item.id}`,
        }));
        if (!isCancelled) {
          setSavedPaletteOptions(options);
          setSavedPaletteOptionsStatus({ loading: false, error: "" });
        }
      } catch (err) {
        if (!isCancelled) {
          setSavedPaletteOptions([]);
          setSavedPaletteOptionsStatus({
            loading: false,
            error: err?.message || "Failed to load saved palettes",
          });
        }
      }
    }

    loadSavedPalettes();

    return () => {
      isCancelled = true;
    };
  }, [saveModalOpen, saveForm.brand, activeBrandCodes]);

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
      const overwriteId = Number(saveForm.overwrite_id || 0);
      const payload = {
        brand: brandToUse,
        color_ids: paletteColorIds,
        nickname: saveForm.nickname || null,
        notes: saveForm.notes || null,
        private_notes: saveForm.private_notes || null,
        terry_fav: !!saveForm.terry_fav,
        kicker_id: saveForm.kicker_id ? Number(saveForm.kicker_id) : null,
        palette_type: saveForm.palette_type || "exterior",
        ...(overwriteId > 0 ? { palette_id: overwriteId } : {}),
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
      const newId = data?.data?.palette?.id ?? overwriteId ?? "";
      setSaveStatus({
        loading: false,
        error: "",
        success: newId
          ? overwriteId > 0
            ? `Updated palette #${newId}`
            : `Saved palette #${newId}`
          : "Palette saved",
      });
      if (newId) {
        setSavedPaletteId(Number(newId));
        const basePalette = Array.isArray(palette) ? palette : [];
        setRoleRows(
          basePalette.map((swatch, index) => ({
            key: swatch?.id ?? swatch?.color?.id ?? `row-${index}`,
            color: swatch?.color ?? swatch,
            role: "",
          }))
        );
      }
    } catch (err) {
      setSaveStatus({ loading: false, error: err?.message || "Failed to save", success: "" });
    }
  }

  const openRolesModal = () => {
    if (!savedPaletteId) return;
    if (!roleRows.length) {
      const basePalette = Array.isArray(palette) ? palette : [];
      setRoleRows(
        basePalette.map((swatch, index) => ({
          key: swatch?.id ?? swatch?.color?.id ?? `row-${index}`,
          color: swatch?.color ?? swatch,
          role: "",
        }))
      );
    }
    setRoleStatus({ loading: false, error: "", success: "" });
    setRolesModalOpen(true);
  };

  const closeRolesModal = () => {
    if (roleStatus.loading) return;
    setRolesModalOpen(false);
  };

  const handleRoleColorChange = (index, color) => {
    setRoleRows((prev) =>
      prev.map((row, idx) => (idx === index ? { ...row, color } : row))
    );
  };

  const handleRoleFieldChange = (index, value) => {
    setRoleRows((prev) =>
      prev.map((row, idx) => (idx === index ? { ...row, role: value } : row))
    );
  };

  const handleSaveRoles = async (event) => {
    event.preventDefault();
    if (!savedPaletteId) {
      setRoleStatus({ loading: false, error: "Save the palette first.", success: "" });
      return;
    }
    const members = roleRows
      .map((row, index) => {
        const colorId = Number(row?.color?.id || row?.color?.color_id || 0);
        if (!colorId) return null;
        const role = row?.role?.trim() || null;
        return { color_id: colorId, order_index: index, role };
      })
      .filter(Boolean);
    if (!members.length) {
      setRoleStatus({ loading: false, error: "Add at least one color.", success: "" });
      return;
    }
    setRoleStatus({ loading: true, error: "", success: "" });
    try {
      const res = await fetch(`${API_FOLDER}/v2/admin/saved-palette-update.php`, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          palette_id: savedPaletteId,
          members,
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data?.ok === false) {
        throw new Error(data?.error || "Failed to save roles");
      }
      setRoleStatus({ loading: false, error: "", success: "Roles saved." });
    } catch (err) {
      setRoleStatus({ loading: false, error: err?.message || "Failed to save roles", success: "" });
    }
  };

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
              <div className="sg-root sg-palette myp-palette-grid">
                <div className="sg-grid">
                  {paletteFallback.map((swatch, index) => {
                    const swatchId = getSwatchId(swatch);
                    const key = swatchId ?? swatch?.hex6 ?? swatch?.hex ?? index;
                    return (
                      <div
                        key={key}
                        className={`sg-item${swatchId === draggingId ? " is-dragging" : ""}${swatchId === dragOverId ? " is-over" : ""}`}
                        draggable={paletteFallback.length > 1}
                        onDragStart={(event) => handleDragStart(event, swatchId)}
                        onDragOver={(event) => handleDragOver(event, swatchId)}
                        onDrop={(event) => handleDrop(event, swatchId)}
                        onDragEnd={handleDragEnd}
                      >
                        <div className="sg-card-shell">
                          <PaletteSwatch
                            color={swatch?.color ?? swatch}
                            onSelectColor={onResultsPick}
                          />
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
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
                <div className="myp-results-buttons">
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
              </div>
            )}
            <SwatchGallery
              className="sg-results sw-edge"
              items={sortedFriends}
              SwatchComponent={PaletteSwatch}
              swatchPropName="color"
              gap={16}
              aspectRatio="5 / 4"
              groupBy={hasGroupHeaders ? "group_header" : (resultsSort ? null : "group_header")}
              groupOrderBy={hasGroupHeaders ? "group_order" : (resultsSort ? null : "group_order")}
              showGroupHeaders={hasGroupHeaders ? true : !resultsSort}
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
                Overwrite Existing (optional)
                <select
                  name="overwrite_id"
                  value={saveForm.overwrite_id}
                  onChange={handleOverwriteSelect}
                >
                  <option value="">Create new…</option>
                  {savedPaletteOptions.map((opt) => (
                    <option key={opt.id} value={opt.id}>
                      {opt.label}
                    </option>
                  ))}
                </select>
                {savedPaletteOptionsStatus.loading && (
                  <div className="save-help">Loading saved palettes…</div>
                )}
                {savedPaletteOptionsStatus.error && (
                  <div className="save-help error">{savedPaletteOptionsStatus.error}</div>
                )}
              </label>
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
                Public Notes (shown in viewer)
                <textarea
                  name="notes"
                  rows={3}
                  value={saveForm.notes}
                  onChange={handleSaveFieldChange}
                />
              </label>
              <label>
                Private Notes (for me)
                <textarea
                  name="private_notes"
                  rows={3}
                  value={saveForm.private_notes}
                  onChange={handleSaveFieldChange}
                />
              </label>
              <label>
                Kicker (optional)
                <KickerDropdown
                  value={saveForm.kicker_id}
                  onChange={(next) => setSaveForm((prev) => ({ ...prev, kicker_id: next || "" }))}
                />
              </label>
              <label>
                Palette Type
                <select
                  name="palette_type"
                  value={saveForm.palette_type}
                  onChange={handleSaveFieldChange}
                >
                  <option value="exterior">Exterior</option>
                  <option value="interior">Interior</option>
                  <option value="hoa">HOA</option>
                </select>
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
              {saveStatus.error && <div className="save-error">{saveStatus.error}</div>}
              {saveStatus.success && <div className="save-success">{saveStatus.success}</div>}
              {saveStatus.success && savedPaletteId && (
                <button type="button" className="ghost" onClick={openRolesModal}>
                  Edit Colors / Roles
                </button>
              )}
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
      {rolesModalOpen && (
        <div className="myp-save-modal-backdrop" role="dialog" aria-modal="true">
          <div className="myp-save-modal myp-role-modal">
            <div className="save-modal-head">
              <h2>Edit Colors & Roles</h2>
              <button
                type="button"
                className="close-btn"
                onClick={closeRolesModal}
                aria-label="Close dialog"
              >
                ✕
              </button>
            </div>
            <form className="save-form" onSubmit={handleSaveRoles}>
              <div className="role-rows">
                {roleRows.map((row, index) => (
                  <div key={row.key || index} className="role-row">
                    <EditableSwatch
                      value={row.color}
                      onChange={(color) => handleRoleColorChange(index, color)}
                      showName
                      size="sm"
                      placement="top"
                    />
                    <input
                      type="text"
                      placeholder="Role (e.g. trim, body, door)"
                      value={row.role}
                      onChange={(e) => handleRoleFieldChange(index, e.target.value)}
                    />
                  </div>
                ))}
              </div>
              {roleStatus.error && <div className="save-error">{roleStatus.error}</div>}
              {roleStatus.success && <div className="save-success">{roleStatus.success}</div>}
              <div className="save-actions">
                <button type="button" className="ghost" onClick={closeRolesModal} disabled={roleStatus.loading}>
                  Close
                </button>
                <button type="submit" className="primary" disabled={roleStatus.loading}>
                  {roleStatus.loading ? "Saving…" : "Save Roles"}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
      {showBackToTop && (
        <button
          type="button"
          className="back-to-top"
          onClick={() => window.scrollTo({ top: 0, behavior: "smooth" })}
        >
          Back to top
        </button>
      )}
    </div>
  );
}
