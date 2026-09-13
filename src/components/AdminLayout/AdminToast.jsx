import { useEffect } from "react";
import { createPortal } from "react-dom";

export default function AdminToast({
  open = true,
  label = "",
  message = "",
  children = null,
  variant = "default",
  duration = 6000,
  onClose,
  className = "",
}) {
  useEffect(() => {
    if (!open || !onClose || duration === null || duration === false) {
      return undefined;
    }

    const ms = Number(duration);
    if (!Number.isFinite(ms) || ms <= 0) return undefined;

    const timer = window.setTimeout(() => onClose(), ms);
    return () => window.clearTimeout(timer);
  }, [open, duration, onClose]);

  if (!open) return null;

  const toast = (
    <div className="admin-toast-region" aria-live="polite" aria-atomic="true">
      <div
        className={[
          "admin-toast",
          variant && variant !== "default" ? `admin-toast--${variant}` : "",
          className,
        ].filter(Boolean).join(" ")}
        role="status"
      >
        {label ? <div className="admin-toast__label">{label}</div> : null}
        {children || message}
      </div>
    </div>
  );

  return createPortal(toast, document.body);
}
