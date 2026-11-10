
import { useId } from "react";
import { Link } from "react-router-dom";
import { hasSavedSwatchCrop, swatchCropStyle } from "./swatchCropStyle";
import { photoThumbUrl } from "@helpers/imageThumb";
import "./swatches.css";
import "./art-swatch.css";

function MuseumFrame() {
  const rawId = useId().replace(/:/g, "");
  // A frame's profile is modeled by concentric bands rather than
  // screen-wide directional gradients. Every corner receives equal light.
  const corners = [
    "translate(0 0)",
    "translate(500 0) scale(-1 1)",
    "translate(0 400) scale(1 -1)",
    "translate(500 400) scale(-1 -1)",
  ];

  return (
    <svg
      className="pals-art-frame"
      viewBox="0 0 500 400"
      preserveAspectRatio="none"
      aria-hidden="true"
      focusable="false"
    >
      {/* Solid gilded ground. The cutout leaves the painting unobscured. */}
      <path
        d="M0 0H500V400H0Z M40 32H460V368H40Z"
        fill="#DDBB70"
        fillRule="evenodd"
      />

      {/* Even, concentric relief. Each stroke completes the entire rectangle,
          avoiding the left-bright/right-dark effect of the old gradients. */}
      {/* Outer rolled crown: glint, raised face, then the fine downturn. */}
      <rect x="2" y="2" width="496" height="396" rx="4"
        fill="none" stroke="#F3D98F" strokeWidth="4" />
      <rect x="6" y="6" width="488" height="388" rx="3"
        fill="none" stroke="#B78D45" strokeWidth="3" />
      <rect x="10" y="10" width="480" height="380" rx="2"
        fill="none" stroke="#E3B964" strokeWidth="9" />
      <rect x="14" y="14" width="472" height="372" rx="2"
        fill="none" stroke="#FFE7A1" strokeWidth="2.4" />
      <rect x="17.5" y="17.5" width="465" height="365"
        fill="none" stroke="#D0A354" strokeWidth="3" />

      {/* Concave channel. Dark gold is inside the trough only; its rims are
          identical on all four sides, including at the corner joins. */}
      <rect x="22" y="22" width="456" height="356"
        fill="none" stroke="#F2D184" strokeWidth="3" />
      <rect x="25" y="25" width="450" height="350"
        fill="none" stroke="#AD843F" strokeWidth="5" />
      <rect x="28" y="28" width="444" height="344"
        fill="none" stroke="#D6AE5C" strokeWidth="3" />

      {/* Raised inner bead and narrow bevel into the artwork opening. */}
      <rect x="32" y="32" width="436" height="336"
        fill="none" stroke="#B98D43" strokeWidth="3" />
      <rect x="35" y="35" width="430" height="330"
        fill="none" stroke="#EBC778" strokeWidth="7" />
      <rect x="38" y="38" width="424" height="324"
        fill="none" stroke="#FFF0B7" strokeWidth="1.8" />
      <rect x="40" y="32" width="420" height="336"
        fill="none" stroke="#A88140" strokeWidth="1.6" />

      {/* Repeating ornamental details */}
      {Array.from({ length: 13 }, (_, i) => {
        const x = 56 + i * 32;
        return (
          <g key={`horizontal-${i}`} fill="#F1D185">
            <path d={`M${x} 9 l6 5 -6 5 -6 -5Z`} />
            <path d={`M${x} 381 l6 5 -6 5 -6 -5Z`} />
          </g>
        );
      })}

      {Array.from({ length: 8 }, (_, i) => {
        const y = 65 + i * 38;
        return (
          <g key={`vertical-${i}`} fill="#F1D185">
            <path d={`M9 ${y} l5 -6 5 6 -5 6Z`} />
            <path d={`M481 ${y} l5 -6 5 6 -5 6Z`} />
          </g>
        );
      })}

      {/* Carved corner flourishes */}
      {corners.map((transform, i) => (
        <g
          key={i}
          transform={transform}
          fill="none"
          stroke="#F2D389"
          strokeWidth="2.4"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="M5 65 Q5 5 65 5" />
          <path d="M12 52 Q26 42 26 20 Q43 26 52 12" />
          <path d="M15 15 Q45 9 34 38 Q10 45 15 15Z" />
          <path d="M22 28 Q32 23 35 14" />
          <path d="M14 48 Q34 49 42 32" />
          <circle cx="23" cy="23" r="5" fill="#EBC574" />
        </g>
      ))}
    </svg>
  );
}

export default function ArtSwatch({
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
  // Do not apply saved coordinates to a server-generated thumbnail: it may
  // already have been resized or center-cropped. Use uncropped photo_url.
  const imageUrl = hasCustomCrop ? (photoUrl || smallImageUrl) : (smallImageUrl || photoUrl);
  const imageSrcSet = !hasCustomCrop && smallImageUrl && largeImageUrl
    ? `${smallImageUrl} 360w, ${largeImageUrl} 520w`
    : undefined;
  const cropStyle = hasCustomCrop && photoUrl ? swatchCropStyle(crop) : undefined;

  const content = (
    <div className="pals-art-tile">
      <div className="pals-art-opening">
        {imageUrl && (
          <img
            className="pals-art-image"
            src={imageUrl}
            srcSet={imageSrcSet}
            sizes="(min-width: 560px) 260px, 50vw"
            alt={name || "Artwork"}
            loading="lazy"
            decoding="async"
            style={cropStyle}
          />
        )}
      </div>
      <MuseumFrame />
    </div>
  );

  const commonProps = {
    className: "pals-swatch pals-art",
    id: markerId || undefined,
    style: { "--pals-width": `${widthPercent}%` },
    title: [name, meta].filter(Boolean).join(" — "),
  };

  function handleClick(event) {
    if (onClick) {
      event.preventDefault();
      onClick();
      return;
    }

    if (markerId && typeof window !== "undefined") {
      window.history.replaceState(window.history.state, "", `#${markerId}`);
    }
  }

  if (to) {
    return (
      <Link {...commonProps} to={to} onClick={handleClick}>
        {content}
      </Link>
    );
  }

  if (onClick) {
    return (
      <button
        {...commonProps}
        type="button"
        onClick={handleClick}
      >
        {content}
      </button>
    );
  }

  return <div {...commonProps}>{content}</div>;
}
