// src/pages/PaletteTranslationPage.jsx
import React, { useEffect, useMemo, useState } from "react";
import { useParams, useLocation } from "react-router-dom";
import { useAppState } from "@context/AppStateContext";
import { API_FOLDER as API } from "@helpers/config";
import SwatchGallery from "@components/SwatchGallery";
import PaletteSwatch from "@components/Swatches/PaletteSwatch";

export default function PaletteTranslationPage() {
  const { id } = useParams();           // designer flow: /palette/:id/brands
  const { search } = useLocation();     // user flow: /palette/translate?clusters=1,2,3
  const { palette } = useAppState();    // fallback if no query (in-app navigation)

  const palette_id = useMemo(() => Number(id ?? 0), [id]);





  // Parse cluster ids from the querystring (?clusters=12,34,56)
  const clustersFromQuery = useMemo(() => {
    const sp = new URLSearchParams(search);
    const raw = (sp.get("clusters") || "").trim();
    if (!raw) return [];
    return raw
      .split(",")
      .map(s => Number((s || "").trim()))
      .filter(n => Number.isFinite(n) && n > 0);
  }, [search]);

  // Fallback: derive cluster ids from AppState palette (in case you navigated internally)
  const clustersFromState = useMemo(() => {
    const arr = Array.isArray(palette) ? palette : [];
    const seen = new Set();
    for (const item of arr) {
      const sw = item?.color ?? item; // support either shape
      const cid = Number(sw?.cluster_id ?? sw?.clusterId ?? 0);
      if (cid > 0) seen.add(cid);
    }
    return Array.from(seen);
  }, [palette]);

  // Effective source cluster ids: query wins (so links are reproducible), else AppState
  const cluster_ids = clustersFromQuery.length ? clustersFromQuery : clustersFromState;

  const [state, setState] = useState({
    loading: true,
    error: "",
    items: [],
    brands: [],
    failures: [],
    count: 0,
    src_count: 0,
    src_kind: "", // "palette" or "clusters"
  });

  // Build request payload (palette_id takes precedence if present)
  const payload = useMemo(() => {
    if (palette_id > 0) return { palette_id };
    if (cluster_ids.length > 0) return { cluster_ids };
    return null;
  }, [palette_id, cluster_ids]);

// and keep the grouping fields on the same object for SwatchGallery.
const galleryItems = useMemo(() => {
  const arr = Array.isArray(state.items) ? state.items : [];
  return arr.map(it => ({
    ...it.color,                       // r, g, b, id, name, brand, etc.
    brand_name: it.brand_name ?? it.color?.brand_name ?? "", // header text
    group_order: it.group_order ?? 9999,                     // section order
  }));
}, [state.items]);


/*  fly  */
useEffect(() => {
  let cancelled = false;

  async function run() {
    if (!payload) {
      setState(s => ({
        ...s,
        loading: false,
        error: "Missing source (palette_id or cluster_ids).",
        items: [],
        brands: [],
        failures: [],
        count: 0,
        src_count: 0,
        src_kind: "",
      }));
      return;
    }

    setState(s => ({ ...s, loading: true, error: "" }));

    try {
      const params = new URLSearchParams();
      if (payload?.palette_id) params.set("palette_id", String(payload.palette_id));
      if (Array.isArray(payload?.cluster_ids) && payload.cluster_ids.length) {
        // cluster IDs are numbers only
        params.set("clusters", payload.cluster_ids.join(","));
      }

      const res = await fetch(`${API}/translate-palette-controller.php?${params.toString()}`, {
        method: "GET",
        headers: { Accept: "application/json" },
      });

      const text = await res.text();
      console.log('returned:' , text);
      if (cancelled) return;

      if (!res.ok) {
        setState(s => ({
          ...s,
          loading: false,
          error: `HTTP ${res.status}: ${text.slice(0, 400)}`,
          items: [],
          brands: [],
          failures: [],
          count: 0,
          src_count: 0,
          src_kind: "",
        }));
        return;
      }

      let json;
      try {
        json = JSON.parse(text);
      } catch {
        console.error("Non-JSON from API (first 800 chars):\n", text.slice(0, 800));
        setState(s => ({
          ...s,
          loading: false,
          error: "API did not return JSON. Check API path / proxy / PHP errors.",
          items: [],
          brands: [],
          failures: [],
          count: 0,
          src_count: 0,
          src_kind: "",
        }));
        return;
      }

      if (!json?.ok) {
        setState(s => ({
          ...s,
          loading: false,
          error: json?.error || "Translation failed.",
          items: [],
          brands: [],
          failures: json?.failures ?? [],
          count: 0,
          src_count: 0,
          src_kind: json?.src_kind || "",
        }));
        return;
      }

   // success path inside useEffect
setState({
  loading: false,
  error: "",
  items: json.items ?? [],
  brands: json.brands ?? [],
  failures: json.failures ?? [],
  count: Number(json.count ?? 0),
  src_count: Number(json.src_count ?? 0),
  src_kind: json.src_kind || "",
});
    } catch (err) {
      if (cancelled) return;
      setState(s => ({
        ...s,
        loading: false,
        error: String(err),
        items: [],
        brands: [],
        failures: [],
        count: 0,
        src_count: 0,
        src_kind: "",
      }));
    }
  }

  run();
  return () => { cancelled = true; };
}, [payload]);
 
  // Group items by brand in API order



// ----- RENDER -----
if (state.loading) {
  return (
    <div className="page translation-page">
      <h2>Translating palette…</h2>
    </div>
  );
}

if (state.error) {
  return (
    <div className="page translation-page">
      <h2>Translate Palette</h2>
      <p style={{ color: "#b00", marginTop: 8 }}>{state.error}</p>
    </div>
  );
}

const headerNote = state.src_count > 0
  ? `Complete matches in ${state.brands.length} brand${state.brands.length === 1 ? "" : "s"} • ${state.src_count} colors each`
  : `No source colors`;

return (
  <div className="page translation-page">
    <div className="translation-header">
      <h2>Your Palette in All Brands</h2>
      <h4>Closest calculated colors</h4>
    </div>
<SwatchGallery
  className="sg-results sw-edge"
  items={state.items}
  SwatchComponent={PaletteSwatch}
  swatchPropName="color"
  gap={5}
  minWidth={140}
  itemMaxWidth={200}   // ← this now caps each grid track width
  aspectRatio="5 / 4"
  groupBy="brand_name"
  groupOrderBy="group_order"
  showGroupHeaders
/>


    {state.failures?.length > 0 && (
      <details style={{ marginTop: 24 }}>
        <summary>Diagnostics</summary>
        <pre style={{ whiteSpace: "pre-wrap" }}>
          {JSON.stringify(state.failures, null, 2)}
        </pre>
      </details>
    )}
  </div>
);

}