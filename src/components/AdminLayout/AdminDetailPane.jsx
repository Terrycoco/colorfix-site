export default function AdminDetailPane({
  children,
  className = "",
  ariaLabel = "Detail",
}) {
  return (
    <section
      className={`admin-detail-pane ${className}`.trim()}
      aria-label={ariaLabel}
    >
      {children}
    </section>
  );
}
