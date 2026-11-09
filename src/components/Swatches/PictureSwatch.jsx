import { photoThumbUrl } from "@helpers/imageThumb";
import "./swatches.css";
import { hasSavedSwatchCrop, swatchCropStyle } from "./swatchCropStyle";

export default function PictureSwatch({
  photoUrl,
  photoLibraryId,
  name,
  meta,
  to,
  onClick,
  markerId,
  widthPercent = 20,
  cropX = null,
  cropY = null,
  cropWidth = null,
  cropHeight = null,
}) {
  const crop = { cropX, cropY, cropWidth, cropHeight };
  const hasCustomCrop = hasSavedSwatchCrop(crop);
  const smallImageUrl = photoThumbUrl(photoLibraryId, 360, 70);
  const largeImageUrl = photoThumbUrl(photoLibraryId, 520, 72);
  const imageUrl = hasCustomCrop ? (photoUrl || smallImageUrl) : (smallImageUrl || photoUrl);
  const imageSrcSet = !hasCustomCrop && smallImageUrl && largeImageUrl
    ? `${smallImageUrl} 360w, ${largeImageUrl} 520w`
    : undefined;
  const cropStyle = hasCustomCrop && photoUrl ? swatchCropStyle(crop) : undefined;
  const href = to || photoUrl || "";

  const go = () => {
    if (onClick) {
      onClick();
      return;
    }
  };

  const content = (
    <>
      <div className="pals-fill pals-photo-fill">
        {imageUrl && (
          <img
            className="pals-photo-img"
            style={cropStyle}
            src={imageUrl}
            srcSet={imageSrcSet}
            sizes="(min-width: 560px) 260px, 50vw"
            alt={name || "Palette example"}
            loading="lazy"
            decoding="async"
          />
        )}
      </div>
    </>
  );

  const commonProps = {
    className: "pals-swatch pals-photo",
    id: markerId || undefined,
    style: { "--pals-width": `${widthPercent}%` },
  };

  if (href) {
    return (
      <a
        {...commonProps}
        href={href}
        onClick={() => {
          if (markerId && typeof window !== "undefined" && window.history) {
            window.history.replaceState(null, "", `#${markerId}`);
          }
        }}
      >
        {content}
      </a>
    );
  }

  return (
    <div
      {...commonProps}
      onClick={go}
      role={onClick ? "button" : undefined}
      tabIndex={onClick ? 0 : undefined}
      onKeyDown={(e) => {
        if (!onClick) return;
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          go();
        }
      }}
    >
      {content}
    </div>
  );
}
