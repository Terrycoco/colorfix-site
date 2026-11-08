
import { useId, useState } from "react";
import { photoThumbUrl } from "@helpers/imageThumb";
import "./swatches.css";
import "./art-swatch.css";

const FRAME_RATIO = 5 / 4;

function getCropStyle(width, height, cropX, cropY, cropSize) {
  if (!width || !height) return undefined;

  const shortSide = Math.min(width, height);

  // cropSize is the crop WIDTH relative to the
  // shorter dimension of the original photograph.
  const requestedSize = Number(cropSize);
  const size =
    Number.isFinite(requestedSize) && requestedSize > 0
      ? Math.min(requestedSize, 1)
      : 1;

  const cropWidth = Math.min(
    size * shortSide,
    width,
    height * FRAME_RATIO
  );
  const cropHeight = cropWidth / FRAME_RATIO;

  const maxX = Math.max(0, width - cropWidth);
  const maxY = Math.max(0, height - cropHeight);

  const hasSavedCrop =
    Number.isFinite(Number(cropX)) &&
    Number.isFinite(Number(cropY)) &&
    !(Number(cropX) === 0 &&
      Number(cropY) === 0 &&
      size === 1);

  const x = hasSavedCrop
    ? Math.max(0, Math.min(Number(cropX) * width, maxX))
    : maxX / 2;

  const y = hasSavedCrop
    ? Math.max(0, Math.min(Number(cropY) * height, maxY))
    : maxY / 2;

  return {
    width: `${(width / cropWidth) * 100}%`,
    height: `${(height / cropHeight) * 100}%`,
    left: `${(-x / cropWidth) * 100}%`,
    top: `${(-y / cropHeight) * 100}%`,
  };
}

function MuseumFrame() {
  const rawId = useId().replace(/:/g, "");
  const goldId = `art-gold-${rawId}`;
  const innerId = `art-inner-${rawId}`;

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
      <defs>
        <linearGradient id={goldId} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0%" stopColor="#F8DE9C" />
          <stop offset="18%" stopColor="#C7994C" />
          <stop offset="40%" stopColor="#80501D" />
          <stop offset="63%" stopColor="#EDC982" />
          <stop offset="82%" stopColor="#AD7834" />
          <stop offset="100%" stopColor="#F4D58B" />
        </linearGradient>
        <linearGradient id={innerId} x1="0" y1="0" x2="0.7" y2="1">
          <stop offset="0%" stopColor="#5B381A" />
          <stop offset="45%" stopColor="#B98842" />
          <stop offset="100%" stopColor="#F1CF84" />
        </linearGradient>
      </defs>

      {/* Solid molding, with transparent artwork opening */}
      <path
        d="M0 0H500V400H0Z M40 32H460V368H40Z"
        fill={`url(#${goldId})`}
        fillRule="evenodd"
      />

      {/* Layered gilded profiles */}
      <rect
        x="5" y="5" width="490" height="390"
        fill="none" stroke="#6E461F" strokeWidth="8"
      />
      <rect
        x="12" y="12" width="476" height="376"
        fill="none" stroke="#F9DE9C" strokeWidth="3"
      />
      <rect
        x="19" y="19" width="462" height="362"
        fill="none" stroke="#785022" strokeWidth="7"
      />
      <rect
        x="27" y="27" width="446" height="346"
        fill="none" stroke="#E8B969" strokeWidth="3"
      />
      <rect
        x="34" y="34" width="432" height="332"
        fill="none" stroke={`url(#${innerId})`}
        strokeWidth="7"
      />
      <rect
        x="40" y="32" width="420" height="336"
        fill="none" stroke="#38230F" strokeWidth="2"
      />

      {/* Repeating ornamental details */}
      {Array.from({ length: 13 }, (_, i) => {
        const x = 56 + i * 32;
        return (
          <g key={`horizontal-${i}`} fill="#F5D48A">
            <path d={`M${x} 9 l6 5 -6 5 -6 -5Z`} />
            <path d={`M${x} 381 l6 5 -6 5 -6 -5Z`} />
          </g>
        );
      })}

      {Array.from({ length: 8 }, (_, i) => {
        const y = 65 + i * 38;
        return (
          <g key={`vertical-${i}`} fill="#F5D48A">
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
          stroke="#FFE2A0"
          strokeWidth="2.4"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="M5 65 Q5 5 65 5" />
          <path d="M12 52 Q26 42 26 20 Q43 26 52 12" />
          <path d="M15 15 Q45 9 34 38 Q10 45 15 15Z" />
          <path d="M22 28 Q32 23 35 14" />
          <path d="M14 48 Q34 49 42 32" />
          <circle cx="23" cy="23" r="5" fill="#F6D68C" />
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
  cropX = 0,
  cropY = 0,
  cropSize = 1,
}) {
  const [dimensions, setDimensions] = useState(null);

  const smallImageUrl = photoThumbUrl(photoLibraryId, 360, 70);
  const largeImageUrl = photoThumbUrl(photoLibraryId, 520, 72);
  const imageUrl = smallImageUrl || photoUrl;

  const imageSrcSet =
    smallImageUrl && largeImageUrl
      ? `${smallImageUrl} 360w, ${largeImageUrl} 520w`
      : undefined;

  const cropStyle = dimensions
    ? getCropStyle(
        dimensions.width,
        dimensions.height,
        cropX,
        cropY,
        cropSize
      )
    : undefined;

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
            onLoad={(event) => {
              const img = event.currentTarget;
              setDimensions({
                width: img.naturalWidth,
                height: img.naturalHeight,
              });
            }}
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
      window.history.replaceState(null, "", `#${markerId}`);
    }
  }

  if (to) {
    return (
      <a {...commonProps} href={to} onClick={handleClick}>
        {content}
      </a>
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
