import { useEffect, useState } from "react";
import {
  extractAssetId,
  fetchAssetUrl,
  isAssetRef,
  parsePhotoRef,
} from "@helpers/assetImage";
import { withSourceParam } from "@helpers/sourceParam";
import "./playlist-thumbs.css";

export default function Thumbs({ collection }) {
  const items = Array.isArray(collection?.items) ? collection.items : [];
  const playlistTitle = formatTitle(
    collection?.playlist_title || collection?.title || "Colors Used"
  );
  const parentUrl = normalizeInternalRexPath(collection?.parent_url || "");

  const [thumbUrlByPvId, setThumbUrlByPvId] = useState({});

  useEffect(() => {
    let cancelled = false;

    items.forEach((item) => {
      const value = String(item?.photo_url || "").trim();

      if (!isAssetRef(value)) return;

      const assetId = extractAssetId(value);
      if (!assetId) return;

      fetchAssetUrl(assetId).then((url) => {
        if (cancelled || !url) return;

        setThumbUrlByPvId((prev) => ({
          ...prev,
          [String(item.pv_id || item.viewer_rex_id || assetId)]: url,
        }));
      });
    });

    return () => {
      cancelled = true;
    };
  }, [items]);

  if (!collection) {
    return (
      <div className="playlist-thumbs__status error">
        Colors Used could not be loaded.
      </div>
    );
  }

  const handleExit = () => {
    window.location.href = withSourceParam("/");
  };

  const handleBackToPlaylist = () => {
    if (!parentUrl) return;
    window.location.href = withSourceParam(parentUrl);
  };

  return (
    <div className="playlist-thumbs playlist-thumbs--end">
      <button
        type="button"
        className="playlist-thumbs__exit"
        onClick={handleExit}
        aria-label="Exit colors used"
      >
        ×
      </button>

      <div className="playlist-thumbs__panel">
        <header className="playlist-thumbs__header">
          <div className="playlist-thumbs__header-row">
            <div>
              <h1>{playlistTitle}</h1>
              <p>
                Palettes used in this playlist.{" "}
                <strong>Tap photo to view full colors</strong>
              </p>
            </div>
          </div>
        </header>

        {!items.length ? (
          <div className="playlist-thumbs__status">
            No palettes found in this playlist.
          </div>
        ) : null}

        <div className="playlist-thumbs__grid-wrap">
          <div className="playlist-thumbs__grid">
            {items.map((item, index) => {
              const cardKey =
                item.pv_id ||
                item.viewer_rex_id ||
                `viewer-${index}`;

              const photoValue = String(item.photo_url || "").trim();
              const parsedPhoto = parsePhotoRef(photoValue);

              const resolvedUrl = parsedPhoto.url
                ? parsedPhoto.url
                : isAssetRef(photoValue)
                  ? thumbUrlByPvId[String(cardKey)] || ""
                  : photoValue;

              const viewerUrl = normalizeInternalRexPath(
                item.viewer_url || ""
              );

              const href = viewerUrl
                ? withSourceParam(viewerUrl)
                : "";

              const title = formatTitle(
                item.title || `Palette Viewer ${index + 1}`
              );

              const cardContent = (
                <>
                  <div className="playlist-thumbs__image">
                    {resolvedUrl ? (
                      <img
                        src={resolvedUrl}
                        alt={item.photo_alt || title}
                        loading="lazy"
                      />
                    ) : (
                      <div className="playlist-thumbs__placeholder">
                        No Image
                      </div>
                    )}
                  </div>

                  <div className="playlist-thumbs__title-row">
                    <div className="playlist-thumbs__title">
                      {title}
                    </div>
                  </div>
                </>
              );

              if (!href) {
                return (
                  <div
                    key={cardKey}
                    className="playlist-thumbs__card playlist-thumbs__card--disabled"
                    aria-disabled="true"
                    title="No Viewer REX is linked to this palette."
                  >
                    {cardContent}
                  </div>
                );
              }

              return (
                <a
                  key={cardKey}
                  className="playlist-thumbs__card"
                  href={href}
                >
                  {cardContent}
                </a>
              );
            })}
          </div>
        </div>

        <div className="playlist-thumbs__footer">
          {parentUrl ? (
            <button
              type="button"
              className="playlist-thumbs__back"
              onClick={handleBackToPlaylist}
            >
              Back to playlist
            </button>
          ) : null}
        </div>
      </div>
    </div>
  );
}

function formatTitle(value) {
  return String(value || "")
    .replace(/\s*--\s*/g, " — ")
    .trim();
}

function normalizeInternalRexPath(value) {
  const path = String(value || "").trim();

  if (!path || !path.startsWith("/t/") || path.startsWith("//")) {
    return "";
  }

  return path;
}
