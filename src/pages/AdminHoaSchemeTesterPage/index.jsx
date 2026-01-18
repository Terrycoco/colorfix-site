import { useEffect, useMemo, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import PhotoSearchPicker from "@components/PhotoSearchPicker";
import "./admin-hoa-scheme-tester.css";

const HOA_LIST_URL = `${API_FOLDER}/v2/admin/hoas/list.php`;
const SCHEMES_LIST_URL = `${API_FOLDER}/v2/admin/hoa-schemes/list.php`;
const SCHEME_COLORS_URL = `${API_FOLDER}/v2/admin/hoa-schemes/colors/list.php`;
const MASK_MAP_GET_URL = `${API_FOLDER}/v2/admin/hoa-schemes/mask-map/get.php`;
const MASK_MAP_SAVE_URL = `${API_FOLDER}/v2/admin/hoa-schemes/mask-map/save.php`;

function normalizeToken(value) {
  return String(value || "").toLowerCase().replace(/[^a-z0-9]+/g, "");
}

function splitRoleTokens(value) {
  return String(value || "")
    .split(/[|,;/&]/g)
    .map((r) => r.trim())
    .filter(Boolean);
}

function normalizeRoleGroup(value) {
  const allowed = String(value || "").trim();
  if (!allowed) return "";
  if (allowed.toLowerCase() === "any") return "";
  const parts = splitRoleTokens(allowed);
  if (!parts.length) return "";
  return parts.join(", ");
}

function parseRoleGroupsFromColors(items = []) {
  const groups = new Set();
  items.forEach((row) => {
    const group = normalizeRoleGroup(row?.allowed_roles || "");
    if (group) groups.add(group);
  });
  return Array.from(groups).sort((a, b) => a.localeCompare(b));
}

function normalizeRoleTokens(value) {
  return splitRoleTokens(value).map((r) => normalizeToken(r)).filter(Boolean);
}

function buildRoleGroups(roleGroups) {
  return roleGroups.map((group) => {
    const tokens = splitRoleTokens(group).map((r) => normalizeToken(r)).filter(Boolean);
    return {
      group,
      tokens,
      normalizedGroup: normalizeToken(group),
    };
  });
}

function computeAutoMapping(masks = [], roleGroups = []) {
  const groups = buildRoleGroups(roleGroups);
  const nextMap = {};
  masks.forEach(({ role }) => {
    if (!role) return;
    const normalized = normalizeToken(role);
    const tokenMatches = groups
      .filter((entry) => entry.tokens.includes(normalized))
      .sort((a, b) => b.tokens.length - a.tokens.length);
    if (tokenMatches.length) {
      nextMap[role] = tokenMatches[0].group;
      return;
    }
    const fallback = groups
      .filter((entry) => entry.normalizedGroup.includes(normalized))
      .sort((a, b) => b.normalizedGroup.length - a.normalizedGroup.length);
    if (fallback.length) {
      nextMap[role] = fallback[0].group;
    }
  });
  return nextMap;
}

export default function AdminHoaSchemeTesterPage() {
  const { search } = useLocation();
  const query = useMemo(() => new URLSearchParams(search), [search]);
  const navigate = useNavigate();
  const [hoaOptions, setHoaOptions] = useState([]);
  const [hoaId, setHoaId] = useState(query.get("hoa") || "");
  const [schemes, setSchemes] = useState([]);
  const [schemeId, setSchemeId] = useState(query.get("scheme") || "");
  const [schemeColors, setSchemeColors] = useState([]);
  const [assetId, setAssetId] = useState(query.get("asset") || "");
  const [asset, setAsset] = useState(null);
  const [loadingAsset, setLoadingAsset] = useState(false);
  const [error, setError] = useState("");
  const [mappingByScheme, setMappingByScheme] = useState({});
  const [dirtyByScheme, setDirtyByScheme] = useState({});
  const [saving, setSaving] = useState(false);
  const [findOpen, setFindOpen] = useState(true);

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
      return;
    }
    fetch(`${SCHEMES_LIST_URL}?hoa_id=${encodeURIComponent(hoaId)}&_=${Date.now()}`, {
      credentials: "include",
    })
      .then((r) => r.json())
      .then((data) => {
        if (!data?.ok) throw new Error(data?.error || "Failed to load schemes");
        setSchemes(data.items || []);
      })
      .catch((err) => setError(err?.message || "Failed to load schemes"));
  }, [hoaId]);

  useEffect(() => {
    if (!schemeId) {
      setSchemeColors([]);
      return;
    }
    fetch(`${SCHEME_COLORS_URL}?scheme_id=${encodeURIComponent(schemeId)}&_=${Date.now()}`, {
      credentials: "include",
    })
      .then((r) => r.json())
      .then((data) => {
        if (!data?.ok) throw new Error(data?.error || "Failed to load scheme colors");
        setSchemeColors(data.items || []);
      })
      .catch((err) => setError(err?.message || "Failed to load scheme colors"));
  }, [schemeId]);

  function loadAsset(nextAssetId = null) {
    const resolvedAssetId = String(nextAssetId ?? assetId).trim();
    if (!resolvedAssetId) return;
    setLoadingAsset(true);
    setError("");
    if (resolvedAssetId !== String(assetId)) {
      setAssetId(resolvedAssetId);
    }
    fetch(`${API_FOLDER}/v2/photos/get.php?asset_id=${encodeURIComponent(resolvedAssetId)}`, {
      credentials: "include",
      headers: { Accept: "application/json" },
    })
      .then((r) => r.json())
      .then((data) => {
        if (data.error) throw new Error(data.error);
        const normalized = {
          asset_id: data.asset_id,
          masks: Array.isArray(data.masks)
            ? data.masks.map((m) => ({ role: m.role }))
            : [],
        };
        setAsset(normalized);
        if (schemeId) {
          fetchSchemeMapping(schemeId, normalized.asset_id);
        }
        setFindOpen(false);
      })
      .catch((err) => setError(err?.message || "Failed to load asset"))
      .finally(() => setLoadingAsset(false));
  }

  useEffect(() => {
    const nextHoa = query.get("hoa") || "";
    const nextScheme = query.get("scheme") || "";
    const nextAsset = query.get("asset") || "";
    if (nextHoa && nextHoa !== hoaId) setHoaId(nextHoa);
    if (nextScheme && nextScheme !== schemeId) setSchemeId(nextScheme);
    if (nextAsset && nextAsset !== assetId) {
      setAssetId(nextAsset);
      loadAsset(nextAsset);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [query]);

  useEffect(() => {
    if (!assetId) return;
    if (asset?.asset_id === assetId) return;
    loadAsset(assetId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [assetId]);

  useEffect(() => {
    if (!schemeId || !assetId) return;
    fetchSchemeMapping(schemeId, assetId);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [schemeId, assetId]);

  useEffect(() => {
    setMappingByScheme({});
  }, [assetId]);

  useEffect(() => {
    setDirtyByScheme({});
  }, [assetId]);

  async function fetchSchemeMapping(targetSchemeId, targetAssetId) {
    if (!targetSchemeId || !targetAssetId) return;
    try {
      const res = await fetch(`${MASK_MAP_GET_URL}?scheme_id=${encodeURIComponent(targetSchemeId)}&asset_id=${encodeURIComponent(targetAssetId)}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!data?.ok) throw new Error(data?.error || "Failed to load mapping");
      const map = {};
      (data.items || []).forEach((row) => {
        if (row.mask_role && row.scheme_role) map[row.mask_role] = row.scheme_role;
      });
      setMappingByScheme((prev) => ({ ...prev, [targetSchemeId]: map }));
    } catch (err) {
      setError(err?.message || "Failed to load mapping");
    }
  }

  async function saveMapping(targetSchemeId) {
    if (!hoaId || !assetId || !targetSchemeId) return;
    const map = mappingByScheme[targetSchemeId] || {};
    setSaving(true);
    setError("");
    try {
      const items = Object.entries(map || {}).map(([mask_role, scheme_role]) => ({
        mask_role,
        scheme_role,
      }));
      const res = await fetch(MASK_MAP_SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          hoa_id: Number(hoaId),
          scheme_id: Number(targetSchemeId),
          asset_id: assetId,
          items,
        }),
      });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Failed to save mapping");
      }
      setDirtyByScheme((prev) => ({ ...prev, [targetSchemeId]: false }));
    } catch (err) {
      setError(err?.message || "Failed to save mapping");
    } finally {
      setSaving(false);
    }
  }

  async function saveAllMappings() {
    if (!hoaId || !assetId) return;
    const schemeIds = Object.keys(dirtyByScheme).filter((id) => dirtyByScheme[id]);
    if (!schemeIds.length) return;
    setSaving(true);
    setError("");
    try {
      for (const id of schemeIds) {
        const map = mappingByScheme[id] || {};
        const items = Object.entries(map || {}).map(([mask_role, scheme_role]) => ({
          mask_role,
          scheme_role,
        }));
        const res = await fetch(MASK_MAP_SAVE_URL, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            hoa_id: Number(hoaId),
            scheme_id: Number(id),
            asset_id: assetId,
            items,
          }),
        });
        const data = await res.json().catch(() => null);
        if (!res.ok || !data?.ok) {
          throw new Error(data?.error || "Failed to save mapping");
        }
      }
      setDirtyByScheme((prev) => {
        const next = { ...prev };
        schemeIds.forEach((id) => {
          next[id] = false;
        });
        return next;
      });
    } catch (err) {
      setError(err?.message || "Failed to save mapping");
    } finally {
      setSaving(false);
    }
  }

  const schemeRoleOptions = useMemo(() => {
    const groups = parseRoleGroupsFromColors(schemeColors);
    if (!groups.length) return [];
    return groups;
  }, [schemeColors]);

  const schemeColorsByRole = useMemo(() => {
    const map = new Map();
    const allColors = Array.isArray(schemeColors) ? schemeColors : [];
    schemeRoleOptions.forEach((group) => {
      const roleTokens = normalizeRoleTokens(group);
      const filtered = allColors.filter((row) => {
        const allowed = String(row?.allowed_roles || "").trim().toLowerCase();
        if (!allowed || allowed === "any") return true;
        const allowedTokens = normalizeRoleTokens(allowed);
        return allowedTokens.some((token) => roleTokens.includes(token));
      });
      map.set(group, filtered);
    });
    return map;
  }, [schemeColors, schemeRoleOptions]);

  const currentMapping = useMemo(() => {
    if (!schemeId) return {};
    return mappingByScheme[schemeId] || {};
  }, [mappingByScheme, schemeId]);

  function updateMapping(maskRole, value) {
    if (!schemeId) return;
    setMappingByScheme((prev) => {
      const next = { ...prev };
      const map = { ...(next[schemeId] || {}) };
      if (!value) {
        delete map[maskRole];
      } else {
        map[maskRole] = value;
      }
      next[schemeId] = map;
      return next;
    });
    setDirtyByScheme((prev) => ({ ...prev, [schemeId]: true }));
  }

  function applyToAllSchemes(baseMap) {
    if (!schemeId) return;
    setMappingByScheme((prev) => {
      const next = { ...prev };
      const base = baseMap || prev[schemeId] || {};
      schemes.forEach((scheme) => {
        next[scheme.id] = { ...base };
      });
      return next;
    });
    setDirtyByScheme((prev) => {
      const next = { ...prev };
      schemes.forEach((scheme) => {
        next[scheme.id] = true;
      });
      return next;
    });
  }

  const unmappedMasks = useMemo(() => {
    if (!asset?.masks?.length) return [];
    return asset.masks
      .map((m) => m.role)
      .filter((role) => !currentMapping[role]);
  }, [asset?.masks, currentMapping]);

  const autoMapPreview = useMemo(() => {
    if (!schemeRoleOptions.length || !asset?.masks?.length) return {};
    return computeAutoMapping(asset.masks, schemeRoleOptions);
  }, [asset?.masks, schemeRoleOptions]);

  function applyAutoMap(force = false, applyAll = false) {
    if (!schemeId || !asset?.masks?.length || !schemeRoleOptions.length) return;
    const computed = computeAutoMapping(asset.masks, schemeRoleOptions);
    setMappingByScheme((prev) => {
      const existing = prev[schemeId] || {};
      if (force) {
        const updated = { ...existing, ...computed };
        if (applyAll) {
          const next = { ...prev, [schemeId]: updated };
          schemes.forEach((scheme) => {
            next[scheme.id] = { ...updated };
          });
          return next;
        }
        return { ...prev, [schemeId]: updated };
      }
      let changed = false;
      const nextMap = { ...existing };
      Object.entries(computed).forEach(([maskRole, group]) => {
        if (nextMap[maskRole]) return;
        nextMap[maskRole] = group;
        changed = true;
      });
      if (!changed) return prev;
      if (applyAll) {
        const next = { ...prev, [schemeId]: nextMap };
        schemes.forEach((scheme) => {
          next[scheme.id] = { ...nextMap };
        });
        return next;
      }
      return { ...prev, [schemeId]: nextMap };
    });
    if (applyAll) {
      setDirtyByScheme((prev) => {
        const next = { ...prev };
        schemes.forEach((scheme) => {
          next[scheme.id] = true;
        });
        return next;
      });
    } else {
      setDirtyByScheme((prev) => ({ ...prev, [schemeId]: true }));
    }
  }

  return (
    <div className="hoa-scheme-tester">
      <header className="hst-header">
        <div>
          <div className="hst-title">HOA Scheme Mapper</div>
          <div className="hst-subtitle">Map photo masks to scheme roles before testing.</div>
        </div>
        <div className="hst-actions">
          <button type="button" onClick={() => navigate("/admin/hoas")}>Back to HOAs</button>
          <button
            type="button"
            className="primary-btn"
            onClick={() => {
              if (!asset?.asset_id) return;
              const nav = new URLSearchParams();
              if (hoaId) nav.set("hoa", hoaId);
              if (schemeId) nav.set("scheme", schemeId);
              nav.set("asset", asset.asset_id);
              navigate(`/admin/hoa-mask-tester?${nav.toString()}`);
            }}
            disabled={!asset?.asset_id}
          >
            Open in HOA Mask Tester
          </button>
        </div>
      </header>

      {error && <div className="hst-status error">{error}</div>}

      <section className="hst-panel">
        <div className="hst-grid">
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
            <select value={schemeId} onChange={(e) => setSchemeId(e.target.value)} disabled={!hoaId}>
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
            <div className="hst-inline">
              <input
                type="text"
                value={assetId}
                onChange={(e) => setAssetId(e.target.value)}
                placeholder="e.g., 12345"
              />
              <button type="button" onClick={loadAsset} disabled={!assetId || loadingAsset}>
                {loadingAsset ? "Loading…" : "Load"}
              </button>
              <button type="button" onClick={() => setFindOpen((v) => !v)}>
                {findOpen ? "Hide Finder" : "Find Photo"}
              </button>
            </div>
          </label>
        </div>
        {findOpen && (
          <div className="hst-finder">
            <PhotoSearchPicker onPick={(item) => loadAsset(item.asset_id)} />
          </div>
        )}
      </section>

      <section className="hst-panel">
        <div className="hst-panel-header">
          <div className="hst-panel-title">Mask → Scheme Role Mapping</div>
          <div className="hst-panel-actions">
            <button type="button" className="primary-btn" onClick={() => saveMapping(schemeId)} disabled={!schemeId || !dirtyByScheme[schemeId] || saving}>
              {saving ? "Saving…" : "Save Changes"}
            </button>
            <button type="button" onClick={saveAllMappings} disabled={!Object.values(dirtyByScheme).some(Boolean) || saving}>
              Save All Schemes
            </button>
            <button type="button" onClick={() => applyAutoMap(true, true)} disabled={!schemeId}>
              Auto-map Matches
            </button>
            <button type="button" className="primary-btn" onClick={() => applyToAllSchemes(currentMapping)} disabled={!schemeId}>
              Apply to All Schemes
            </button>
          </div>
        </div>
        {!asset?.masks?.length && (
          <div className="hst-status">Load a photo to see masks.</div>
        )}
        {asset?.masks?.length > 0 && (
          <>
            {schemeRoleOptions.length > 0 && (
              <div className="hst-hint">
                Auto matches available: {Object.keys(autoMapPreview).length}
              </div>
            )}
            {unmappedMasks.length > 0 && (
              <div className="hst-hint">
                Unmapped masks: {unmappedMasks.join(", ")}
              </div>
            )}
            <div className="hst-mapping">
              <div className="hst-row hst-row-header">
                <div>Photo.</div>
                <div>Scheme</div>
                <div>Colors</div>
              </div>
              {asset.masks.map((mask) => (
                <div key={mask.role} className="hst-row">
                  <div className="hst-mask">{mask.role}</div>
                  <select
                    value={currentMapping[mask.role] || ""}
                    onChange={(e) => updateMapping(mask.role, e.target.value)}
                    disabled={!schemeId}
                  >
                    <option value="">No match</option>
                    {schemeRoleOptions.map((role) => (
                      <option key={role} value={role}>
                        {role}
                      </option>
                    ))}
                  </select>
                  <div className="hst-colors">
                    {(schemeColorsByRole.get(currentMapping[mask.role] || "") || []).length === 0 && (
                      <span className="hst-colors-empty">No scheme colors</span>
                    )}
                    {(schemeColorsByRole.get(currentMapping[mask.role] || "") || []).map((color) => (
                      <div key={`${mask.role}-${color.color_id}`} className="hst-color-chip">
                        <span
                          className="hst-color-swatch"
                          style={{ backgroundColor: color.hex6 ? `#${color.hex6}` : "#ccc" }}
                        />
                        <span className="hst-color-name">{color.name || `Color #${color.color_id}`}</span>
                        {color.brand && <span className="hst-color-brand">{color.brand}</span>}
                      </div>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          </>
        )}
      </section>
    </div>
  );
}
