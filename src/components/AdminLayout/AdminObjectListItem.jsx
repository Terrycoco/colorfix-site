function normalizeMeta(meta) {
  if (!Array.isArray(meta)) return [];

  return meta.filter(
    (value) =>
      value !== null
      && value !== undefined
      && String(value).trim() !== ""
  );
}

export default function AdminObjectListItem({
  id,
  title,
  meta = [],
  selected = false,
  status = null,
  onSelect,
  onStatusClick,
  className = "",
}) {
  const metaLines = normalizeMeta(meta);
  const statusActive = Boolean(status?.active);
  const statusCount = Number(status?.count || 0);
  const statusLabel =
    status?.label
    || (
      statusCount > 0
        ? `${statusCount} active reservation${statusCount === 1 ? "" : "s"}`
        : "No active reservations"
    );

  function handleKeyDown(event) {
    if (event.key === "Enter" || event.key === " ") {
      event.preventDefault();
      onSelect?.();
    }
  }

  return (
    <div
      className={[
        "admin-object-list-item",
        selected ? "is-selected" : "",
        className,
      ].filter(Boolean).join(" ")}
      role="listitem"
    >
      <div
        className="admin-object-list-item__main"
        role="button"
        tabIndex={0}
        aria-current={selected ? "true" : undefined}
        data-admin-object-id={id}
        onClick={onSelect}
        onKeyDown={handleKeyDown}
      >
        <div className="admin-object-list-item__topline">
          <div className="admin-object-list-item__title-wrap">
            <span className="admin-object-list-item__title">
              {title}
            </span>

            {id !== null && id !== undefined && id !== "" ? (
              <span className="admin-object-list-item__id">
                #{id}
              </span>
            ) : null}
          </div>

          {status ? (
            <button
              type="button"
              className={`admin-object-list-item__status ${statusActive ? "is-active" : ""}`}
              title={statusLabel}
              aria-label={statusLabel}
              onClick={(event) => {
                event.stopPropagation();
                onStatusClick?.();
              }}
              onDoubleClick={(event) => {
                event.stopPropagation();
              }}
            >
              <span
                className="admin-object-list-item__dot"
                aria-hidden="true"
              />

              {statusCount > 0 ? (
                <span className="admin-object-list-item__status-count">
                  {statusCount}
                </span>
              ) : null}
            </button>
          ) : null}
        </div>

        {metaLines.length > 0 ? (
          <div className="admin-object-list-item__meta">
            {metaLines.map((line, index) => (
              <div
                className="admin-object-list-item__meta-line"
                key={`${id ?? title}-meta-${index}`}
              >
                {line}
              </div>
            ))}
          </div>
        ) : null}
      </div>
    </div>
  );
}
