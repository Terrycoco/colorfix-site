export default function AdminWorkbenchPane({
  title = "",
  action = null,
  children,
  className = "",
  style = undefined,
}) {
  return (
    <section
      className={["admin-workbench-pane", className].filter(Boolean).join(" ")}
      style={style}
    >
      <div className="admin-workbench-pane__header">
        <strong className="admin-workbench-pane__title">
          {title}
        </strong>
        {action}
      </div>

      <div className="admin-workbench-pane__body">
        {children}
      </div>
    </section>
  );
}
