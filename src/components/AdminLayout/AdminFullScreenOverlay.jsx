import { createPortal } from "react-dom";

export default function AdminFullScreenOverlay({
  open = true,
  children,
  className = "",
  ariaLabel,
}) {
  if (!open) return null;

  return createPortal(
    <div
      className={["admin-fullscreen-overlay", className].filter(Boolean).join(" ")}
      role="region"
      aria-label={ariaLabel}
    >
      {children}
    </div>,
    document.body
  );
}
