import { createPortal } from "react-dom";
import useOverlayLifecycle from "../../hooks/useOverlayLifecycle";
import "./modal-dialog.css";

export default function ModalDialog({ open, title, subtitle = "", onClose, children, width = "560px" }) {
  const overlayRef = useOverlayLifecycle({ open: open, layer: "dialog", onEscape: onClose });

  if (!open) return null;

  return createPortal(
    <div
      ref={overlayRef}
      className="modal-dialog__backdrop"
      role="presentation"
      onClick={onClose}
    >
      <div
        className="modal-dialog"
        role="dialog"
        aria-modal="true"
        aria-label={title}
        style={{ width: `min(${width}, 100%)` }}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="modal-dialog__head">
          <div>
            <div className="modal-dialog__title">{title}</div>
            {subtitle ? <div className="modal-dialog__subtitle">{subtitle}</div> : null}
          </div>
          <button
            type="button"
            className="modal-dialog__close"
            onClick={onClose}
          >
            Close
          </button>
        </div>
        <div className="modal-dialog__body">{children}</div>
      </div>
    </div>, document.body
  );
}
