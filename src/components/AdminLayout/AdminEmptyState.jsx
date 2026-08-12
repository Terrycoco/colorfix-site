export default function AdminEmptyState({
  title = "Select an item",
  message = "",
  className = "",
}) {
  return (
    <div className={`admin-empty-state ${className}`.trim()}>
      <div className="admin-empty-state__title">{title}</div>
      {message ? <div className="admin-empty-state__message">{message}</div> : null}
    </div>
  );
}
