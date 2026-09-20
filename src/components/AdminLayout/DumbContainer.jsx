export default function DumbContainer({
  children,
  className = "",
  ariaLabel = "Content",
}) {
  return (
    <section
      className={["admin-dumb-container", className]
        .filter(Boolean)
        .join(" ")}
      aria-label={ariaLabel}
    >
      {children}
    </section>
  );
}