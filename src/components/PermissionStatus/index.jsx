import { useEffect, useRef, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./permission-status.css";

const PERMISSION_STATUS_URL = `${API_FOLDER}/v2/admin/photo-library/permission-status.php`;
const PERMISSION_STATUS_EVENT = "colorfix:photo-permission-status";

const STATUS_COPY = {
  not_needed: "Permission not needed",
  granted: "Permission granted",
  requested: "Permission requested",
  declined: "Permission declined",
  unknown: "Permission unknown",
  none: "No client linked",
};

const EDIT_OPTIONS = [
  { value: "not_needed", label: "Not needed" },
  { value: "granted", label: "Granted" },
  { value: "requested", label: "Requested" },
  { value: "declined", label: "Declined" },
  { value: "default", label: "Use client/default" },
];

export default function PermissionStatus({
  status,
  photoLibraryId,
  clientId,
  clientName,
  clientEmail,
  showLabel = false,
  className = "",
}) {
  const [localStatus, setLocalStatus] = useState(status);
  const [draftStatus, setDraftStatus] = useState(normalizeDraftStatus(status));
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const rootRef = useRef(null);
  const fieldNameRef = useRef(`permission-status-${Math.random().toString(36).slice(2)}`);
  const canEdit = Number(photoLibraryId || 0) > 0;

  useEffect(() => {
    setLocalStatus(status);
    setDraftStatus(normalizeDraftStatus(status));
  }, [status]);

  useEffect(() => {
    function onPermissionChange(event) {
      const detail = event?.detail || {};
      if (Number(detail.photo_library_id || 0) !== Number(photoLibraryId || 0)) return;
      const nextStatus = detail.permission?.photo_permission_status || detail.photo_permission_status || "unknown";
      setLocalStatus(nextStatus);
      setDraftStatus(normalizeDraftStatus(nextStatus));
    }
    window.addEventListener(PERMISSION_STATUS_EVENT, onPermissionChange);
    return () => window.removeEventListener(PERMISSION_STATUS_EVENT, onPermissionChange);
  }, [photoLibraryId]);

  useEffect(() => {
    if (!open) return undefined;
    function onPointerDown(event) {
      if (!rootRef.current || rootRef.current.contains(event.target)) return;
      saveStatus();
    }
    document.addEventListener("pointerdown", onPointerDown, true);
    return () => document.removeEventListener("pointerdown", onPointerDown, true);
  }, [open, draftStatus, saving]);

  const normalized = normalizeStatus(localStatus);
  const hasClient = Number(clientId || 0) > 0 || Boolean(clientName) || Boolean(clientEmail);
  const displayStatus = normalized === "unknown" && !hasClient && !canEdit ? "none" : normalized;
  const label = STATUS_COPY[displayStatus] || STATUS_COPY.unknown;
  const clientText = [clientName, clientEmail].filter(Boolean).join(" / ");
  const titleParts = [label];
  if (photoLibraryId) titleParts.push(`Photo #${photoLibraryId}`);
  if (clientText) titleParts.push(clientText);
  const title = titleParts.join(" - ");

  function toggleEditor() {
    if (!canEdit) return;
    if (open) {
      saveStatus();
      return;
    }
    setDraftStatus(normalizeDraftStatus(localStatus));
    setOpen(true);
  }

  async function saveStatus() {
    if (!canEdit || saving) return;
    const nextStatus = draftStatus === "default" ? "" : draftStatus;
    const nextDisplayStatus = nextStatus || "unknown";
    if (normalizeStatus(localStatus) === normalizeStatus(nextDisplayStatus)) {
      setOpen(false);
      return;
    }
    setSaving(true);
    try {
      const res = await fetch(PERMISSION_STATUS_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          photo_library_id: Number(photoLibraryId),
          photo_permission_status: nextStatus,
        }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Permission update failed");
      }
      const permission = data?.permission || {};
      setLocalStatus(permission.photo_permission_status || nextStatus || "unknown");
      window.dispatchEvent(new CustomEvent(PERMISSION_STATUS_EVENT, {
        detail: {
          photo_library_id: Number(photoLibraryId),
          permission,
        },
      }));
      setOpen(false);
    } catch (error) {
      window.alert(error?.message || "Permission update failed");
    } finally {
      setSaving(false);
    }
  }

  return (
    <span ref={rootRef} className="permission-status-wrap">
      <span
      role={canEdit ? "button" : undefined}
      tabIndex={canEdit ? 0 : undefined}
      className={[
        "permission-status",
        `permission-status--${displayStatus}`,
        canEdit ? "permission-status--editable" : "",
        saving ? "is-saving" : "",
        className,
      ].filter(Boolean).join(" ")}
      title={title}
      aria-label={title}
      aria-haspopup={canEdit ? "menu" : undefined}
      aria-expanded={canEdit ? open : undefined}
      onClick={(event) => {
        event.stopPropagation();
        toggleEditor();
      }}
      onKeyDown={(event) => {
        if (!canEdit) return;
        if (event.key === "Enter" || event.key === " ") {
          event.preventDefault();
          event.stopPropagation();
          toggleEditor();
        }
      }}
    >
      <span className="permission-status__dot" aria-hidden="true" />
      {showLabel ? <span className="permission-status__label">{label}</span> : null}
      </span>
      {open ? (
        <span className="permission-status-menu" role="dialog" onClick={(event) => event.stopPropagation()}>
          <span className="permission-status-menu__title">Photo #{photoLibraryId}</span>
          {EDIT_OPTIONS.map((option) => (
            <label key={option.value} className="permission-status-menu__option">
              <input
                type="radio"
                name={fieldNameRef.current}
                value={option.value}
                checked={draftStatus === option.value}
                onChange={() => setDraftStatus(option.value)}
              />
              <span
                className={[
                  "permission-status-menu__dot",
                  `permission-status-menu__dot--${option.value}`,
                ].join(" ")}
                aria-hidden="true"
              />
              <span>{option.label}</span>
            </label>
          ))}
          {saving ? <span className="permission-status-menu__saving">Saving...</span> : null}
        </span>
      ) : null}
    </span>
  );
}

function normalizeStatus(status) {
  const value = String(status || "").trim().toLowerCase();
  if (value === "not_needed" || value === "granted" || value === "requested" || value === "declined") return value;
  return "unknown";
}

function normalizeDraftStatus(status) {
  const value = normalizeStatus(status);
  return value === "unknown" ? "default" : value;
}
