import { useEffect, useRef } from "react";
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

  const onConfirmRef = useRef(onConfirm);
  const cancelRef = useRef(cancel);
  const modeRef = useRef(mode);

  onConfirmRef.current = onConfirm;
  cancelRef.current = cancel;
  modeRef.current = mode;

  useEffect(() => {
    if (!open) return undefined;

    const previousActiveElement = document.activeElement;

    window.requestAnimationFrame(() => {
      const dialog = dialogRef.current;

      const firstFormControl = dialog?.querySelector(
        [
          "input:not([disabled])",
          "select:not([disabled])",
          "textarea:not([disabled])",
        ].join(",")
      );

      const target =
        firstFormControl
        || confirmButtonRef.current
        || dialog;

      target?.focus?.();

      if (
        firstFormControl
        && firstFormControl instanceof HTMLInputElement
        && ["text", "search", "email", "url", "tel"].includes(firstFormControl.type)
      ) {
        const length = firstFormControl.value.length;
        firstFormControl.setSelectionRange?.(length, length);
      }
    });

    const handleKeyDown = (event) => {
      if (event.key === "Escape") {
        event.preventDefault();

        if (modeRef.current === "alert") {
          onConfirmRef.current?.();
        } else {
          cancelRef.current?.();
        }
        return;
      }

      if (event.key !== "Tab") return;

      const dialog = dialogRef.current;
      if (!dialog) return;

      const focusable = Array.from(
        dialog.querySelectorAll(
          [
            "button:not([disabled])",
            "[href]",
            "input:not([disabled])",
            "select:not([disabled])",
            "textarea:not([disabled])",
            '[tabindex]:not([tabindex="-1"])',
          ].join(",")
        )
      );

      if (focusable.length === 0) {
        event.preventDefault();
        return;
      }

      const first = focusable[0];
      const last = focusable[focusable.length - 1];

      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener("keydown", handleKeyDown);

    return () => {
      document.removeEventListener("keydown", handleKeyDown);
      previousActiveElement?.focus?.();
    };
  }, [open]);

  if (!open) return null;

  const hasBuiltInActions = actions === null;
  const actionItems = Array.isArray(actions)
    ? actions.filter(Boolean)
    : null;
  const titleId = title ? "admin-dialog-title" : undefined;
  const messageId = message ? "admin-dialog-message" : undefined;

  return createPortal(
    <div
      className="admin-dialog-backdrop"
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
