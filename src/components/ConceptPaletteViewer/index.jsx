import { useEffect, useState } from "react";
import BrandFooterLogo from "@components/BrandFooterLogo";
import LogoAnimated from "@components/LogoAnimated";
import { copyShareText, openNativeShare, openTextShare } from "@helpers/shareUrls";
import "./conceptpaletteviewer.css";

export default function ConceptPaletteViewer({
  meta,
  adminMode = false,
  showBackButton = true,
  backLabel = "← Back",
  onBack,
  onExit,
  showLogo = true,
  footer,
  showShare = false,
  shareTitle = "Design Concept by ColorFix",
  shareText = "Here's a ColorFix design concept I wanted to share with you.",
  shareUrl,
  playlistUrl = "",
  contactUrl = "",
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

  const kicker = meta?.kicker_text || "";
  const title = formatTitle(meta?.title || "Design Concept");
  const intro = meta?.intro || "";
  const notes = meta?.notes || "";
  const photoUrl = meta?.photo_url || "";
  const insetPhotos = Array.isArray(meta?.inset_photos)
    ? meta.inset_photos
    : [];
  const photoAlt = meta?.photo_alt || "Design concept";

  const resolvedShareUrl =
    shareUrl || (typeof window !== "undefined" ? window.location.href : "");

  const resolvedPlaylistUrl = playlistUrl || meta?.playlist_url || "";
  const resolvedPlaylistLabel = meta?.cta_label || "Watch the Transformation";
  const resolvedContactUrl = contactUrl || meta?.contact_url || "";

  const seoTitle = kicker
    ? `${kicker} – ${title} | ColorFix`
    : `${title} | ColorFix`;

  const seoDescription =
    intro || notes || "Take a look at this ColorFix design concept.";

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

      window.location.href = "/";
    }
  };

  const handleExit = () => {
    if (onExit) {
      onExit();
      return;
    }

    if (typeof window !== "undefined") {
      window.location.href = "/";
    }
  };

  useEffect(() => {
    if (typeof document === "undefined") return undefined;

    const ogTags = [
      ["og:title", seoTitle],
      ["og:description", seoDescription],
      ["og:image", photoUrl],
    ];

    const previousOgTags = ogTags.map(([property]) => {
      const element = document.querySelector(`meta[property="${property}"]`);

      return {
        property,
        element,
        content: element?.getAttribute("content") ?? null,
        created: !element,
      };
    });

    const existingDescription = document.querySelector(
      'meta[name="description"]'
    );
    const previousDescription =
      existingDescription?.getAttribute("content") ?? null;
    const descriptionWasCreated = !existingDescription;

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

    let descriptionElement = existingDescription;

    if (seoDescription) {
      if (!descriptionElement) {
        descriptionElement = document.createElement("meta");
        descriptionElement.setAttribute("name", "description");
        document.head.appendChild(descriptionElement);
      }

      descriptionElement.setAttribute("content", seoDescription);
    }

    const previousTitle = document.title;
    document.title = seoTitle;

    return () => {
      previousOgTags.forEach(({ property, content, created }) => {
        const element = document.querySelector(`meta[property="${property}"]`);
        if (!element) return;

        if (created) {
          element.remove();
        } else if (content == null) {
          element.removeAttribute("content");
        } else {
          element.setAttribute("content", content);
        }
      });

      if (descriptionElement) {
        if (descriptionWasCreated) {
          descriptionElement.remove();
        } else if (previousDescription == null) {
          descriptionElement.removeAttribute("content");
        } else {
          descriptionElement.setAttribute("content", previousDescription);
        }
      }

      document.title = previousTitle;
    };
  }, [seoTitle, seoDescription, photoUrl]);

  const handleShare = async () => {
    if (!resolvedShareUrl) return;

    if (await openNativeShare({ title: shareTitle, text: shareText, url: resolvedShareUrl })) {
      return;
    }

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

      setShareStatus({
        loading: false,
        error: "",
        success: "Email sent.",
      });
    } catch (error) {
      setShareStatus({
        loading: false,
        error: error?.message || "Failed to send email",
        success: "",
      });
    }
  };

  const shareMessageBody = () =>
    `${shareForm.message || shareText}\n${resolvedShareUrl}`;

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
    <div className={`apv-shell ${adminMode ? "apv-shell--admin" : ""}`}>
      <button
        className="apv-exit"
        onClick={handleExit}
        aria-label="Exit concept viewer"
      >
        ×
      </button>

      <div className="apv-header">
        <div className="apv-header-slot">
          {showBackButton ? (
            <button className="apv-btn apv-btn--ghost" onClick={handleBack}>
              {backLabel}
            </button>
          ) : (
            showLogo && (
              <button
                className="apv-logo-button"
                onClick={() => {
                  window.location.href = "/";
                }}
              >
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
            <div
              className="apv-photo-wrap"
              onClick={() => {
                setExpandedPhoto({ url: photoUrl, alt: photoAlt || "" });
                setPhotoExpanded(true);
              }}
            >
              <img src={photoUrl} alt={photoAlt} className="apv-photo" />
            </div>

            {insetPhotos.length > 0 && (
              <div className="apv-photo-insets apv-photo-insets--below">
                {insetPhotos.map((photo, index) => {
                  const url =
                    typeof photo === "string" ? photo : photo?.url;

                  if (!url) return null;

                  const alt =
                    typeof photo === "string"
                      ? "Design concept inset"
                      : photo?.alt_text || "Design concept inset";

                  const caption =
                    typeof photo === "string" ? "" : photo?.caption || "";

                  return (
                    <figure
                      key={`${url}-${index}`}
                      className="apv-photo-inset-figure"
                      onClick={() => {
                        setExpandedPhoto({ url, alt });
                        setPhotoExpanded(true);
                      }}
                    >
                      <img
                        src={url}
                        alt={alt}
                        className="apv-photo-inset"
                        loading="lazy"
                      />

                      {caption && (
                        <figcaption className="apv-photo-inset-caption">
                          {caption}
                        </figcaption>
                      )}
                    </figure>
                  );
                })}
              </div>
            )}
          </div>
        )}

        <div className="apv-column apv-column--details">
          <div className="apv-info">
            {kicker && <div className="apv-kicker">{kicker}</div>}

            <h1>{title}</h1>

            {intro && (
              <section className="apv-concept-section">
                <div className="apv-kicker">The Goal</div>
                <p className="apv-notes">{intro}</p>
              </section>
            )}

            {notes && (
              <section className="apv-concept-section">
                <div className="apv-kicker">The Design Direction</div>
                <p className="apv-notes">{notes}</p>
              </section>
            )}

            {(resolvedPlaylistUrl || resolvedContactUrl) && (
              <div className="apv-concept-cta">
                <p className="apv-concept-cta-copy">
                  See how the full concept comes together.
                </p>

                <div className="apv-concept-actions">
                  {resolvedPlaylistUrl && (
                    <a
                      className="apv-concept-primary"
                      href={resolvedPlaylistUrl}
                    >
                      {resolvedPlaylistLabel}
                    </a>
                  )}

                  {resolvedContactUrl && (
                    <a
                      className="apv-concept-secondary"
                      href={resolvedContactUrl}
                    >
                      Talk With Terry
                    </a>
                  )}
                </div>
              </div>
            )}
          </div>
        </div>
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

      {(footer || showShare) && (
        <div className="apv-footer">
          {footer}

          <div className="apv-footer-actions">
            {showShare && (
              <button
                type="button"
                className="apv-btn apv-btn--share"
                onClick={handleShare}
              >
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

            <h3>Share Concept</h3>

            <label>
              To
              <input
                type="email"
                value={shareForm.toEmail}
                onChange={(event) =>
                  handleShareField("toEmail", event.target.value)
                }
                placeholder="name@email.com"
              />
            </label>

            <label>
              Message
              <textarea
                rows={4}
                value={shareForm.message}
                onChange={(event) =>
                  handleShareField("message", event.target.value)
                }
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
              <div className="apv-share-status apv-share-status--error">
                {shareStatus.error}
              </div>
            )}

            {shareStatus.success && (
              <div className="apv-share-status apv-share-status--success">
                {shareStatus.success}
              </div>
            )}

            <div className="apv-share-actions">
              <button
                type="button"
                className="apv-btn apv-btn--ghost"
                onClick={handleShareCopyLink}
              >
                Copy Link
              </button>

              <button
                type="button"
                className="apv-btn apv-btn--ghost"
                onClick={handleShareText}
              >
                Text
              </button>

              <button
                type="button"
                className="apv-btn apv-btn--copy"
                onClick={handleShareSend}
              >
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
