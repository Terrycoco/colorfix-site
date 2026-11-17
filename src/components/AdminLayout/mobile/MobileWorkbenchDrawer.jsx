import useOverlayLifecycle from "../../../hooks/useOverlayLifecycle";
import { useState } from "react";
import { createPortal } from "react-dom";
import "./MobileWorkbenchDrawer.css";

export default function MobileWorkbenchDrawer({
  open,
  title = "",
  onClose,
  beforeClose = null,
  children,
  footer = null,
  actions = null,
  padded = false,
  className = "",
}) {
  const [closing, setClosing] = useState(false);

  const overlayRef = useOverlayLifecycle({
    open, layer: "drawer", onEscape: () => void requestClose(),
  });

  if (!open) return null;

  async function requestClose() {
    if (!onClose || closing) return;
    setClosing(true);
    try {
      if (typeof beforeClose === "function") {
        const allowed = await beforeClose();
        if (allowed === false) return;
      }
      await onClose();
    } finally {
      setClosing(false);
    }
  }

  const drawer = (
    <div ref={overlayRef} className="mobile-workbench-drawer-overlay" role="dialog" aria-modal="true"
      aria-label={typeof title === "string" && title ? title : "Details"}>
      <aside className={["mobile-workbench-drawer", className, closing ? "is-closing" : ""].filter(Boolean).join(" ")}>
        <header className="mobile-workbench-drawer__header">
          {onClose ? (
            <button type="button" className="mobile-workbench-drawer__back"
              aria-label="Close and return to grid" title="Back"
              onClick={requestClose} disabled={closing}>
              <span aria-hidden="true">←</span>
            </button>
          ) : null}
          <strong className="mobile-workbench-drawer__title">{title}</strong>
        </header>
        {actions ? <div className="admin-workbench-drawer__close-row">{actions}</div> : null}
        <div className={["mobile-workbench-drawer__body", padded ? "is-padded" : ""].filter(Boolean).join(" ")}>
          {children}
        </div>
        {footer ? <footer className="mobile-workbench-drawer__footer">{footer}</footer> : null}
      </aside>
    </div>
  );

  return createPortal(drawer, document.body);
}
