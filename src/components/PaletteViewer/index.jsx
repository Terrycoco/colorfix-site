import { useEffect, useMemo, useState } from "react";
import LogoAnimated from "@components/LogoAnimated";
import "./appliedpaletteviewer.css";

export default function PaletteViewer({
  meta,
  swatches = [],
  adminMode = false,
  showBackButton = true,
  onBack,
  showLogo = true,
  footer,
  showShare = false,
  shareTitle = "Palette by ColorFix",
  shareText = "I wanted to share this color palette with you.\n\nThe link shows the colors together so you can get a feel for the overall look.\n\nWhat do you think?",
  shareUrl,
}) {
  const [photoExpanded, setPhotoExpanded] = useState(false);
  const [shareOpen, setShareOpen] = useState(false);
  const [shareForm, setShareForm] = useState({
    toEmail: "",
    message: shareText,
  });
  const [shareStatus, setShareStatus] = useState({ loading: false, error: "", success: "" });
  const colorGroups = useMemo(() => groupEntriesByColor(swatches), [swatches]);
  const title = formatTitle(meta?.title || "ColorFix Palette");
  const notes = meta?.notes || "";
  const photoUrl = meta?.photo_url || "";
  const insetPhotos = Array.isArray(meta?.inset_photos) ? meta.inset_photos : [];
  const resolvedShareUrl =
    shareUrl || (typeof window !== "undefined" ? window.location.href : "");

  const handleBack = () => {
    if (onBack) {
      onBack();
      return;
    }
    if (typeof window !== "undefined" && window.history.length > 1) {
      window.history.back();
      return;
    }
    if (typeof window !== "undefined") {
      window.location.href = "/";
    }
  };

  useEffect(() => {
    if (typeof document === "undefined") return;
    const ogTags = [
      ["og:title", shareTitle],
      ["og:description", shareText],
      ["og:image", photoUrl],
    ];
    const prev = ogTags.map(([property]) => {
      const el = document.querySelector(`meta[property="${property}"]`);
      return { property, el, content: el?.getAttribute("content") ?? null };
    });
    ogTags.forEach(([property, content]) => {
      if (!content) return;
      let el = document.querySelector(`meta[property="${property}"]`);
      if (!el) {
        el = document.createElement("meta");
        el.setAttribute("property", property);
        document.head.appendChild(el);
      }
      el.setAttribute("content", content);
    });
    const prevTitle = document.title;
    document.title = shareTitle;
    return () => {
      prev.forEach(({ property, el, content }) => {
        if (!el) return;
        if (content == null) {
          el.remove();
        } else {
          el.setAttribute("content", content);
        }
      });
      document.title = prevTitle;
    };
  }, [shareTitle, shareText, photoUrl]);

  const isMobileShare = () => {
    if (typeof window === "undefined") return false;
    if (window.matchMedia?.("(hover: none) and (pointer: coarse)").matches) return true;
    const ua = navigator.userAgent || "";
    return /Android|iPhone|iPad|iPod/i.test(ua);
  };

  const handleShare = async () => {
    if (!resolvedShareUrl) return;
    if (navigator.share && isMobileShare()) {
      try {
        await navigator.share({
          title: shareTitle,
          text: shareText,
          url: resolvedShareUrl,
        });
        return;
      } catch {
        // fall through to email
      }
    }
    setShareForm((prev) => ({
      toEmail: prev.toEmail,
      message: prev.message || shareText,
    }));
    setShareStatus({ loading: false, error: "", success: "" });
    setShareOpen(true);
  };

  const handleShareField = (key, value) => {
    setShareForm((prev) => ({ ...prev, [key]: value }));
  };

  const handleShareSend = async () => {
    if (shareStatus.loading) return;
    const toEmail = shareForm.toEmail.trim();
    if (!toEmail) {
      setShareStatus({ loading: false, error: "Recipient email required.", success: "" });
      return;
    }
    setShareStatus({ loading: true, error: "", success: "" });
    try {
      const payload = {
        source: meta?.source || "",
        id: meta?.id ?? null,
        hash: meta?.hash ?? null,
        to_email: toEmail,
        message: shareForm.message || "",
        subject: shareTitle,
        share_url: resolvedShareUrl,
      };
      const res = await fetch("/api/v2/palette-viewer-send.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Failed to send email");
      }
      setShareStatus({ loading: false, error: "", success: "Email sent." });
    } catch (err) {
      setShareStatus({ loading: false, error: err?.message || "Failed to send email", success: "" });
    }
  };

  return (
      <div className={`apv-shell ${adminMode ? "apv-shell--admin" : ""}`}>
      <button
        className="apv-exit"
        onClick={handleBack}
        aria-label="Exit palette viewer"
      >
        ×
      </button>
      <div className="apv-header">
        <div className="apv-header-slot">
          {showBackButton ? (
            <button className="apv-btn apv-btn--ghost" onClick={handleBack}>
              ← Back
            </button>
          ) : (
            showLogo && (
              <button className="apv-logo-button" onClick={() => (window.location.href = "/")}>
                <LogoAnimated />
              </button>
            )
          )}
        </div>
        <div className="apv-header-logo" />
        <div className="apv-header-slot apv-header-slot--right">
          <div className="apv-header-spacer" />
        </div>
      </div>

      <div className="apv-content">
        {photoUrl && (
          <div className="apv-column apv-column--photo">
            <div className="apv-photo-wrap" onClick={() => setPhotoExpanded(true)}>
              <img src={photoUrl} alt="Palette photo" className="apv-photo" />
            </div>
            {insetPhotos.length > 0 && (
              <div className="apv-photo-insets apv-photo-insets--below">
                {insetPhotos.map((src, idx) => (
                  <img
                    key={`${src}-${idx}`}
                    src={src}
                    alt="Palette inset"
                    className="apv-photo-inset"
                    loading="lazy"
                  />
                ))}
              </div>
            )}
          </div>
        )}

        <div className="apv-column apv-column--details">
          <div className="apv-info">
            <h1>{title}</h1>
            {notes && <p className="apv-notes">{notes}</p>}
          </div>

          {swatches.length > 0 && (
            <div className="apv-entries">
              {colorGroups.map((group) => (
                <div key={group.key} className="apv-entry">
                  <div className="apv-color">
                    <span
                      className="apv-swatch"
                      style={{ backgroundColor: group.hex6 ? `#${group.hex6}` : "#ccc" }}
                    />
                    <div className="apv-color-meta">
                      <div className="apv-name">
                        {group.name || `Color #${group.id}`}
                        {group.code ? `, ${group.code}` : ""}
                      </div>
                      {(group.brand || group.brand_name) && (
                        <div className="apv-brand">
                          {group.brand_name || group.brand}
                        </div>
                      )}
                      {group.masks.length > 0 && (
                        <div className="apv-masks">
                          {group.masks.join(", ")}
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
      {photoExpanded && photoUrl && (
        <div className="apv-photo-fullscreen" onClick={() => setPhotoExpanded(false)}>
          <img src={photoUrl} alt="Palette photo full view" />
          <div className="apv-photo-fullscreen-hint">Tap to close</div>
        </div>
      )}
      {(footer || showShare) && (
        <div className="apv-footer">
          {footer}
          {showShare && (
            <button type="button" className="apv-btn apv-btn--share" onClick={handleShare}>
              Share
            </button>
          )}
          <div className="apv-branding">
            <span>Brought to you by </span>
            <a href="/" className="apv-branding-link">ColorFix</a>
            <span>.</span>
          </div>
        </div>
      )}
      {showShare && shareOpen && (
        <div className="apv-share-modal" role="dialog" aria-modal="true">
          <div className="apv-share-panel">
            <button
              type="button"
              className="apv-share-close"
              onClick={() => setShareOpen(false)}
              aria-label="Close share dialog"
            >
              ×
            </button>
            <h3>Share Palette</h3>
            <label>
              To
              <input
                type="email"
                value={shareForm.toEmail}
                onChange={(e) => handleShareField("toEmail", e.target.value)}
                placeholder="name@email.com"
              />
            </label>
            <label>
              Message
              <textarea
                rows={4}
                value={shareForm.message}
                onChange={(e) => handleShareField("message", e.target.value)}
              />
            </label>
            <label>
              Link
              <input type="text" readOnly value={resolvedShareUrl} onFocus={(e) => e.target.select()} />
            </label>
            {shareStatus.error && <div className="apv-share-status apv-share-status--error">{shareStatus.error}</div>}
            {shareStatus.success && <div className="apv-share-status apv-share-status--success">{shareStatus.success}</div>}
            <div className="apv-share-actions">
              <button type="button" className="apv-btn apv-btn--ghost" onClick={() => setShareOpen(false)}>
                Close
              </button>
              <button type="button" className="apv-btn apv-btn--copy" onClick={handleShareSend}>
                {shareStatus.loading ? "Sending…" : "Send Email"}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function formatTitle(value) {
  return String(value).replace(/\s*--\s*/g, " — ");
}

function groupEntriesByColor(entries) {
  if (!Array.isArray(entries)) return [];
  const groups = new Map();
  const order = [];
  entries.forEach((entry) => {
    const colorId = entry.id ?? null;
    const hex6 = entry.hex6 || entry.hex || "";
    const key = colorId ? `id:${colorId}` : hex6 ? `hex:${hex6}` : `role:${entry.role}`;
    if (!groups.has(key)) {
      groups.set(key, {
        key,
        id: colorId,
        hex6,
        name: entry.name || "",
        code: entry.code || "",
        brand: entry.brand || "",
        brand_name: entry.brand_name || "",
        masks: [],
      });
      order.push(key);
    }
    const group = groups.get(key);
    if (entry.role && !group.masks.includes(entry.role)) {
      group.masks.push(entry.role);
    }
  });
  return order.map((key) => groups.get(key));
}
