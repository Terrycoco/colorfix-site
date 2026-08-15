export default function AdminDataGrid({
  children,
  className = "",
  ariaLabel,
}) {
  return (
    <div className={`admin-data-grid-wrap ${className}`.trim()}>
      <table
        className="admin-data-grid"
        aria-label={ariaLabel}
      >
        {children}
      </table>
    </div>
  );
}