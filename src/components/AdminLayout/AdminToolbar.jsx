export default function AdminToolbar({
  children,
  spread = false,
  compact = false,
  className = "",
}) {
  return (
    <div
      className={[
        "admin-toolbar",
        spread ? "admin-toolbar--spread" : "",
        compact ? "admin-toolbar--compact" : "",
        className,
      ].filter(Boolean).join(" ")}
    >
      {children}
    </div>
  );
}
