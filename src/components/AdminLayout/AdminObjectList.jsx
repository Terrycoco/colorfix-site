export default function AdminObjectList({
  children,
  className = "",
  ariaLabel = "Items",
}) {
  return (
    <div
      className={`admin-object-list ${className}`.trim()}
      role="list"
      aria-label={ariaLabel}
    >
      {children}
    </div>
  );
}
