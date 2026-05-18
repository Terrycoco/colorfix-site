import { useEffect, useState } from "react";
import ModalDialog from "@components/ModalDialog";
import "./lookup-type-manager-modal.css";

export default function LookupTypeManagerModal({
  open = false,
  title = "Manage Types",
  subtitle = "",
  items = [],
  loading = false,
  saving = false,
  error = "",
  inputLabel = "Label",
  placeholder = "Add a new option",
  createButtonLabel = "Add",
  emptyMessage = "No options yet.",
  helperText = "",
  onClose,
  onCreate,
}) {
  const [label, setLabel] = useState("");

  useEffect(() => {
    if (!open) {
      setLabel("");
    }
  }, [open]);

  async function handleSubmit(event) {
    event.preventDefault();
    const normalized = label.trim();
    if (!normalized || !onCreate) return;
    const created = await onCreate(normalized);
    if (created !== false) {
      setLabel("");
    }
  }

  return (
    <ModalDialog open={open} onClose={onClose} title={title} subtitle={subtitle} width="560px">
      <div className="lookup-type-manager">
        <div className="lookup-type-manager__section">
          <div className="lookup-type-manager__title">Existing options</div>
          {loading ? <div className="lookup-type-manager__status">Loading…</div> : null}
          {!loading && items.length === 0 ? <div className="lookup-type-manager__status">{emptyMessage}</div> : null}
          {!loading && items.length > 0 ? (
            <div className="lookup-type-manager__chips">
              {items.map((item) => (
                <div key={item.key} className="lookup-type-manager__chip">
                  <strong>{item.label}</strong>
                  <span>{item.key}</span>
                </div>
              ))}
            </div>
          ) : null}
        </div>

        <form className="lookup-type-manager__section lookup-type-manager__form" onSubmit={handleSubmit}>
          <label>
            {inputLabel}
            <input
              type="text"
              value={label}
              onChange={(e) => setLabel(e.target.value)}
              placeholder={placeholder}
            />
          </label>
          {helperText ? <div className="lookup-type-manager__help">{helperText}</div> : null}
          {error ? <div className="lookup-type-manager__error">{error}</div> : null}
          <div className="lookup-type-manager__actions">
            <button type="submit" className="lookup-type-manager__primary" disabled={saving || !label.trim()}>
              {saving ? "Saving…" : createButtonLabel}
            </button>
          </div>
        </form>
      </div>
    </ModalDialog>
  );
}

