export default function AdminField({
  label,
  hint = null,
  compact = false,
  grow = false,
  className = "",
  children,
}) {
  return (
    <label
      className={[
        "admin-field",
        compact ? "admin-field--compact" : "",
        grow ? "admin-field--grow" : "",
        className,
      ].filter(Boolean).join(" ")}
    >
      {label ? (
        <span className="admin-field__label">
          {label}
        </span>
      ) : null}

      {children}

      {hint ? (
        <span className="admin-field__hint">
          {hint}
        </span>
      ) : null}
    </label>
  );
}
