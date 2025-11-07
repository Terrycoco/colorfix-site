// MatchesPage.jsx
import React, { useEffect, useMemo, useState } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { API_FOLDER as API } from "@helpers/config";
import SwatchGallery from "@components/SwatchGallery";
import PaletteSwatch from "@components/Swatches/PaletteSwatch";
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";

const v1 = '/match-controller.php';
const v2 = '/v2/translate.php';

export default function MatchResultsPage() {
  const navigate = useNavigate();
  const { search } = useLocation();

  const sourceId = useMemo(() => {
    const sp = new URLSearchParams(search);
    const v = sp.get("source_id");
    return v && /^\d+$/.test(v) ? Number(v) : null;
  }, [search]);

  const [err, setErr] = useState("");
  const [rawItems, setRawItems] = useState([]);
  const [sourceMeta, setSourceMeta] = useState(null);     // what match-controller returns
  const [sourceSwatch, setSourceSwatch] = useState(null); // full swatch_enriched row for PaletteSwatch

  // 1) Fetch the matches (controller)
  useEffect(() => {
    if (!sourceId) { setErr(""); setSourceMeta(null); setRawItems([]); return; }
    const url = `${API}/${v2}?source_id=${sourceId}&ts=${Date.now()}`;
    const ac = new AbortController();
    (async () => {
      try {
        const r = await fetch(url, { signal: ac.signal, credentials: "same-origin" });
        const j = await r.json();
        if (!j.ok) { setErr(j.error || "Match failed"); setRawItems([]); setSourceMeta(null); return; }
        console.log('data: ', j);
        setErr("");
        setSourceMeta(j.source || null);
        setRawItems(Array.isArray(j.items) ? j.items : []);
      } catch (e) {
        if (!ac.signal.aborted) { setErr(String(e.message || e)); setRawItems([]); setSourceMeta(null); }
      }
    })();
    return () => ac.abort();
  }, [sourceId, API]);

  // 2) Hydrate the *full* source swatch for display (separate API)
  useEffect(() => {
    if (!sourceId) { setSourceSwatch(null); return; }
    const url = `${API}/get-swatch-by-id.php?id=${sourceId}&ts=${Date.now()}`;
    const ac = new AbortController();
    (async () => {
      try {
        const r = await fetch(url, { signal: ac.signal, credentials: "same-origin" });
        const j = await r.json();

        // Be flexible about the payload shape:
        // - if endpoint returns { ok:true, swatch:{...} } use j.swatch
        // - if it returns the row itself, use j
        const row = (j && typeof j === 'object')
          ? (j.swatch ?? j.data ?? j)
          : null;

        if (row && row.id) {
          // normalize hex if needed so PaletteSwatch is happy
          const hex = row.hex ?? (row.hex6 ? `#${row.hex6}` : undefined);
          setSourceSwatch({ ...row, ...(hex ? { hex } : {}) });
        } else {
          setSourceSwatch(null);
        }
      } catch (e) {
        if (!ac.signal.aborted) setSourceSwatch(null);
      }
    })();
    return () => ac.abort();
  }, [sourceId, API]);

  // Flatten controller items → gallery items
// Flatten controller items → gallery items (keep `color` nested for SwatchGallery)
const items = useMemo(() => {
  return (rawItems || [])
    .filter((it) => it && it.color)
    .map((it) => ({
      group_header: it.group_header,
      group_order: it.group_order,
      delta_e: it.delta_e,
      is_twin: it.is_twin,
      color: it.color, // ← keep nested so SwatchGallery passes it to PaletteSwatch
    }));
}, [rawItems]);

  function handlePick(c) {
    if (!c?.id) return;
    navigate(`/matches?source_id=${c.id}`);
  }

  return (
    <div style={{ padding: "0 0 5rem 0" }}>
      <div style={{display: "flex", flexDirection: "column", alignItems: "center"}}>
        <h1 style={{ margin: "0 0 12px" }}>Match a Paint Color</h1>

        <div style={{ maxWidth: 520, marginBottom: 12 }}>
          <FuzzySearchColorSelect onSelect={handlePick} />
        </div>

        {err && <div style={{ color: "#b42318", marginBottom: 12 }}>{err}</div>}

        {/* Source swatch at the top */}
        {sourceSwatch && (
          <div style={{ marginBottom: 12, width: '280px' }}>
            <PaletteSwatch color={sourceSwatch} />
          </div>
        )}
      </div>
      <SwatchGallery
        className="sg-results"
        items={items}
        SwatchComponent={PaletteSwatch}
        swatchPropName="color"
        groupBy="group_header"
        groupOrderBy="group_order"
        showGroupHeaders
        gap={12}
        aspectRatio="5 / 4"
        emptyMessage={sourceId ? "No matches found." : "Pick a color to begin."}
      />
    </div>
  );
}
