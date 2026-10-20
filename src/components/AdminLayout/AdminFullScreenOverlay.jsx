import { createPortal } from "react-dom";
import useOverlayLifecycle from "../../hooks/useOverlayLifecycle";

export default function AdminFullScreenOverlay({
  open = true,
  children,
  className = "",
  ariaLabel,
  modal = false,
  onClose,
}) {
  const overlayRef = useOverlayLifecycle({
    open, layer: modal ? "editor" : "fullscreen", modal, autoFocus: modal,
    onEscape: onClose,
  });
  if (!open) return null;

  return createPortal(
    <div
      ref={overlayRef}
      className={["admin-fullscreen-overlay", className].filter(Boolean).join(" ")}
      role={modal ? "dialog" : "region"}
      aria-modal={modal || undefined}
      aria-label={ariaLabel}
      onClick={(event) => {
        if (modal && event.target === event.currentTarget) onClose?.();
      }}
    >
      {children}
    </div>,
    document.body
  );
}
