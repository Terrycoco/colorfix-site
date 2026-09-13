export default function AdminCheckboxRow({
  checked = false,
  disabled = false,
  onChange,
  children,
  className = "",
}) {
  return (
    <label
      className={[
        "admin-checkbox-row",
        disabled ? "is-disabled" : "",
        className,
      ].filter(Boolean).join(" ")}
    >
      <input
        type="checkbox"
        checked={checked}
        disabled={disabled}
        onChange={onChange}
      />

      <span className="admin-checkbox-row__label">
        {children}
      </span>
    </label>
  );
}
