export default function AdminPanel({
  title = "",
  meta = null,
  actions = null,
  children = null,
  compact = false,
  className = "",
}) {
  return (
    <section
      className={[
        "admin-panel",
        compact ? "admin-panel--compact" : "",
        className,
      ].filter(Boolean).join(" ")}
    >
      {(title || meta || actions) ? (
        <div className="admin-panel__header">
          <div className="admin-panel__heading">
            {title ? (
              <div className="admin-panel__title">
                {title}
              </div>
            ) : null}

            {meta ? (
              <div className="admin-panel__meta">
                {meta}
              </div>
            ) : null}
          </div>

          {actions ? (
            <div className="admin-panel__actions">
              {actions}
            </div>
          ) : null}
        </div>
      ) : null}

      {children ? (
        <div className="admin-panel__body">
          {children}
        </div>
      ) : null}
    </section>
  );
}
