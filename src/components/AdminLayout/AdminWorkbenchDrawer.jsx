import { createPortal } from "react-dom";

function cssSize(value, fallback) {
  if (value === null || value === undefined || value === "") return fallback;
  return typeof value === "number" ? `${value}px` : String(value);
}

export default function AdminWorkbenchDrawer({
  open,
  width = 380,
  title = "",
  onClose,
  children,
  footer = null,
  portal = false,
  padded = false,
  className = "",
}) {
  if (!open) return null;

  const cssWidth = cssSize(width, "380px");

  function handleDrawerDoubleClick(event) {
    if (!onClose) return;

    /*
     * Use capture so the drawer always receives the double-click,
     * even if something inside the drawer stops propagation.
     */
    event.preventDefault();
    onClose();
  }

  const drawer = (
    <aside
      className={["admin-workbench-drawer", className].filter(Boolean).join(" ")}
      style={{
        "--admin-workbench-drawer-width": cssWidth,
      }}
      onDoubleClickCapture={handleDrawerDoubleClick}
    >
      <div className="admin-workbench-drawer__header">
        <strong className="admin-workbench-drawer__title">
          {title}
        </strong>

        {onClose ? (
          <button
            type="button"
            className="admin-workbench-drawer__close"
            onClick={onClose}
            aria-label="Close drawer"
            title="Close"
          >
            ×
          </button>
        ) : null}
      </div>

      <div
        className={[
          "admin-workbench-drawer__body",
          padded ? "is-padded" : "",
        ].filter(Boolean).join(" ")}
      >
        {children}
      </div>

      {footer ? (
        <div className="admin-workbench-drawer__footer">
          {footer}
        </div>
      ) : null}
    </aside>
  );

  if (!portal) return drawer;

  return createPortal(
    <div
      className="admin-workbench-drawer-host"
      style={{ "--admin-workbench-drawer-width": cssWidth }}
    >
      {drawer}
    </div>,
    document.body
  );
}
