import { useEffect, useState } from "react";
import "./address-modal.css";

const emptyAddress = {
  street_1: "",
  street_2: "",
  city: "",
  state: "",
  postal_code: "",
  country_code: "US",
};

export default function AddressModal({
  open,
  title = "Address",
  address = null,
  saving = false,
  onClose,
  onSave,
  onRemove,
}) {
  const [form, setForm] = useState(emptyAddress);

  useEffect(() => {
    if (!open) return;
    setForm({
      ...emptyAddress,
      ...(address || {}),
      country_code: address?.country_code || "US",
    });
  }, [address, open]);

  if (!open) return null;

  function updateField(field, value) {
    setForm((prev) => ({ ...prev, [field]: value }));
  }

  function handleSave(event) {
    event.preventDefault();
    onSave?.({
      ...form,
      street_1: form.street_1.trim(),
      street_2: form.street_2.trim(),
      city: form.city.trim(),
      state: form.state.trim(),
      postal_code: form.postal_code.trim(),
      country_code: form.country_code.trim().toUpperCase() || "US",
    });
  }

  return (
    <div className="address-modal" role="dialog" aria-modal="true" aria-label={title}>
      <div className="address-modal__backdrop" onClick={onClose} />
      <form className="address-modal__panel" onSubmit={handleSave}>
        <div className="address-modal__header">
          <h2>{title}</h2>
          <button type="button" className="address-modal__icon-btn" onClick={onClose} aria-label="Close">
            x
          </button>
        </div>

        <div className="address-modal__grid">
          <label className="address-modal__wide">
            <span>Street 1</span>
            <input value={form.street_1} onChange={(event) => updateField("street_1", event.target.value)} required />
          </label>
          <label className="address-modal__wide">
            <span>Street 2</span>
            <input value={form.street_2} onChange={(event) => updateField("street_2", event.target.value)} />
          </label>
          <label>
            <span>City</span>
            <input value={form.city} onChange={(event) => updateField("city", event.target.value)} required />
          </label>
          <label>
            <span>State</span>
            <input value={form.state} onChange={(event) => updateField("state", event.target.value)} required />
          </label>
          <label>
            <span>Postal code</span>
            <input value={form.postal_code} onChange={(event) => updateField("postal_code", event.target.value)} required />
          </label>
          <label>
            <span>Country code</span>
            <input
              value={form.country_code}
              onChange={(event) => updateField("country_code", event.target.value.toUpperCase().slice(0, 2))}
              required
            />
          </label>
        </div>

        <div className="address-modal__actions">
          {address?.id && onRemove ? (
            <button type="button" className="address-modal__btn address-modal__btn--danger" onClick={onRemove} disabled={saving}>
              Remove Address
            </button>
          ) : <span />}
          <div className="address-modal__action-cluster">
            <button type="button" className="address-modal__btn" onClick={onClose} disabled={saving}>
              Cancel
            </button>
            <button type="submit" className="address-modal__btn address-modal__btn--primary" disabled={saving}>
              {saving ? "Saving..." : "Save & Close"}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
}
