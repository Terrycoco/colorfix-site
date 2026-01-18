import { useEffect, useMemo, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import AdminMaskTesterPage from "@pages/AdminMaskTesterPage";
import PhotoSearchPicker from "@components/PhotoSearchPicker";
import { API_FOLDER } from "@helpers/config";
import "./admin-hoa-mask-tester.css";

const HOA_LIST_URL = `${API_FOLDER}/v2/admin/hoas/list.php`;
const SCHEMES_LIST_URL = `${API_FOLDER}/v2/admin/hoa-schemes/list.php`;
const SCHEME_COLORS_URL = `${API_FOLDER}/v2/admin/hoa-schemes/colors/list.php`;
const MASK_MAP_GET_URL = `${API_FOLDER}/v2/admin/hoa-schemes/mask-map/get.php`;
const HOA_COLORS_URL = `${API_FOLDER}/v2/admin/hoa-schemes/colors/by-hoa.php`;
const LAST_ASSET_KEY = "hoaMaskTester:lastAssetId";

function useQuery() {
  const { search } = useLocation();
  return useMemo(() => new URLSearchParams(search), [search]);
}

function normalizeRoleKey(role) {
  const base = String(role || "").trim().toLowerCase();
  if (base.length > 3 && base.endsWith("s") && !base.endsWith("ss")) {
    return base.slice(0, -1);
  }
  return base;
}

function splitRoleTokens(value) {
  return String(value || "")
    .split(/[|,;/&]/g)
    .map((r) => r.trim())
    .filter(Boolean);
}

function buildSchemeColors(items = []) {
  const byRole = {};
  const anyColors = [];
  const anySeen = new Set();
  const roleSeen = {};

  items.forEach((row) => {
    const colorId = Number(row?.color_id);
    if (!colorId) return;
    const color = {
      color_id: colorId,
      name: row?.name || "",
      brand: row?.brand || "",
      code: row?.code || "",
      hex6: String(row?.hex6 || "").toUpperCase(),
      hcl_h: row?.hcl_h ?? null,
      hcl_c: row?.hcl_c ?? null,
      hcl_l: row?.hcl_l ?? null,
      note: row?.notes ?? null,
      scheme_code: row?.scheme_code ?? null,
    };
    const allowedRaw = String(row?.allowed_roles || "").trim().toLowerCase();
    if (!allowedRaw || allowedRaw === "any") {
      if (!anySeen.has(colorId)) {
        anyColors.push(color);
        anySeen.add(colorId);
      }
      return;
    }

    const roles = splitRoleTokens(allowedRaw);
    if (!roles.length) {
      if (!anySeen.has(colorId)) {
        anyColors.push(color);
        anySeen.add(colorId);
      }
      return;
    }

    roles.forEach((role) => {
      const key = normalizeRoleKey(role);
      if (!key) return;
      if (!byRole[key]) {
        byRole[key] = [];
        roleSeen[key] = new Set();
      }
      if (roleSeen[key].has(colorId)) return;
      byRole[key].push(color);
      roleSeen[key].add(colorId);
    });
  });

  const union = [...anyColors];
  const seen = new Set(union.map((c) => c.color_id));
  Object.values(byRole).forEach((list) => {
    list.forEach((row) => {
      if (seen.has(row.color_id)) return;
      seen.add(row.color_id);
      union.push(row);
    });
  });

  return { byRole, anyColors, union };
}

export default function AdminHoaMaskTesterPage() {
  const query = useQuery();
  const navigate = useNavigate();
  const initialHoaId = query.get("hoa") || "";
  const initialSchemeId = query.get("scheme") || "";
  const initialAssetId = query.get("asset") || "";

  const [hoaOptions, setHoaOptions] = useState([]);
  const [schemes, setSchemes] = useState([]);
  const [hoaId, setHoaId] = useState(initialHoaId);
  const [schemeId, setSchemeId] = useState(initialSchemeId);
  const [assetId, setAssetId] = useState(initialAssetId);
  const [assetInput, setAssetInput] = useState(initialAssetId);
  const [findOpen, setFindOpen] = useState(true);
  const [schemeColors, setSchemeColors] = useState([]);
  const [hoaColorsByRole, setHoaColorsByRole] = useState({});
  const [hoaAnyColors, setHoaAnyColors] = useState([]);
  const [loadingSchemes, setLoadingSchemes] = useState(false);
  const [loadingColors, setLoadingColors] = useState(false);
  const [loadingMapping, setLoadingMapping] = useState(false);
  const [schemeMapping, setSchemeMapping] = useState({});
  const [error, setError] = useState("");
  const [mappingRefreshTick, setMappingRefreshTick] = useState(0);

  useEffect(() => {
    if (initialAssetId) return;
    try {
      const saved = window.localStorage.getItem(LAST_ASSET_KEY);
      if (saved) {
        setAssetInput(saved);
        setAssetId(saved);
      }
    } catch {
      // ignore storage failures
    }
  }, [initialAssetId]);

  useEffect(() => {
    fetch(HOA_LIST_URL, { credentials: "include" })
      .then((r) => r.json())
      .then((data) => {
        if (!data?.ok) throw new Error(data?.error || "Failed to load HOAs");
        setHoaOptions(data.items || []);
      })
      .catch((err) => setError(err?.message || "Failed to load HOAs"));
  }, []);

  useEffect(() => {
    if (!hoaId) {
      setSchemes([]);
      setSchemeId("");
      setHoaColorsByRole({});
      setHoaAnyColors([]);
      return;
    }
    setLoadingSchemes(true);
    fetch(`${SCHEMES_LIST_URL}?hoa_id=${encodeURIComponent(hoaId)}&_=${Date.now()}`, {
      credentials: "include",
    })
      .then((r) => r.json())
      .then((data) => {
        if (!data?.ok) throw new Error(data?.error || "Failed to load schemes");
        setSchemes(data.items || []);
      })
      .catch((err) => setError(err?.message || "Failed to load schemes"))
      .finally(() => setLoadingSchemes(false));

    fetch(`${HOA_COLORS_URL}?hoa_id=${encodeURIComponent(hoaId)}&_=${Date.now()}`, {
      credentials: "include",
      cache: "no-store",
    })
      .then((r) => r.json())
      .then((data) => {
        if (!data?.ok) throw new Error(data?.error || "Failed to load HOA colors");
        const rawByRole = data?.colors_by_role || {};
        const byRole = {};
        Object.entries(rawByRole).forEach(([role, list]) => {
          const key = normalizeRoleKey(role);
          byRole[key] = Array.isArray(list) ? list : [];
        });
        const anyColors = Array.isArray(data?.any_colors) ? data.any_colors : [];
        setHoaColorsByRole(byRole);
        setHoaAnyColors(anyColors);
      })
      .catch((err) => setError(err?.message || "Failed to load HOA colors"));
  }, [hoaId]);

  useEffect(() => {
    if (!schemeId) {
      setSchemeColors([]);
      return;
    }
    setLoadingColors(true);
    fetch(`${SCHEME_COLORS_URL}?scheme_id=${encodeURIComponent(schemeId)}&_=${Date.now()}`, {
      credentials: "include",
    })
      .then((r) => r.json())
      .then((data) => {
        if (!data?.ok) throw new Error(data?.error || "Failed to load scheme colors");
        setSchemeColors(data.items || []);
      })
      .catch((err) => setError(err?.message || "Failed to load scheme colors"))
      .finally(() => setLoadingColors(false));
  }, [schemeId]);

  useEffect(() => {
    if (!schemeId || !assetId) {
      setSchemeMapping({});
      return;
    }
    let cancelled = false;
    setLoadingMapping(true);
    fetch(
      `${MASK_MAP_GET_URL}?scheme_id=${encodeURIComponent(schemeId)}&asset_id=${encodeURIComponent(assetId)}&_=${Date.now()}`,
      { credentials: "include", cache: "no-store" }
    )
      .then((r) => r.json())
      .then((data) => {
        if (!data?.ok) throw new Error(data?.error || "Failed to load mapping");
        if (cancelled) return;
        const nextMap = {};
        (data.items || []).forEach((row) => {
          if (row.mask_role && row.scheme_role) {
            nextMap[row.mask_role] = row.scheme_role;
          }
        });
        setSchemeMapping(nextMap);
      })
      .catch((err) => {
        if (!cancelled) setError(err?.message || "Failed to load mapping");
      })
      .finally(() => {
        if (!cancelled) setLoadingMapping(false);
      });
    return () => {
      cancelled = true;
    };
  }, [schemeId, assetId, mappingRefreshTick]);

  useEffect(() => {
    function handleFocus() {
      setMappingRefreshTick((v) => v + 1);
    }
    window.addEventListener("focus", handleFocus);
    return () => window.removeEventListener("focus", handleFocus);
  }, []);

  useEffect(() => {
    const nav = new URLSearchParams();
    if (hoaId) nav.set("hoa", hoaId);
    if (schemeId) nav.set("scheme", schemeId);
    if (assetId) nav.set("asset", assetId);
    navigate(`/admin/hoa-mask-tester?${nav.toString()}`, { replace: true });
  }, [hoaId, schemeId, assetId, navigate]);

  useEffect(() => {
    if (!assetId) return;
    try {
      window.localStorage.setItem(LAST_ASSET_KEY, assetId);
    } catch {
      // ignore storage failures
    }
  }, [assetId]);

  const { byRole, anyColors, union } = useMemo(
    () => buildSchemeColors(schemeColors),
    [schemeColors]
  );

  const schemeColorIds = useMemo(
    () => union.map((row) => Number(row.color_id)).filter(Boolean),
    [union]
  );

  const schemeLabel = useMemo(() => {
    const found = schemes.find((s) => String(s.id) === String(schemeId));
    if (!found) return "HOA Scheme";
    return found.scheme_code || `Scheme #${found.id}`;
  }, [schemes, schemeId]);

  const schemeMapperLink = useMemo(() => {
    const nav = new URLSearchParams();
    if (hoaId) nav.set("hoa", hoaId);
    if (schemeId) nav.set("scheme", schemeId);
    if (assetId) nav.set("asset", assetId);
    const qs = nav.toString();
    return qs ? `/admin/hoa-scheme-tester?${qs}` : "/admin/hoa-scheme-tester";
  }, [hoaId, schemeId, assetId]);

  const roleAliasMap = useMemo(() => {
    if (!schemeId || !assetId) return {};
    const alias = {};
    Object.entries(schemeMapping).forEach(([maskRole, group]) => {
      const tokens = splitRoleTokens(group);
      const candidate = tokens.find((t) => byRole[normalizeRoleKey(t)]);
      alias[maskRole] = candidate || tokens[0] || "";
    });
    return alias;
  }, [schemeId, assetId, byRole, schemeMapping]);

  function handlePickPhoto(item) {
    const nextId = item?.asset_id || "";
    setAssetInput(nextId);
    setAssetId(nextId);
    setFindOpen(false);
  }

  function handleLoadAsset() {
    const nextId = assetInput.trim();
    if (!nextId) return;
    setAssetId(nextId);
    setFindOpen(false);
  }

  return (
    <div className="hoa-mask-tester-page">
      <header className="hoa-mask-tester-header">
        <div>
          <div className="hoa-mask-tester-title">HOA Mask Tester</div>
          <div className="hoa-mask-tester-subtitle">
            Test scheme colors against masks using the standard mask tester.
          </div>
        </div>
      </header>

      {error && <div className="hoa-mask-tester-status error">{error}</div>}

      <section className="hoa-mask-tester-panel">
        <div className="hoa-mask-tester-grid">
          <label>
            HOA
            <select value={hoaId} onChange={(e) => setHoaId(e.target.value)}>
              <option value="">Select HOA</option>
              {hoaOptions.map((hoa) => (
                <option key={hoa.id} value={hoa.id}>
                  {hoa.name || `HOA #${hoa.id}`}{hoa.city ? ` • ${hoa.city}` : ""}
                </option>
              ))}
            </select>
          </label>
          <label>
            Scheme
            <select value={schemeId} onChange={(e) => setSchemeId(e.target.value)} disabled={!hoaId || loadingSchemes}>
              <option value="">Select scheme</option>
              {schemes.map((scheme) => (
                <option key={scheme.id} value={scheme.id}>
                  {scheme.scheme_code} {scheme.notes ? `• ${scheme.notes}` : ""}
                </option>
              ))}
            </select>
          </label>
          <label>
            Photo asset id
            <div className="hoa-mask-tester-inline">
              <input
                type="text"
                value={assetInput}
                onChange={(e) => setAssetInput(e.target.value)}
                placeholder="e.g., PHO_123ABC"
              />
              <button type="button" onClick={handleLoadAsset} disabled={!assetInput.trim()}>
                Load
              </button>
              <button type="button" onClick={() => setFindOpen((v) => !v)}>
                {findOpen ? "Hide Finder" : "Find Photo"}
              </button>
            </div>
          </label>
        </div>
        {findOpen && (
          <div className="hoa-mask-tester-finder">
            <PhotoSearchPicker onPick={handlePickPhoto} />
          </div>
        )}
        {loadingColors && <div className="hoa-mask-tester-status">Loading scheme colors…</div>}
        {loadingMapping && <div className="hoa-mask-tester-status">Loading mapping…</div>}
      </section>

      <AdminMaskTesterPage
        baseRoute="/admin/hoa-mask-tester"
        forcedAssetId={assetId}
        forcedTesterLabel={schemeId ? schemeLabel : ""}
        forcedTesterColors={schemeId ? union : null}
        forcedTesterColorsByMask={schemeId ? byRole : null}
        forcedTesterColorsAny={schemeId ? anyColors : null}
        schemeMode
        schemeOptions={schemes}
        schemeSelection={schemeId}
        onSchemeChange={setSchemeId}
        defaultTesterView="all"
        schemeMappingLink={schemeMapperLink}
        schemeColorIds={schemeColorIds}
        allSchemeColorsByMask={hoaColorsByRole}
        allSchemeColorsAny={hoaAnyColors}
        roleAliasMap={roleAliasMap}
        hideTesterSourceControls
        hideFinder
        titleOverride="HOA Mask Tester"
        defaultBlendMode="multiply"
        defaultBlendOpacity={1}
      />
    </div>
  );
}
