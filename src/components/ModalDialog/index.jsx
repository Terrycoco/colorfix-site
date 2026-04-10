import "./modal-dialog.css";

export default function ModalDialog({ open, title, subtitle = "", onClose, children, width = "560px" }) {
  if (!open) return null;

  return (
    <div
      className="modal-dialog__backdrop"
      role="button"
      tabIndex={0}
      onClick={onClose}
      onKeyDown={(e) => {
        if (e.key === "Escape") onClose?.();
      }}
    >
      <div
        className="modal-dialog"
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
    </div>
  );
}
