import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams, useSearchParams } from "react-router-dom";
import PaletteViewer from "@components/PaletteViewer";
import CTASection from "@components/CTASection";
import { buildCtaHandlers, getCtaKey } from "@helpers/ctaActions";

const API_URL = "/api/v2/palette-viewer.php";
const CTA_GROUP_BY_AUDIENCE_URL = "/api/v2/cta-groups/by-audience.php";
const CTA_GROUP_ITEMS_URL = "/api/v2/cta-group-items/list.php";

export default function AppliedPaletteViewPage() {
  const { paletteId } = useParams();
  const navigate = useNavigate();
  const paletteNumericId = useMemo(() => {
    const parsed = Number(paletteId);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
  }, [paletteId]);
  const [searchParams] = useSearchParams();
  const [state, setState] = useState({ loading: true, error: "", data: null });
  const [ctaItems, setCtaItems] = useState([]);
  const [shareStatus, setShareStatus] = useState("");
  const [shareSheetOpen, setShareSheetOpen] = useState(false);
  const isAdminView = (searchParams.get("admin") || "").toString() === "1";
  const ctaAudience = searchParams.get("aud") ?? "";
  const addCtaGroup = searchParams.get("add_cta_group") ?? "";
  const psiParam = searchParams.get("psi") ?? "";
  const thumbParam = searchParams.get("thumb") ?? "";
  const demoParam = searchParams.get("demo") ?? "";
  const isHoaView = ctaAudience.toLowerCase() === "hoa";

  useEffect(() => {
    if (!paletteNumericId) return;
    setState({ loading: true, error: "", data: null });
    const controller = new AbortController();
    const psiQuery = psiParam ? `&psi=${encodeURIComponent(psiParam)}` : "";
    fetch(`${API_URL}?source=applied&id=${paletteNumericId}${psiQuery}`, { signal: controller.signal })
      .then((r) => r.json())
      .then((res) => {
        if (!res?.ok || !res?.data) throw new Error(res?.error || "Failed to load palette");
        setState({ loading: false, error: "", data: res.data });
      })
      .catch((err) => {
        if (controller.signal.aborted) return;
        setState({ loading: false, error: err?.message || "Failed to load", data: null });
      });
    return () => controller.abort();
  }, [paletteNumericId]);

  useEffect(() => {
    let cancelled = false;
    async function loadCtas() {
      setCtaItems([]);
      const groupId = Number(addCtaGroup || 0);
      let resolvedGroupId = groupId;
      if (!resolvedGroupId && ctaAudience) {
        try {
          const res = await fetch(`${CTA_GROUP_BY_AUDIENCE_URL}?audience=${encodeURIComponent(ctaAudience)}`, {
            headers: { Accept: "application/json" },
          });
          const data = await res.json();
          if (res.ok && data?.ok && data?.group?.id) {
            resolvedGroupId = Number(data.group.id) || 0;
          }
        } catch {
          resolvedGroupId = 0;
        }
      }
      if (!resolvedGroupId) return;
      try {
        const res = await fetch(`${CTA_GROUP_ITEMS_URL}?group_id=${resolvedGroupId}`, {
          headers: { Accept: "application/json" },
        });
        const data = await res.json();
        if (!res.ok || !data?.ok) return;
        if (!cancelled) {
          setCtaItems(Array.isArray(data.items) ? data.items : []);
        }
      } catch {
        // ignore CTA load errors
      }
    }
    loadCtas();
    return () => {
      cancelled = true;
    };
  }, [addCtaGroup, ctaAudience]);

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

  const ctas = useMemo(() => {
    const raw = ctaItems || [];
    return raw.map((cta, index) => {
      let parsedParams = {};
      if (typeof cta?.params === "string" && cta.params.trim() !== "") {
        try {
          const decoded = JSON.parse(cta.params);
          if (decoded && typeof decoded === "object") {
            parsedParams = decoded;
          }
        } catch {
          parsedParams = {};
        }
      } else if (cta?.params && typeof cta.params === "object") {
        parsedParams = cta.params;
      }
      const key = cta?.key || cta?.type_action_key || cta?.action_key || cta?.action || "";
      const label = cta?.label ?? "";
      const isBack = key.toLowerCase().includes("back") || label.trim().toLowerCase().startsWith("back");
      const variant = resolveVariant(parsedParams.variant || parsedParams.style, isBack);
      return {
        cta_id: cta?.cta_id ?? `${key || "cta"}-${index}`,
        label,
        key,
        enabled: resolveEnabled(
          cta?.is_active ?? true,
          parsedParams,
          psiParam,
          thumbParam,
          demoParam,
          ctaAudience
        ),
        variant,
        display_mode: parsedParams.display_mode,
        icon: parsedParams.icon,
        params: parsedParams,
      };
    });
  }, [ctaItems, psiParam, thumbParam, demoParam, ctaAudience]);

  const ctaHandlers = useMemo(
    () =>
      buildCtaHandlers({
        data: {},
        navigate,
        ctaAudience,
        psi: psiParam,
        thumb: thumbParam === "1" || thumbParam.toLowerCase() === "true",
        demo: demoParam === "1" || demoParam.toLowerCase() === "true",
      }),
    [navigate, ctaAudience, psiParam, thumbParam, demoParam]
  );

  const handleCtaClick = (cta) => {
    const key = getCtaKey(cta);
    if (!key) return;
    if (key === "open_share") {
      setShareSheetOpen(true);
      return;
    }
    ctaHandlers[key]?.(cta);
  };

  const meta = state.data?.meta || null;
  const swatches = state.data?.swatches || [];

  const pageCtas = ctas;

  const shareUrl = useMemo(() => {
    if (typeof window === "undefined") return "";
    try {
      const url = new URL(window.location.href);
      if (isAdminView) {
        url.searchParams.delete("admin");
      }
      return url.toString();
    } catch {
      return window.location.href || "";
    }
  }, [isAdminView]);

  const shareTitle = meta?.title || "ColorFix Palette";
  const shareMessage = `Check out ${shareTitle} from ColorFix: ${shareUrl}`;
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
        footer={
          <CTASection
            className="cta-section--transparent cta-section--on-dark cta-section--back-left cta-section--desktop-row-split"
            ctas={pageCtas}
            onCtaClick={handleCtaClick}
          />
        }
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

function isTruthyFlag(value) {
  if (value === true) return true;
  if (value === false || value === null || value === undefined) return false;
  const normalized = String(value).toLowerCase().trim();
  return normalized === "1" || normalized === "true" || normalized === "yes";
}

function resolveEnabled(baseEnabled, params, psiParam, thumbParam, demoParam, audParam) {
  if (!baseEnabled) return false;
  const requirePsi = Boolean(params?.require_psi || params?.requirePsi || params?.require_psi_id);
  if (requirePsi && !psiParam) return false;
  const requireThumb = Boolean(params?.require_thumb || params?.requireThumb);
  if (requireThumb && !isTruthyFlag(thumbParam)) return false;
  const requireDemo = Boolean(params?.require_demo || params?.requireDemo);
  if (requireDemo && !isTruthyFlag(demoParam)) return false;
  const requireAud = params?.require_aud || params?.requireAud;
  if (requireAud && String(audParam || "").toLowerCase() !== String(requireAud).toLowerCase()) return false;
  return true;
}

function resolveVariant(raw, isBack = false) {
  if (!raw) return isBack ? "link" : undefined;
  const normalized = String(raw).toLowerCase();
  if (normalized === "anchor" || normalized === "link") return "link";
  if (normalized === "button") return isBack ? "link" : undefined;
  if (normalized === "primary" || normalized === "secondary" || normalized === "ghost") return normalized;
  return isBack ? "link" : undefined;
}
