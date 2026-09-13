export default function AdminMediaPreview({
  imageUrl = "",
  label = "",
  detail = "",
  detailTitle = "",
  alt = "",
  placeholder = "No image",
  className = "",
}) {
  return (
    <div className={["admin-media-preview", className].filter(Boolean).join(" ")}>
      {imageUrl ? (
        <img
          className="admin-media-preview__image"
          src={imageUrl}
          alt={alt}
        />
      ) : (
        <div className="admin-media-preview__placeholder">
          {placeholder}
        </div>
      )}

      {(label || detail) ? (
        <div className="admin-media-preview__text">
          {label ? (
            <div className="admin-media-preview__label">
              {label}
            </div>
          ) : null}

          {detail ? (
            <div
              className="admin-media-preview__detail"
              title={detailTitle || undefined}
            >
              {detail}
            </div>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}
