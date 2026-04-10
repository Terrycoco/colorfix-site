import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams, useSearchParams } from "react-router-dom";
import PaletteViewer from "@components/PaletteViewer";
import { getLastPlaylistInstanceId } from "@helpers/playlistHistory";

const API_URL = "/api/v2/palette-viewer.php";
export default function AppliedPaletteViewPage() {
  const { paletteId } = useParams();
  const navigate = useNavigate();
  const paletteNumericId = useMemo(() => {
    const parsed = Number(paletteId);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
  }, [paletteId]);
  const [searchParams] = useSearchParams();
  const [state, setState] = useState({ loading: true, error: "", data: null });
  const [shareStatus, setShareStatus] = useState("");
  const [shareSheetOpen, setShareSheetOpen] = useState(false);
  const [lastPlaylistInstanceId, setLastPlaylistInstanceId] = useState(() => getLastPlaylistInstanceId());
  const isAdminView = (searchParams.get("admin") || "").toString() === "1";
  const ctaAudience = searchParams.get("aud") ?? "";
  const psiParam = searchParams.get("psi") ?? "";
  const isHoaView = ctaAudience.toLowerCase() === "hoa";
  const photoUrlParam = useMemo(() => searchParams.get("photo_url") ?? "", [searchParams]);

  useEffect(() => {
    if (!paletteNumericId) return;
    setState({ loading: true, error: "", data: null });
    const controller = new AbortController();
    const psiQuery = psiParam ? `&psi=${encodeURIComponent(psiParam)}` : "";
    const forcedPhotoUrl = photoUrlParam?.trim() ?? "";
    fetch(`${API_URL}?source=applied&id=${paletteNumericId}${psiQuery}`, { signal: controller.signal })
      .then((r) => r.json())
      .then((res) => {
        if (!res?.ok || !res?.data) throw new Error(res?.error || "Failed to load palette");
        const normalized = res.data ? { ...res.data } : null;
        if (normalized && forcedPhotoUrl) {
          normalized.meta = {
            ...(normalized.meta || {}),
            photo_url: forcedPhotoUrl,
          };
        }
        setState({ loading: false, error: "", data: normalized });
      })
      .catch((err) => {
        if (controller.signal.aborted) return;
        setState({ loading: false, error: err?.message || "Failed to load", data: null });
      });
    return () => controller.abort();
  }, [paletteNumericId, psiParam, photoUrlParam]);

  useEffect(() => {
    setLastPlaylistInstanceId(getLastPlaylistInstanceId());
  }, []);

  const handleBack = () => {
    if (isAdminView) {
      window.location.href = "/admin/applied-palettes";
    } else if (isHoaView) {
      window.location.href = "/hoa";
    } else if (window.history.length > 1) {
      window.history.back();
    } else {
      window.location.href = "/";
    }
  };

  const data = state.data;
  const meta = data?.meta || null;
  const swatches = data?.swatches || [];

  const shouldShowBackToPlaylistButton =
    Boolean(lastPlaylistInstanceId) &&
    Boolean(data?.playlist_instance_id) &&
    String(lastPlaylistInstanceId) === String(data.playlist_instance_id);

  const handleBackToPlaylist = () => {
    if (!lastPlaylistInstanceId) return;
    navigate(`/playlist/${lastPlaylistInstanceId}`);
  };

  const shareUrl = useMemo(() => {
    if (typeof window === "undefined") return "";
    const hash = meta?.palette_hash || "";
    if (hash) {
      return `${window.location.origin}/palette/${hash}/share`;
    }
    return window.location.href || "";
  }, [meta?.palette_hash]);

  const shareMessage = `I'm sharing a palette I found on ColorFix: ${shareUrl}`;
  const smsLink = `sms:&body=${encodeURIComponent(shareMessage)}`;
  const emailLink = `mailto:?subject=${encodeURIComponent("Your ColorFix Palette")}&body=${encodeURIComponent(shareMessage)}`;

  const copyLink = async () => {
    if (!shareUrl) return;
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(shareUrl);
      } else {
        const tmp = document.createElement("textarea");
        tmp.value = shareUrl;
        document.body.appendChild(tmp);
        tmp.select();
        document.execCommand("copy");
        tmp.remove();
      }
      setShareStatus("Link copied");
    } catch (err) {
      setShareStatus(err?.message || "Unable to copy");
    }
  };

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

  const paletteFooter = (
    <div className="apv-footer">
      {shouldShowBackToPlaylistButton && (
        <button
          type="button"
          className="apv-btn apv-btn--ghost apv-back-to-playlist"
          onClick={handleBackToPlaylist}
        >
          Back to playlist
        </button>
      )}
    </div>
  );

  return (
    <>
      <PaletteViewer
        meta={meta}
        swatches={swatches}
        adminMode={isAdminView}
        onBack={isAdminView ? handleBack : undefined}
        showBackButton={isAdminView}
        showLogo={!isAdminView}
        showShare={true}
        footer={paletteFooter}
      />
      {shareSheetOpen && (
        <div className="apv-share-modal" role="dialog" aria-modal="true">
          <div className="apv-share-panel">
            <button className="apv-share-close" onClick={() => setShareSheetOpen(false)} aria-label="Close share options">
              ×
            </button>
            <h3>Share Palette</h3>
            <label>
              Link
              <input type="text" readOnly value={shareUrl} onFocus={(e) => e.target.select()} />
            </label>
            {shareStatus && <div className="apv-share-status">{shareStatus}</div>}
            <button className="apv-btn apv-btn--copy" onClick={copyLink}>
              Copy Link
            </button>
            <a className="apv-share-option" href={smsLink}>
              Text Link
            </a>
            <a className="apv-share-option" href={emailLink}>
              Email Link
            </a>
            <button className="apv-btn apv-btn--ghost" onClick={() => setShareSheetOpen(false)}>
              Done
            </button>
          </div>
        </div>
      )}
    </>
  );
}
