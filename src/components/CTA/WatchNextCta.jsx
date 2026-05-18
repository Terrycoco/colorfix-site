import "./cta.css";
import { photoThumbUrl } from "@helpers/imageThumb";

export default function WatchNextCta({ cta, onClick, disabled = false }) {
  if (!cta) return null;
  if (cta.enabled === false) return null;
  const params = cta.params || {};
  const rawTitle = params.title || cta.label || "Next Playlist";
  const strippedTitle = rawTitle.replace(/^watch next[:\s-]*/i, "").trim();
  const title = strippedTitle || rawTitle || "Suggested Playlists";
  const subtitle = params.subtitle || params.dek || "";
  const thumbnailUrl = photoThumbUrl(params.photo_library_id, 360, 70) || params.thumbnail_url || params.thumb_url || "";

  return (
    <button
      type="button"
      className={`cta-watch-next${disabled ? " is-disabled" : ""}`}
      onClick={() => {
        if (!disabled && onClick) onClick(cta);
      }}
    >
      <div className="cta-watch-next__label">Watch Next</div>
      <div className="cta-watch-next__body">
        {thumbnailUrl && (
          <div className="cta-watch-next__thumb">
            <img src={thumbnailUrl} alt="" loading="lazy" decoding="async" />
          </div>
        )}
        <div className="cta-watch-next__text">
          <div className="cta-watch-next__title">{title}</div>
          {subtitle && <div className="cta-watch-next__subtitle">{subtitle}</div>}
        </div>
      </div>
    </button>
  );
}
