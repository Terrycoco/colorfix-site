export default function AdminBadge({
  children,
  variant = "neutral",
  className = "",
  ...props
}) {
  return (
    <span
      {...props}
      className={[
        "admin-badge",
        variant ? `admin-badge--${variant}` : "",
        className,
      ].filter(Boolean).join(" ")}
    >
      {children}
    </span>
  );
}
