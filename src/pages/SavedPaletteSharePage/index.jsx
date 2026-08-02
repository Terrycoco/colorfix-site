import { useEffect, useMemo, useState } from "react";
import { useParams } from "react-router-dom";
import ConceptPaletteViewer from "@components/ConceptPaletteViewer";
import PaletteViewer from "@components/PaletteViewer";
import "./saved-palette-share.css";

export default function SavedPaletteSharePage() {
  const { hash, token } = useParams();
  const [state, setState] = useState({ loading: true, error: "", data: null });
  const returnTo = useMemo(() => {
    if (typeof window === "undefined") return "/";
    const params = new URLSearchParams(window.location.search);
    const value = params.get("return_to") || "";
    return value.startsWith("/") ? value : "/";
  }, []);
  const setId = useMemo(() => {
    if (typeof window === "undefined") return "";
    const params = new URLSearchParams(window.location.search);
    return params.get("set_id") || "";
  }, []);
  const backLabel = returnTo.startsWith("/p/") || returnTo.startsWith("/playlist/")
    ? "Back to Playlist"
    : "← Back";
  useEffect(() => {
    if (!hash && !token) return;
    const controller = new AbortController();
    setState({ loading: true, error: "", data: null });
    const params = new URLSearchParams();
    if (token) {
      params.set("token", token);
    } else if (/^\d+$/.test(String(hash))) {
      params.set("source", "saved");
      params.set("id", hash);
    } else {
      params.set("source", "saved");
      params.set("hash", hash);
    }
    if (!token && Number(setId || 0) > 0) {
      params.set("set_id", String(Number(setId)));
    }
    fetch(`/api/v2/palette-viewer.php?${params.toString()}`, {
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
  }, [hash, token, setId]);

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
  const playlistUrl = meta?.playlist_url || "";
  const playlistCtaLabel = meta?.cta_label || "View Playlist";
  const isConcept = meta?.palette_viewer_key === "concept";
  const ViewerComponent = isConcept ? ConceptPaletteViewer : PaletteViewer;

  return (
    <ViewerComponent
      meta={meta}
      swatches={swatches}
      adminMode={false}
      showBackButton={true}
      backLabel={backLabel}
      onBack={() => {
        if (typeof window !== "undefined") {
          if (returnTo !== "/") {
            window.location.href = returnTo;
          } else if (window.history.length > 1) {
            window.history.back();
          } else {
            window.location.href = "/";
          }
        }
      }}
      onExit={() => {
        if (typeof window !== "undefined") {
          if (returnTo !== "/") {
            window.location.href = returnTo;
          } else if (window.history.length > 1) {
            window.history.back();
          } else {
            window.location.href = "/";
          }
        }
      }}
      showLogo={true}
      showShare={true}
      playlistUrl={playlistUrl}
      footer={!isConcept && playlistUrl ? (
        <button
          type="button"
          className="apv-btn apv-btn--ghost apv-back-to-playlist"
          onClick={() => {
            window.location.href = playlistUrl;
          }}
        >
          {playlistCtaLabel}
        </button>
      ) : null}
    />
  );
}
