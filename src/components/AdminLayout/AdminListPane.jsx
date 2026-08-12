export default function AdminListPane({
  title,
  actions = null,
  toolbar = null,
  children,
  className = "",
}) {
  return (
    <section className={`admin-list-pane ${className}`.trim()}>
      <header className="admin-list-pane__header">
        <h2 className="admin-list-pane__title">{title}</h2>
        {actions ? <div className="admin-list-pane__actions">{actions}</div> : null}
      </header>

      {toolbar ? <div className="admin-list-pane__toolbar">{toolbar}</div> : null}

      <div className="admin-list-pane__body">{children}</div>
    </section>
  );
}
