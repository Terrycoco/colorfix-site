import { useEffect, useId, useMemo, useRef, useState } from "react";
import { createPortal } from "react-dom";
import "./MobileSelect.css";

/**
 * Generic touch-friendly record/option picker.
 * options: [{ value, label, detail?, disabled? }]
 * onChange(value) is called only when a user selects an enabled option.
 */
export default function MobileSelect({
  title = "Select",
  label = "",
  options = [],
  value = "",
  onChange,
  searchable = false,
  searchPlaceholder = "Search…",
  placeholder = "Choose…",
  disabled = false,
  className = "",
}) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const searchRef = useRef(null);
  const triggerRef = useRef(null);
  const titleId = useId();

  const selected = options.find((item) => String(item.value) === String(value));
  const filtered = useMemo(() => {
    const needle = search.trim().toLocaleLowerCase();
    return needle ? options.filter((item) =>
      `${item.label ?? ""} ${item.detail ?? ""}`.toLocaleLowerCase().includes(needle)
    ) : options;
  }, [options, search]);

  useEffect(() => {
    if (!open) return undefined;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    if (searchable) searchRef.current?.focus();
    function onKeyDown(event) {
      if (event.key === "Escape") setOpen(false);
    }
    document.addEventListener("keydown", onKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener("keydown", onKeyDown);
      triggerRef.current?.focus();
    };
  }, [open, searchable]);

  const close = () => { setOpen(false); setSearch(""); };
  const choose = (item) => {
    if (item.disabled) return;
    close();
    onChange?.(item.value);
  };

  return (
    <div className={`admin-mobile-select ${className}`.trim()}>
      {label ? <span className="admin-mobile-select__label">{label}</span> : null}
      <button
        ref={triggerRef}
        type="button"
        disabled={disabled}
        className="admin-mobile-select__trigger"
        aria-haspopup="dialog"
        aria-expanded={open}
        onClick={() => { setSearch(""); setOpen(true); }}
      >
        <span className="admin-mobile-select__current">{selected?.label ?? placeholder}</span>
        <span aria-hidden="true" className="admin-mobile-select__chevron">⌄</span>
      </button>
      {open && typeof document !== "undefined" ? createPortal(
        <div className="admin-mobile-select__overlay" role="dialog" aria-modal="true" aria-labelledby={titleId}>
          <header className="admin-mobile-select__header">
            <button type="button" className="admin-mobile-select__back" onClick={close} aria-label="Close selection">←</button>
            <h2 id={titleId}>{title}</h2>
            <button type="button" className="admin-mobile-select__cancel" onClick={close}>Cancel</button>
          </header>
          {searchable ? (
            <div className="admin-mobile-select__search-wrap">
              <input
                ref={searchRef}
                className="admin-mobile-select__search"
                type="search"
                value={search}
                placeholder={searchPlaceholder}
                aria-label={`Search ${title}`}
                onChange={(event) => setSearch(event.target.value)}
              />
            </div>
          ) : null}
          <div className="admin-mobile-select__options" role="listbox" aria-label={title}>
            {filtered.map((item, index) => {
              const isSelected = String(value) === String(item.value);
              return (
                <button
                  key={`${String(item.value)}-${index}`}
                  type="button"
                  role="option"
                  aria-selected={isSelected}
                  disabled={item.disabled}
                  className={`admin-mobile-select__option ${isSelected ? "is-selected" : ""}`}
                  onClick={() => choose(item)}
                >
                  <span className="admin-mobile-select__option-content">
                    <span className="admin-mobile-select__option-label">{item.label}</span>
                    {item.detail ? <span className="admin-mobile-select__option-detail">{item.detail}</span> : null}
                  </span>
                  {isSelected ? <span className="admin-mobile-select__check" aria-hidden="true">✓</span> : null}
                </button>
              );
            })}
            {filtered.length === 0 ? <p className="admin-mobile-select__empty">No matching items.</p> : null}
          </div>
        </div>, document.body
      ) : null}
    </div>
  );
}
