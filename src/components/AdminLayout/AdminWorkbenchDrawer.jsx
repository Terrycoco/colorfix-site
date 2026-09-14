import { useState } from "react";
import { createPortal } from "react-dom";

function cssSize(value, fallback) {
  if (value === null || value === undefined || value === "") {
    return fallback;
  }

  return typeof value === "number"
    ? `${value}px`
    : String(value);
}

export default function AdminWorkbenchDrawer({
  open,
  width = 380,
  title = "",
  onClose,
  beforeClose = null,
  children,
  footer = null,
  portal = false,
  padded = false,
  className = "",
}) {
  const [closing, setClosing] = useState(false);

  if (!open) return null;

  const cssWidth = cssSize(width, "380px");

  async function requestClose() {
    if (!onClose || closing) return;

    setClosing(true);

    try {
      if (typeof beforeClose === "function") {
        const result = await beforeClose();

        if (result === false) {
          return;
        }
      }

      onClose();
    } finally {
      setClosing(false);
    }
  }

  function handleDrawerDoubleClick(event) {
    if (!onClose || closing) return;

    /*
     * Use capture so the drawer always receives the double-click,
     * even if something inside the drawer stops propagation.
     */
    event.preventDefault();
    requestClose();
  }

  const drawer = (
    <aside
      className={[
        "admin-workbench-drawer",
        closing ? "is-closing" : "",
        className,
      ].filter(Boolean).join(" ")}
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
            disabled={closing}
            onClick={requestClose}
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
      style={{
        "--admin-workbench-drawer-width": cssWidth,
      }}
    >
      {drawer}
    </div>,
    document.body
  );
}