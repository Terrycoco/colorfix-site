import { useEffect, useMemo, useState } from "react";
import { useParams, useLocation } from "react-router-dom";
import { useAppState } from "@context/AppStateContext";
import { API_FOLDER as API } from "@helpers/config";
import SwatchGallery from "@components/SwatchGallery";
import PaletteSwatch from "@components/Swatches/PaletteSwatch";
import { positiveIds, requestTranslation, translateColors } from "./translatePalette";

const EMPTY_RESULT = { items: [], brands: [], failures: [], count: 0, src_count: 0 };

export default function PaletteTranslationPage() {
  const { id } = useParams();
  const { search } = useLocation();
  const { palette } = useAppState();
  const [attempt, setAttempt] = useState(0);
  const [state, setState] = useState({ ...EMPTY_RESULT, loading: true, error: "", completed: 0 });

  const source = useMemo(() => {
    const params = new URLSearchParams(search);
    const paletteId = Number(id || 0);
    if (paletteId > 0) return { kind: "palette", ids: [paletteId] };
    if (params.has("ids")) return { kind: "colors", ids: positiveIds(params.get("ids").split(",")) };
    // Preserve previously shared cluster links and designer palette routes.
    if (params.has("clusters")) return { kind: "clusters", ids: positiveIds(params.get("clusters").split(",")) };
    return { kind: "colors", ids: positiveIds((Array.isArray(palette) ? palette : []).map((item) => (item?.color ?? item)?.id)) };
  }, [id, search, palette]);

  // Depend on source values, not collection object identity during state hydration.
  const sourceKind = source.kind;
  const sourceKey = source.ids.join(",");
  useEffect(() => {
    const controller = new AbortController();
    const ids = positiveIds(sourceKey.split(","));
    if (!ids.length) {
      setState({ ...EMPTY_RESULT, loading: false, error: "Add colors to MyPalette before translating.", completed: 0 });
      return () => controller.abort();
    }
    setState({ ...EMPTY_RESULT, loading: true, error: "", completed: 0 });
    async function run() {
      try {
        let result;
        if (sourceKind === "colors") {
          result = await translateColors(API, ids, {
            signal: controller.signal,
            onProgress: (completed) => {
              if (!controller.signal.aborted) setState((previous) => ({ ...previous, completed }));
            },
          });
        } else {
          const params = new URLSearchParams();
          params.set(sourceKind === "palette" ? "palette_id" : "clusters", sourceKey);
          result = await requestTranslation(`${API}/translate-palette-controller.php?${params}`, { signal: controller.signal });
        }
        if (!controller.signal.aborted) setState({ ...EMPTY_RESULT, ...result, loading: false, error: "", completed: ids.length });
      } catch (error) {
        if (!controller.signal.aborted) setState({ ...EMPTY_RESULT, loading: false, error: error.message || "Translation failed. Please try again.", completed: 0 });
      }
    }
    void run();
    return () => controller.abort();
  }, [sourceKind, sourceKey, attempt]);

  if (state.loading) {
    return <div className="page translation-page">
      <h2>Translating palette…</h2>
      {sourceKind === "colors" && <p role="status">{state.completed} of {source.ids.length} colors translated</p>}
    </div>;
  }

  if (state.error) {
    return <div className="page translation-page">
      <h2>Translate Palette</h2>
      <p role="alert" style={{ color: "#b00", marginTop: 8 }}>{state.error}</p>
      {source.ids.length > 0 && <button type="button" onClick={() => setAttempt((value) => value + 1)}>Try again</button>}
    </div>;
  }

  return <div className="page translation-page">
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
      itemMaxWidth={200}
      aspectRatio="5 / 4"
      groupBy="brand_name"
      groupOrderBy="group_order"
      showGroupHeaders
      emptyMessage="No brand has a complete set of distinct matches for these colors. Try a smaller palette."
    />
    {state.failures?.length > 0 && <details style={{ marginTop: 24 }}>
      <summary>Diagnostics</summary>
      <pre style={{ whiteSpace: "pre-wrap" }}>{JSON.stringify(state.failures, null, 2)}</pre>
    </details>}
  </div>;
}
