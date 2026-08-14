import { useEffect, useMemo, useState } from "react";
import BrandFooterLogo from "@components/BrandFooterLogo";
import { copyShareText, openNativeShare, openTextShare } from "@helpers/shareUrls";
import { withSourceParam } from "@helpers/sourceParam";
import ViewerCtaButton from "../ViewerCtaButton";
import "../appliedpaletteviewer.css";
import "./painterpaletteviewer.css";

/**
 * painterView is a presentation-only view model. The project query can populate it
 * later without forcing the component to know the database schema.
 *
 * {
 *   address,
 *   projectName,
 *   issuedLabel
 * }
 */
export default function PainterPaletteViewer({
  meta,
  painterView = {},
  plans = [],
  swatches = [],
  adminMode = false,
  showBackButton = true,
  backLabel = "← Back",
  onBack,
  onExit,
  footer,
  showShare = false,
  shareTitle = "Painter Specifications by ColorFix",
  shareText = "Here are the ColorFix painter specifications.",
  shareUrl,
  playlistUrl = "",
  playlistLabel = "Watch Playlist",
}) {
  const [photoExpanded, setPhotoExpanded] = useState(false);
  const [expandedPhoto, setExpandedPhoto] = useState(null);
  const [shareOpen, setShareOpen] = useState(false);
  const [shareForm, setShareForm] = useState({
    toEmail: "",
    message: shareText,
  });
  const [shareStatus, setShareStatus] = useState({
    loading: false,
    error: "",
    success: "",
  });

  const painterPlans = useMemo(() => normalizePainterPlans(plans, swatches), [plans, swatches]);
  const allEntries = useMemo(() => painterPlans.flatMap((plan) => plan.swatches || []), [painterPlans]);

  const fallbackTitle = formatTitle(meta?.title || "ColorFix Palette");
  const address = painterView.address || "";
  const projectTitle = formatTitle(painterView.projectName || meta?.display_title || fallbackTitle);
  const issuedLabel = painterView.issuedLabel || "";
  const overallNote = meta?.notes || painterView.overallPainterNote || "";
  const hasPlanPayload = Array.isArray(plans) && plans.length > 0;
  const notFinalWarning = String(meta?.not_final_warning || painterView.notFinalWarning || "").trim();

  const paletteType = String(meta?.palette_type || "").toLowerCase();
  const photoUrl = meta?.photo_url || "";
  const insetPhotos = Array.isArray(meta?.inset_photos) ? meta.inset_photos : [];
  const photoAlt = meta?.photo_alt || projectTitle || "Final palette rendering";
  const ogImageUrl = meta?.og_image_url || photoUrl;
  const viewerCtaLabel = meta?.viewer_cta_label || "";
  const viewerCtaUrl = meta?.viewer_cta_url || "";

  const resolvedShareUrl =
    shareUrl || (typeof window !== "undefined" ? window.location.href : "");

  const seoTitle = `${projectTitle} Painter Specification Sheet | ColorFix`;
  const seoDescription = address || shareText;

  const showExteriorNote =
    (paletteType === "exterior" || paletteType === "hoa") &&
    allEntries.some((entry) => entry.int_only);

  const exteriorNoteBrandText = useMemo(() => {
    const brands = allEntries
      .filter((entry) => entry.int_only)
      .map((entry) => entry.brand_name || entry.brand)
      .filter(Boolean);
    const uniqueBrands = Array.from(new Set(brands));
    if (uniqueBrands.length === 1) return uniqueBrands[0];
    return "paint brand";
  }, [allEntries]);

  const handleBack = () => {
    if (onBack) {
      onBack();
      return;
    }
    if (typeof window !== "undefined") {
      if (window.history.length > 1) {
        window.history.back();
        return;
      }
      window.location.href = withSourceParam("/");
    }
  };

  const handleExit = () => {
    if (onExit) {
      onExit();
      return;
    }
    if (typeof window !== "undefined") {
      window.location.href = withSourceParam("/");
    }
  };

  const openPhoto = (url, alt = "") => {
    if (!url) return;
    setExpandedPhoto({ url, alt });
    setPhotoExpanded(true);
  };

  useEffect(() => {
    if (typeof document === "undefined") return undefined;

    const ogTags = [
      ["og:title", seoTitle],
      ["og:description", seoDescription],
      ["og:image", ogImageUrl],
      ["og:url", resolvedShareUrl],
    ];

    const previousTags = ogTags.map(([property]) => {
      const element = document.querySelector(`meta[property="${property}"]`);
      return {
        property,
        element,
        content: element?.getAttribute("content") ?? null,
      };
    });

    const metaDescription = document.querySelector('meta[name="description"]');
    const previousDescription = metaDescription?.getAttribute("content") ?? null;
    const previousTitle = document.title;

    ogTags.forEach(([property, content]) => {
      if (!content) return;
      let element = document.querySelector(`meta[property="${property}"]`);
      if (!element) {
        element = document.createElement("meta");
        element.setAttribute("property", property);
        document.head.appendChild(element);
      }
      element.setAttribute("content", content);
    });

    if (seoDescription) {
      let element = metaDescription;
      if (!element) {
        element = document.createElement("meta");
        element.setAttribute("name", "description");
        document.head.appendChild(element);
      }
      element.setAttribute("content", seoDescription);
    }

    document.title = seoTitle;

    return () => {
      previousTags.forEach(({ element, content }) => {
        if (!element) return;
        if (content == null) {
          element.remove();
        } else {
          element.setAttribute("content", content);
        }
      });

      if (metaDescription) {
        if (previousDescription == null) {
          metaDescription.remove();
        } else {
          metaDescription.setAttribute("content", previousDescription);
        }
      }

      document.title = previousTitle;
    };
  }, [seoTitle, seoDescription, ogImageUrl, resolvedShareUrl]);

  const handleShare = async () => {
    if (!resolvedShareUrl) return;

    const shared = await openNativeShare({
      title: shareTitle,
      text: shareText,
      url: resolvedShareUrl,
    });

    if (shared) return;

    setShareForm((previous) => ({
      toEmail: previous.toEmail,
      message: previous.message || shareText,
    }));
    setShareStatus({ loading: false, error: "", success: "" });
    setShareOpen(true);
  };

  const handleShareField = (key, value) => {
    setShareForm((previous) => ({ ...previous, [key]: value }));
  };

  const handleShareSend = async () => {
    if (shareStatus.loading) return;

    const toEmail = shareForm.toEmail.trim();
    if (!toEmail) {
      setShareStatus({
        loading: false,
        error: "Recipient email required.",
        success: "",
      });
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

      const response = await fetch("/api/v2/palette-viewer-send.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });

      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data?.ok) {
        throw new Error(data?.error || "Failed to send email");
      }

      setShareStatus({ loading: false, error: "", success: "Email sent." });
    } catch (error) {
      setShareStatus({
        loading: false,
        error: error?.message || "Failed to send email",
        success: "",
      });
    }
  };

  const shareMessageBody = () => `${shareForm.message || shareText}\n${resolvedShareUrl}`;

  const handleShareCopyLink = async () => {
    const copied = await copyShareText(resolvedShareUrl);
    setShareStatus({
      loading: false,
      error: copied ? "" : "Failed to copy link.",
      success: copied ? "Link copied." : "",
    });
  };

  const handleShareText = async () => {
    const body = shareMessageBody();
    await copyShareText(body);
    setShareStatus({
      loading: false,
      error: "",
      success: "Message copied. Messages will open now.",
    });
    window.setTimeout(() => {
      openTextShare({ text: body }).catch(() => {});
    }, 0);
  };

  return (
    <div className={`apv-shell apv-shell--painter ${adminMode ? "apv-shell--admin" : ""}`}>
      <button className="apv-exit" onClick={handleExit} aria-label="Exit palette viewer">
        ×
      </button>

      {showBackButton && (
        <div className="apv-header">
          <div className="apv-header-slot">
            <button className="apv-btn apv-btn--ghost" onClick={handleBack}>
              {backLabel}
            </button>
          </div>
          <div className="apv-header-logo" />
          <div className="apv-header-slot apv-header-slot--right">
            <div className="apv-header-spacer" />
          </div>
        </div>
      )}

      <div className="apv-content cpv-content ppv-content">
        <div className="apv-column apv-column--details ppv-details">
          <div className="apv-info cpv-summary">
            {notFinalWarning && (
              <div className="ppv-not-final-warning">
                {notFinalWarning}
              </div>
            )}

            <div className="apv-kicker">Painter Specification Sheet</div>

            <h1>{projectTitle}</h1>

            {overallNote && (
              <div className="ppv-note ppv-overall-note">
                <span className="cpv-spec-label">Note:</span>
                <span>{overallNote}</span>
              </div>
            )}
          </div>

          {painterPlans.length > 0 ? (
            <div className="ppv-plan-list">
              {painterPlans.map((plan, planIndex) => (
                <section className="ppv-plan-section" key={plan.key || plan.id || planIndex}>
                  <div className="ppv-plan-header">
                    <h2>{plan.title || `Area ${planIndex + 1}`}</h2>
                  </div>

                  <div className="ppv-plan-body">
                    <div className="ppv-plan-specs">
                      {plan.painterNote && (
                        <div className="ppv-note ppv-area-note">
                          <span className="cpv-spec-label">Note:</span>
                          <span>{plan.painterNote}</span>
                        </div>
                      )}

                      {plan.groups.length > 0 ? (
                        <div className="apv-entries">
                          {plan.groups.map((group) => (
                            <PainterColorEntry
                              key={group.key}
                              group={group}
                              showExteriorNote={showExteriorNote}
                            />
                          ))}
                        </div>
                      ) : (
                        <div className="cpv-empty">No color specifications have been added for this area yet.</div>
                      )}
                    </div>

                    {plan.photos.length > 0 && (
                      <div className="ppv-plan-photos" aria-label={`${plan.title || "Area"} photos`}>
                        {plan.photos.map((photo, photoIndex) => (
                          <button
                            type="button"
                            className="ppv-plan-photo"
                            key={`${photo.url}-${photoIndex}`}
                            onClick={() => openPhoto(photo.url, photo.alt_text || plan.title)}
                          >
                            <img
                              src={photo.url}
                              alt={photo.alt_text || plan.title || "Project photo"}
                              loading="lazy"
                            />
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                </section>
              ))}
            </div>
          ) : (
            <div className="cpv-empty">No color specifications have been added yet.</div>
          )}

          {(address || issuedLabel) && (
            <div className="cpv-project-meta ppv-project-meta">
              {address && (
                <div className="cpv-project-meta-row">
                  <span className="cpv-meta-label">Property:</span>
                  <span>{address}</span>
                </div>
              )}
              {issuedLabel && (
                <div className="cpv-project-meta-row">
                  <span className="cpv-meta-label">Issued:</span>
                  <span>{issuedLabel}</span>
                </div>
              )}
            </div>
          )}

          {showExteriorNote && (
            <div className="apv-footnote">
              * Depending on sun exposure, this color may not be suitable for
              exterior surfaces. Consult with your {exteriorNoteBrandText} representative.
            </div>
          )}

          {playlistUrl && (
            <div className="cpv-owner-actions ppv-actions">
              <a className="cpv-action cpv-action--secondary" href={withSourceParam(playlistUrl)}>
                {playlistLabel}
              </a>
            </div>
          )}
        </div>

        {photoUrl && !hasPlanPayload && (
          <div className="apv-column apv-column--photo ppv-photo-column">
            <div
              className="apv-photo-wrap"
              onClick={() => openPhoto(photoUrl, photoAlt)}
            >
              <img src={photoUrl} alt={photoAlt} className="apv-photo" />
            </div>

            {insetPhotos.length > 0 && (
              <div className="apv-photo-insets apv-photo-insets--below">
                {insetPhotos.map((photo, index) => {
                  const url = typeof photo === "string" ? photo : photo?.url;
                  if (!url) return null;

                  const alt =
                    typeof photo === "string"
                      ? "Additional project view"
                      : photo?.alt_text || "Additional project view";
                  const showBeforeLabel = isBeforePhoto(photo);

                  return (
                    <figure
                      key={`${url}-${index}`}
                      className="apv-photo-inset-figure"
                      onClick={() => openPhoto(url, alt)}
                    >
                      <img
                        src={url}
                        alt={alt}
                        className="apv-photo-inset"
                        loading="lazy"
                      />
                      {showBeforeLabel && (
                        <figcaption className="apv-photo-inset-caption">
                          BEFORE
                        </figcaption>
                      )}
                    </figure>
                  );
                })}
              </div>
            )}
          </div>
        )}
      </div>

      {photoExpanded && expandedPhoto?.url && (
        <div
          className="apv-photo-fullscreen"
          onClick={() => {
            setPhotoExpanded(false);
            setExpandedPhoto(null);
          }}
        >
          <img src={expandedPhoto.url} alt={expandedPhoto.alt || ""} />
          <div className="apv-photo-fullscreen-hint">Tap to close</div>
        </div>
      )}

      {(footer || showShare || viewerCtaUrl) && (
        <div className="apv-footer">
          {footer}
          <div className="apv-footer-actions">
            <ViewerCtaButton label={viewerCtaLabel} url={viewerCtaUrl} />
            {showShare && (
              <button type="button" className="apv-btn apv-btn--share" onClick={handleShare}>
                Share
              </button>
            )}
          </div>
          <div className="apv-branding">
            <BrandFooterLogo />
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
            <h3>Share Painter Specifications</h3>

            <label>
              To
              <input
                type="email"
                value={shareForm.toEmail}
                onChange={(event) => handleShareField("toEmail", event.target.value)}
                placeholder="name@email.com"
              />
            </label>

            <label>
              Message
              <textarea
                rows={4}
                value={shareForm.message}
                onChange={(event) => handleShareField("message", event.target.value)}
              />
            </label>

            <label>
              Link
              <input
                type="text"
                readOnly
                value={resolvedShareUrl}
                onFocus={(event) => event.target.select()}
              />
            </label>

            {shareStatus.error && (
              <div className="apv-share-status apv-share-status--error">{shareStatus.error}</div>
            )}
            {shareStatus.success && (
              <div className="apv-share-status apv-share-status--success">{shareStatus.success}</div>
            )}

            <div className="apv-share-actions">
              <button type="button" className="apv-btn apv-btn--ghost" onClick={handleShareCopyLink}>
                Copy Link
              </button>
              <button type="button" className="apv-btn apv-btn--ghost" onClick={handleShareText}>
                Text
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

function PainterColorEntry({ group, showExteriorNote }) {
  return (
    <article className="apv-entry cpv-entry">
      <div className="apv-color">
        <span
          className="apv-swatch"
          style={{
            backgroundColor: group.hex6 ? `#${group.hex6}` : "#ccc",
          }}
          aria-hidden="true"
        />

        <div className="apv-color-meta">
          <div className="apv-name">
            {group.name || `Color #${group.id}`}
            {group.code ? `, ${group.code}` : ""}
            {showExteriorNote && group.int_only && (
              <span
                className="apv-int-only"
                aria-label="Not recommended for exteriors"
              >
                *
              </span>
            )}
          </div>

          {(group.brand_name || group.brand) && (
            <div className="apv-brand">
              {group.brand_name || group.brand}
            </div>
          )}
        </div>
      </div>

      {group.assignments.map((assignment, index) => (
        <div
          className="cpv-assignment ppv-assignment"
          key={`${group.key}-${index}`}
        >
          {assignment.role && (
            <div className="cpv-spec-line">
              <span className="cpv-spec-label">Placement:</span>
              <span>{assignment.role}</span>
            </div>
          )}

          {assignment.sheen && (
            <div className="cpv-spec-line">
              <span className="cpv-spec-label">Sheen:</span>
              <span>{assignment.sheen}</span>
            </div>
          )}

          {assignment.note && (
            <div className="ppv-note">
              <span className="cpv-spec-label">Note:</span>
              <span>{assignment.note}</span>
            </div>
          )}
        </div>
      ))}
    </article>
  );
}

function formatTitle(value) {
  return String(value || "").replace(/\s*--\s*/g, " — ");
}

function isBeforePhoto(photo) {
  if (!photo || typeof photo === "string") return false;

  if (photo.is_before === true || photo.isBefore === true) return true;

  const label = String(
    photo.caption || photo.role || photo.type || photo.photo_type || photo.kind || "",
  ).trim().toLowerCase();

  return label === "before" || label.startsWith("before ");
}

function groupEntriesByColor(entries) {
  if (!Array.isArray(entries)) return [];

  return entries.map((entry, index) => {
    const colorId = entry.id ?? null;
    const hex6 = String(entry.hex6 || entry.hex || "").replace(/^#/, "");
    const assignment = {
      role: entry.role_name || entry.role || "",
      sheen: entry.sheen || "",
      note: entry.note || "",
    };

    return {
      key: `${colorId ? `id:${colorId}` : hex6 ? `hex:${hex6}` : "entry"}:${index}`,
      id: colorId,
      hex6,
      name: entry.name || "",
      code: entry.code || "",
      brand: entry.brand || "",
      brand_name: entry.brand_name || "",
      assignments: assignment.role || assignment.sheen || assignment.note ? [assignment] : [],
      int_only: Boolean(entry.int_only),
    };
  });
}

function normalizePainterPlans(plans, fallbackSwatches) {
  const sourcePlans = Array.isArray(plans) ? plans : [];
  if (sourcePlans.length > 0) {
    return sourcePlans.map((plan, index) => {
      const title = formatTitle(
        plan.title
          || plan.area_name
          || plan.nickname
          || plan.schemeTitle
          || plan.scheme_title
          || `Area ${index + 1}`
      );
      const swatchRows = Array.isArray(plan.swatches) ? plan.swatches : [];
      const photos = (Array.isArray(plan.photos) ? plan.photos : [])
        .filter((photo) => !isBeforePhoto(photo))
        .map((photo) => ({
          ...photo,
          url: typeof photo === "string" ? photo : photo.url,
          alt_text: typeof photo === "string" ? title : photo.alt_text || photo.photo_title || title,
          type: typeof photo === "string" ? "" : photo.type || photo.photo_type || "",
        }))
        .filter((photo) => photo.url);
      return {
        key: plan.key || plan.id || `${title}-${index}`,
        id: plan.id || null,
        title,
        schemeTitle: formatTitle(plan.schemeTitle || plan.scheme_title || ""),
        painterNote: plan.painterNote || plan.painter_note || plan.overall_painter_note || "",
        photos,
        groups: groupEntriesByColor(swatchRows),
        swatches: swatchRows,
      };
    });
  }

  const swatchRows = Array.isArray(fallbackSwatches) ? fallbackSwatches : [];
  return swatchRows.length > 0
    ? [{
        key: "single-room",
        id: null,
        title: "",
        schemeTitle: "",
        painterNote: "",
        photos: [],
        groups: groupEntriesByColor(swatchRows),
        swatches: swatchRows,
      }]
    : [];
}
