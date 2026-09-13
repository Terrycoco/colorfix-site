export default function AdminEditorImage({
  src = "",
  alt = "",
  placeholder = "No preview",
  variant = "preview",
  className = "",
}) {
  const classes = [
    "admin-editor-image",
    variant ? `admin-editor-image--${variant}` : "",
    className,
  ].filter(Boolean).join(" ");

  if (src) {
    return (
      <img
        src={src}
        alt={alt}
        className={classes}
      />
    );
  }

  return (
    <div
      className={`${classes} admin-editor-image--empty`}
      role="img"
      aria-label={placeholder}
    >
      {placeholder}
    </div>
  );
}
