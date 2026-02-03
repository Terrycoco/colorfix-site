import { useEffect, useMemo, useState } from "react";
import { useParams } from "react-router-dom";
import PaletteViewer from "@components/PaletteViewer";
import "./saved-palette-share.css";

export default function SavedPaletteSharePage() {
  const { hash } = useParams();
  const [state, setState] = useState({ loading: true, error: "", data: null });

  useEffect(() => {
    if (!hash) return;
    const controller = new AbortController();
    setState({ loading: true, error: "", data: null });
    fetch(`/api/v2/palette-viewer.php?source=saved&hash=${encodeURIComponent(hash)}`, {
      signal: controller.signal,
    })
      .then((r) => r.json())
      .then((res) => {
        if (!res?.ok || !res?.data) throw new Error(res?.error || "Failed to load palette");
        setState({ loading: false, error: "", data: res.data });
      })
      .catch((err) => {
        if (controller.signal.aborted) return;
        setState({ loading: false, error: err?.message || "Failed to load palette", data: null });
      });
    return () => {
      controller.abort();
    };
  }, [hash]);

  if (state.loading) {
    return (
      <div className="saved-palette-share">
        <div className="sps-card">Loading palette…</div>
      </div>
    );
  }

  if (state.error) {
    return (
      <div className="saved-palette-share">
        <div className="sps-card sps-error">{state.error}</div>
      </div>
    );
  }

  const meta = state.data?.meta || null;
  const swatches = state.data?.swatches || [];

  return (
    <PaletteViewer
      meta={meta}
      swatches={swatches}
      adminMode={false}
      showBackButton={true}
      showLogo={true}
      showShare={true}
    />
  );
}
