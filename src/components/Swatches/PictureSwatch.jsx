// PictureSwatch.jsx
import { useNavigate } from "react-router-dom";
import { photoThumbUrl } from "@helpers/imageThumb";
import "./swatches.css";

export default function PictureSwatch({
  photoUrl,
  photoLibraryId,
  name,
  meta,
  to,
  onClick,
  widthPercent = 20,
}) {
  const navigate = useNavigate();
  const smallImageUrl = photoThumbUrl(photoLibraryId, 360, 70);
  const largeImageUrl = photoThumbUrl(photoLibraryId, 520, 72);
  const imageUrl = smallImageUrl || photoUrl;
  const imageSrcSet = smallImageUrl && largeImageUrl
    ? `${smallImageUrl} 360w, ${largeImageUrl} 520w`
    : undefined;

  const go = () => {
    if (onClick) {
      onClick();
      return;
    }
    if (to) {
      navigate(to);
    }
  };

  return (
    <div
      className="pals-swatch pals-photo"
      style={{ "--pals-width": `${widthPercent}%` }}
      onClick={go}
      role={to || onClick ? "button" : undefined}
      tabIndex={to || onClick ? 0 : undefined}
      onKeyDown={(e) => {
        if (!to && !onClick) return;
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          go();
        }
      }}
    >
      <div className="pals-fill pals-photo-fill">
        {imageUrl && (
          <img
            className="pals-photo-img"
            src={imageUrl}
            srcSet={imageSrcSet}
            sizes="(min-width: 560px) 260px, 50vw"
            alt={name || "Palette example"}
            loading="lazy"
            decoding="async"
          />
        )}
      </div>
    </div>
  );
}
