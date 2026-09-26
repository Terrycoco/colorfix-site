import { useState } from "react";
import RexReservationDialog from "@components/REX/RexReservationDialog";
import "./FetchRexButton.css";

function absoluteUrl(value) {
  const url = String(value || "").trim();
  if (!url) return "";

  try {
    return new URL(url, window.location.origin).toString();
  } catch {
    return url;
  }
}

function adminRexUrl(value) {
  const url = absoluteUrl(value);
  if (!url) return "";

  try {
    const parsed = new URL(url, window.location.origin);
    parsed.searchParams.set("back", "1");
    return parsed.toString();
  } catch {
    return url;
  }
}

export default function FetchRexButton({
  request,
  onCreated,
  buttonLabel = "Fetch REX",
  disabled = false,
  className = "",
  existingUrl = "",
  resolveExistingUrl = null,
}) {
  const [open, setOpen] = useState(false);
  const [checking, setChecking] = useState(false);

  async function handleClick() {
    if (disabled || checking) return;

    setChecking(true);

    try {
      let url = absoluteUrl(existingUrl);

      if (!url && typeof resolveExistingUrl === "function") {
        url = absoluteUrl(await resolveExistingUrl(request));
      }

      if (url) {
        window.location.href = adminRexUrl(url);
        return;
      }

      setOpen(true);
    } finally {
      setChecking(false);
    }
  }

  return (
    <>
      <button
        type="button"
        className={["fetch-rex-button", className].filter(Boolean).join(" ")}
        disabled={disabled || checking}
        onClick={handleClick}
      >
        {buttonLabel}
      </button>

      <RexReservationDialog
        open={open}
        request={request}
        onClose={() => setOpen(false)}
        onCreated={(result) => onCreated?.(result)}
      />
    </>
  );
}
