import { useEffect, useMemo, useState } from "react";
import { useParams, useSearchParams } from "react-router-dom";
import AppliedPaletteViewer from "@components/AppliedPaletteViewer";

const API_URL = "/api/v2/applied-palettes/render.php";

export default function AppliedPaletteViewPage() {
  const { paletteId } = useParams();
  const paletteNumericId = useMemo(() => {
    const parsed = Number(paletteId);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
  }, [paletteId]);
  const [searchParams] = useSearchParams();
  const [state, setState] = useState({ loading: true, error: "", data: null });
  const isAdminView = (searchParams.get("admin") || "").toString() === "1";

  useEffect(() => {
    if (!paletteNumericId) return;
    setState({ loading: true, error: "", data: null });
    const controller = new AbortController();
    fetch(`${API_URL}?id=${paletteNumericId}`, { signal: controller.signal })
      .then((r) => r.json())
      .then((res) => {
        if (!res?.ok) throw new Error(res?.error || "Failed to load palette");
        setState({ loading: false, error: "", data: res });
      })
      .catch((err) => {
        if (controller.signal.aborted) return;
        setState({ loading: false, error: err?.message || "Failed to load", data: null });
      });
    return () => controller.abort();
  }, [paletteNumericId]);

  if (!paletteNumericId) {
    return <div className="apv-page">Missing palette id.</div>;
  }

  if (state.loading) {
    return <div className="apv-page">Rendering palette…</div>;
  }

  if (state.error) {
    return (
      <div className="apv-page">
        <div className="apv-error">{state.error}</div>
      </div>
    );
  }

  const { render, palette, entries = [] } = state.data || {};

  const handleBack = () => {
    if (isAdminView) {
      window.location.href = "/admin/applied-palettes";
    } else if (window.history.length > 1) {
      window.history.back();
    } else {
      window.location.href = "/";
    }
  };

  return (
    <AppliedPaletteViewer
      palette={palette}
      renderInfo={render}
      entries={entries}
      adminMode={isAdminView}
      onBack={isAdminView ? handleBack : undefined}
      showBackButton={isAdminView}
      shareEnabled={isAdminView}
    />
  );
}
