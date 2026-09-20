import "./styles/AdminDetailPane.css";

export default function AdminDetailPane({
  children,
  className = "",
  ariaLabel = "Detail",
  title = "",
  meta = null,
  actions = null,
  subActions = null,
}) {
  const hasTitle =
    typeof title === "string"
      ? title.trim() !== ""
      : Boolean(title);

  return (
    <section
      className={`admin-detail-pane ${className}`.trim()}
      aria-label={ariaLabel}
    >
      <div className="admin-detail-pane__chrome">
        <div
          className="admin-detail-pane__actions"
          aria-label={`${ariaLabel} actions`}
        >
          {actions}
        </div>

        {hasTitle ? (
          <div className="admin-detail-pane__titlebar">
            <div className="admin-detail-pane__title-row">
              <h1 className="admin-detail-pane__title">
                {title}
              </h1>

              {meta ? (
                <div className="admin-detail-pane__meta">
                  {meta}
                </div>
              ) : null}
            </div>
          </div>
        ) : null}

        {subActions ? (
          <div
            className="admin-detail-pane__subactions"
            aria-label={`${ariaLabel} secondary actions`}
          >
            {subActions}
          </div>
        ) : null}
      </div>

      <div className="admin-detail-pane__body">
        {children}
      </div>
    </section>
  );
}
