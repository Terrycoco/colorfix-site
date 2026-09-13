import { useEffect, useId, useRef } from "react";
import { createPortal } from "react-dom";

function cssSize(value) {
  if (value === null || value === undefined || value === "") return undefined;
  return typeof value === "number" ? `${value}px` : String(value);
}

function normalizeMeta(meta) {
  if (meta === null || meta === undefined || meta === false) return [];
  return Array.isArray(meta) ? meta.filter(Boolean) : [meta];
}

export default function AdminEditor({
  open,
  title = "",
  ariaLabel,
  status = null,
  statusVariant = "neutral",
  meta = null,
  size = "lg",
  width,
  busy = false,
  dismissOnBackdrop = true,
  dismissOnEscape = true,
  onClose,
  className = "",
  children,
}) {
  const editorRef = useRef(null);
  const titleId = useId();
  const metaItems = normalizeMeta(meta);
  const resolvedWidth = cssSize(width);

  useEffect(() => {
    if (!open) return undefined;

    const previousActiveElement = document.activeElement;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    window.requestAnimationFrame(() => {
      editorRef.current?.focus?.();
    });

    function handleKeyDown(event) {
      if (event.key !== "Escape") return;
      if (!dismissOnEscape || busy) return;

      /*
       * A smaller AdminDialog may be open on top of this editor.
       * Let that top-most dialog own Escape instead of closing both layers.
       */
      if (document.querySelector(".admin-dialog-backdrop")) return;

      event.preventDefault();
      onClose?.();
    }

    document.addEventListener("keydown", handleKeyDown);

    return () => {
      document.removeEventListener("keydown", handleKeyDown);
      document.body.style.overflow = previousOverflow;
      previousActiveElement?.focus?.();
    };
  }, [open, busy, dismissOnEscape, onClose]);

  if (!open) return null;

  const editor = (
    <div
      className="admin-editor-backdrop"
      role="presentation"
      onMouseDown={(event) => {
        if (event.target !== event.currentTarget) return;
        if (!dismissOnBackdrop || busy) return;
        onClose?.();
      }}
    >
      <section
        ref={editorRef}
        className={[
          "admin-editor",
          size ? `admin-editor--${size}` : "",
          className,
        ].filter(Boolean).join(" ")}
        style={
          resolvedWidth
            ? { "--admin-editor-width": resolvedWidth }
            : undefined
        }
        role="dialog"
        aria-modal="true"
        aria-labelledby={title ? titleId : undefined}
        aria-label={!title ? ariaLabel || "Admin editor" : undefined}
        tabIndex={-1}
      >
        <header className="admin-editor__header">
          <div className="admin-editor__heading">
            {title ? (
              <h2 id={titleId} className="admin-editor__title">
                {title}
              </h2>
            ) : null}

            {metaItems.length ? (
              <div className="admin-editor__meta">
                {metaItems.map((item, index) => (
                  <span key={index} className="admin-editor__meta-item">
                    {item}
                  </span>
                ))}
              </div>
            ) : null}
          </div>

          <div className="admin-editor__header-actions">
            {status ? (
              <span
                className={[
                  "admin-badge",
                  statusVariant ? `admin-badge--${statusVariant}` : "",
                ].filter(Boolean).join(" ")}
              >
                {status}
              </span>
            ) : null}

            {onClose ? (
              <button
                type="button"
                className="admin-editor__close"
                disabled={busy}
                onClick={onClose}
                aria-label="Close editor"
                title="Close"
              >
                ×
              </button>
            ) : null}
          </div>
        </header>

        <div className="admin-editor__content">
          {children}
        </div>
      </section>
    </div>
  );

  return createPortal(editor, document.body);
}
