export default function AdminDetailPane({
  children,
  className = "",
  ariaLabel = "Detail",
  title = "",
  actions = null,
}) {
  const hasHeader = Boolean(actions) || String(title || "").trim() !== "";

  return (
    <section
      className={`admin-detail-pane ${className}`.trim()}
      aria-label={ariaLabel}
    >
      {hasHeader ? (
        <div className="admin-detail-pane__chrome">
          {actions ? (
            <div
              className="admin-detail-pane__actions"
              aria-label={`${ariaLabel} actions`}
            >
              {actions}
            </div>
          ) : null}

          {String(title || "").trim() !== "" ? (
            <div className="admin-detail-pane__titlebar">
              <h1 className="admin-detail-pane__title">
                {title}
              </h1>
            </div>
          ) : null}
        </div>
      ) : null}

      <div className="admin-detail-pane__body">
        {children}
      </div>
    </section>
  );
}
