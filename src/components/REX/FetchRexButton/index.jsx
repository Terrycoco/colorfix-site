import { useState } from "react";
import RexReservationDialog from "@components/REX/RexReservationDialog";
import "./FetchRexButton.css";

export default function FetchRexButton({
  request,
  onCreated,
  buttonLabel = "Fetch REX",
  disabled = false,
  className = "",
}) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <button
        type="button"
        className={`fetch-rex-button ${className}`.trim()}
        disabled={disabled}
        onClick={() => setOpen(true)}
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
