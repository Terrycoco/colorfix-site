import { useEffect, useRef, useState, useCallback } from "react";
import PropTypes from "prop-types";
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import "./editableswatch.css";

function normalizeHex(hex) {
  if (!hex) return "";
  const h = hex.startsWith("#") ? hex.slice(1) : hex;
  return h.toUpperCase();
}

export default function EditableSwatch({
  value,
  onChange,
  label,
  size = "sm",
  disabled = false,
  readOnly = false,
  showName = false,
  className = "",
  placement = "bottom",
}) {
  const [open, setOpen] = useState(false);
  const btnRef = useRef(null);
  const popRef = useRef(null);

  const hex = normalizeHex(value && (value.hex6 || value.hex));
  const chipBg = hex ? `#${hex}` : "transparent";
  const title = label
    ? value && value.name
      ? `${label}: ${value.name}${value.code ? ` (${value.code})` : ""}`
      : `${label}: (choose)`
    : (value && value.name) || "(choose)";

  // click-outside to close
  useEffect(() => {
    if (!open) return;
    function onDocClick(e) {
      const t = e.target;
      if (popRef.current && popRef.current.contains(t)) return;
      if (btnRef.current && btnRef.current.contains(t)) return;
      setOpen(false);
    }
    document.addEventListener("mousedown", onDocClick);
    return () => document.removeEventListener("mousedown", onDocClick);
  }, [open]);

  // keyboard open on button
  function onKeyDownBtn(e) {
    if (disabled) return;
    if (e.key === "Enter" || e.key === " ") {
      e.preventDefault();
      setOpen((v) => !v);
    }
  }

  // ESC inside popover
  function onKeyDownPop(e) {
    if (e.key === "Escape") {
      e.stopPropagation();
      setOpen(false);
      if (btnRef.current) btnRef.current.focus();
    }
  }

  const handleSelect = useCallback(
    (swatch) => {
      if (readOnly) return;
      const next = swatch
        ? { ...swatch, hex6: normalizeHex(swatch.hex6 || swatch.hex) }
        : null;
      onChange(next);
      setOpen(false);
      if (btnRef.current) btnRef.current.focus();
    },
    [onChange, readOnly]
  );

  return (
    <div className={`ed-swatch ${className}`}>
      {label ? <div className="ed-swatch__label">{label}</div> : null}

      <button
        type="button"
        ref={btnRef}
        className={`ed-swatch__btn ed-swatch__btn--${size} ${
          disabled ? "is-disabled" : ""
        } ${readOnly ? "is-readonly" : ""}`}
        aria-haspopup="dialog"
        aria-expanded={open}
        aria-controls="ed-swatch-popover"
        title={title}
        onClick={() => {
          if (disabled) return;
          setOpen((v) => !v);
        }}
        onKeyDown={onKeyDownBtn}
      >
        <span
          className={`ed-swatch__chip ed-swatch__chip--${size}`}
          style={{ backgroundColor: chipBg }}
          aria-hidden="true"
        />
        {showName && (
          <span className="ed-swatch__name">
            {(value && value.name) || "Choose color"}
            {value && value.code ? ` · ${value.brand}` : ""}
          </span>
        )}
        <span className="ed-swatch__caret" aria-hidden="true">▾</span>
      </button>

      {open && (
        <div
          id="ed-swatch-popover"
          ref={popRef}
          className={`ed-swatch__popover ed-swatch__popover--${placement}`}
          role="dialog"
          aria-label="Pick a color"
          onKeyDown={onKeyDownPop}
        >
          <FuzzySearchColorSelect
            onSelect={handleSelect}
            label={label}
            onCancel={() => setOpen(false)}
            autoFocus
          />
        </div>
      )}
    </div>
  );
}

EditableSwatch.propTypes = {
  value: PropTypes.shape({
    id: PropTypes.oneOfType([PropTypes.number, PropTypes.string]),
    name: PropTypes.string,
    brand: PropTypes.string,
    code: PropTypes.string,
    hex: PropTypes.string,
    hex6: PropTypes.string,
  }),
  onChange: PropTypes.func.isRequired,
  label: PropTypes.string,
  size: PropTypes.oneOf(["xs", "sm", "md"]),
  disabled: PropTypes.bool,
  readOnly: PropTypes.bool,
  showName: PropTypes.bool,
  className: PropTypes.string,
  placement: PropTypes.oneOf(["bottom", "top"]),
};
