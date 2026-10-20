import useOverlayLifecycle from "../../hooks/useOverlayLifecycle";
import "./styles/AdminDialog.css";
import { useId, useRef } from "react";
import { createPortal } from "react-dom";

function cssSize(value, fallback) {
  if (value === null || value === undefined || value === "") return fallback;
  return typeof value === "number" ? `${value}px` : String(value);
}

export default function AdminDialog({
  open,
  mode = "confirm",
  title = "",
  message = "",
  children = null,
  actions = null,
  confirmLabel = "OK",
  cancelLabel = "Cancel",
  onConfirm,
  onCancel,
  onClose,
  dismissOnBackdrop = true,
  width = 520,
  className = "",
}) {
  const dialogRef = useRef(null);
  const confirmButtonRef = useRef(null);
  const cancel = onCancel || onClose;

  const id = useId();
  const overlayRef = useOverlayLifecycle({
    open,
    layer: mode === "alert" || mode === "danger" ? "critical" : "dialog",
    onEscape: () => mode === "alert" ? onConfirm?.() : cancel?.(),
    initialFocus: () => dialogRef.current?.querySelector("input:not([disabled]), select:not([disabled]), textarea:not([disabled])")
      || confirmButtonRef.current || dialogRef.current,
  });

  if (!open) return null;

  const hasBuiltInActions = actions === null;
  const actionItems = Array.isArray(actions)
    ? actions.filter(Boolean)
    : null;
  const titleId = title ? `${id}-title` : undefined;
  const messageId = message ? `${id}-message` : undefined;

  return createPortal(
    <div
      ref={overlayRef}
      className="admin-dialog-backdrop"
      style={{ zIndex: `var(--z-${mode === "alert" || mode === "danger" ? "critical" : "dialog"})` }}
      role="presentation"
      onMouseDown={(event) => {
        if (event.target !== event.currentTarget) return;
        if (!dismissOnBackdrop || mode === "alert") return;
        cancel?.();
      }}
    >
      <section
        ref={dialogRef}
        className={[
          "admin-dialog",
          mode === "danger" ? "admin-dialog--danger" : "",
          className,
        ].filter(Boolean).join(" ")}
        style={{ "--admin-dialog-width": cssSize(width, "520px") }}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={messageId}
        tabIndex={-1}
      >
        <div className="admin-dialog__body">
          {title ? (
            <h2 id={titleId} className="admin-dialog__title">
              {title}
            </h2>
          ) : null}

          {message ? (
            <div id={messageId} className="admin-dialog__message">
              {message}
            </div>
          ) : null}

          {children ? (
            <div className="admin-dialog__content">
              {children}
            </div>
          ) : null}
        </div>

        {actions !== false ? (
          <div className="admin-dialog__actions">
            {hasBuiltInActions ? (
              <>
                {mode !== "alert" ? (
                  <button
                    type="button"
                    className="admin-dialog__button admin-dialog__button--cancel"
                    onClick={cancel}
                  >
                    {cancelLabel}
                  </button>
                ) : null}

                <button
                  ref={confirmButtonRef}
                  type="button"
                  className={[
                    "admin-dialog__button",
                    "admin-dialog__button--confirm",
                    mode === "danger" ? "admin-dialog__button--danger" : "",
                  ].filter(Boolean).join(" ")}
                  onClick={onConfirm}
                >
                  {confirmLabel}
                </button>
              </>
            ) : actionItems ? (
              actionItems.map((action, index) => {
                const variant = action.variant || "primary";

                return (
                  <button
                    key={action.key || `${action.label || "action"}-${index}`}
                    ref={action.autoFocus ? confirmButtonRef : undefined}
                    type="button"
                    className={[
                      "admin-dialog__button",
                      variant === "secondary"
                        ? "admin-dialog__button--secondary"
                        : "admin-dialog__button--confirm",
                      variant === "danger"
                        ? "admin-dialog__button--danger"
                        : "",
                    ].filter(Boolean).join(" ")}
                    disabled={Boolean(action.disabled)}
                    onClick={action.onClick}
                  >
                    {action.label}
                  </button>
                );
              })
            ) : actions}
          </div>
        ) : null}
      </section>
    </div>,
    document.body
  );
}
