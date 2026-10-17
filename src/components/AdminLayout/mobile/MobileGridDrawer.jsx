import { useEffect } from "react";
import { createPortal } from "react-dom";
import "./MobileDataGrid.css";

export default function MobileGridDrawer({
  title = "Details",
  onClose,
  closing = false,
  padded = true,
  className = "",
  footer = null,
  children,
}) {
  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    return () => { document.body.style.overflow = previousOverflow; };
  }, []);

  return createPortal(
    <div className="mobile-grid-drawer-overlay" role="dialog" aria-modal="true" aria-label={typeof title === "string" ? title : "Details"}>
      <div className={`mobile-grid-drawer ${className}`}>
        <header className="mobile-grid-drawer__header">
          <button
            type="button"
            className="mobile-grid-drawer__back"
            aria-label="Close details and return to grid"
            title="Back to grid"
            disabled={closing}
            onClick={onClose}
          >
            <span aria-hidden="true">←</span>
          </button>
          <strong className="mobile-grid-drawer__title">{title}</strong>
        </header>
        <div className={`mobile-grid-drawer__body ${padded ? "is-padded" : ""}`}>
          {children}
        </div>
        {footer ? <div className="mobile-grid-drawer__footer">{footer}</div> : null}
      </div>
    </div>,
    document.body
  );
}
