import { useEffect } from "react";
import "./toast.css";

export default function Toast({
  toast,
  type,
  message,
  title,
  onClose,
  successDuration = 7000,
  errorDuration = 12000,
}) {
  const resolvedType = type || toast?.type || "success";
  const resolvedMessage = message || toast?.text || toast?.message || "";
  const resolvedTitle = title || toast?.title || (resolvedType === "error" ? "Action failed" : "Action complete");

  useEffect(() => {
    if (!resolvedMessage || !onClose) return undefined;
    const duration = resolvedType === "error" ? errorDuration : successDuration;
    const timeout = window.setTimeout(onClose, duration);
    return () => window.clearTimeout(timeout);
  }, [errorDuration, onClose, resolvedMessage, resolvedType, successDuration]);

  if (!resolvedMessage) return null;

  return (
    <div className={`app-toast app-toast--${resolvedType}`} role="status" aria-live="polite">
      <div className="app-toast__title">{resolvedTitle}</div>
      <div className="app-toast__body">{resolvedMessage}</div>
      {onClose ? (
        <button type="button" className="app-toast__close" onClick={onClose} aria-label="Dismiss notification">
          Close
        </button>
      ) : null}
    </div>
  );
}
