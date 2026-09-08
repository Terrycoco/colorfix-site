import {
  useEffect,
  useRef,
} from "react";

import { createPortal } from "react-dom";

import "./AdminDialog.css";

export default function AdminDialog({
  open,
  mode = "confirm",
  title = "",
  message = "",
  confirmLabel = "OK",
  cancelLabel = "Cancel",
  onConfirm,
  onCancel,
}) {
  const dialogRef = useRef(null);
  const confirmButtonRef = useRef(null);

  useEffect(() => {
    if (!open) {
      return undefined;
    }

    const previousActiveElement = document.activeElement;

    window.requestAnimationFrame(() => {
      confirmButtonRef.current?.focus();
    });

    const handleKeyDown = (event) => {
      if (event.key === "Escape") {
        event.preventDefault();

        if (mode === "alert") {
          onConfirm?.();
        } else {
          onCancel?.();
        }

        return;
      }

      if (event.key !== "Tab") {
        return;
      }

      const dialog = dialogRef.current;

      if (!dialog) {
        return;
      }

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

      if (
        event.shiftKey
        && document.activeElement === first
      ) {
        event.preventDefault();
        last.focus();
      } else if (
        !event.shiftKey
        && document.activeElement === last
      ) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener(
      "keydown",
      handleKeyDown
    );

    return () => {
      document.removeEventListener(
        "keydown",
        handleKeyDown
      );

      if (
        previousActiveElement
        && typeof previousActiveElement.focus === "function"
      ) {
        previousActiveElement.focus();
      }
    };
  }, [
    open,
    mode,
    onConfirm,
    onCancel,
  ]);

  if (!open) {
    return null;
  }

  return createPortal(
    <div
      className="admin-dialog-backdrop"
      role="presentation"
      onMouseDown={(event) => {
        if (event.target !== event.currentTarget) {
          return;
        }

        if (mode === "alert") {
          return;
        }

        onCancel?.();
      }}
    >
      <section
        ref={dialogRef}
        className={[
          "admin-dialog",
          mode === "danger"
            ? "admin-dialog--danger"
            : "",
        ]
          .filter(Boolean)
          .join(" ")}
        role="dialog"
        aria-modal="true"
        aria-labelledby="admin-dialog-title"
        aria-describedby="admin-dialog-message"
      >
        <div className="admin-dialog__body">
          {title ? (
            <h2
              id="admin-dialog-title"
              className="admin-dialog__title"
            >
              {title}
            </h2>
          ) : null}

          {message ? (
            <div
              id="admin-dialog-message"
              className="admin-dialog__message"
            >
              {message}
            </div>
          ) : null}
        </div>

        <div className="admin-dialog__actions">
          {mode !== "alert" ? (
            <button
              type="button"
              className="admin-dialog__button admin-dialog__button--cancel"
              onClick={onCancel}
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
              mode === "danger"
                ? "admin-dialog__button--danger"
                : "",
            ]
              .filter(Boolean)
              .join(" ")}
            onClick={onConfirm}
          >
            {confirmLabel}
          </button>
        </div>
      </section>
    </div>,
    document.body
  );
}
