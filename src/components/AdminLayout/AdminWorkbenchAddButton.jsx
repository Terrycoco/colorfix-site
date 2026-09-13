export default function AdminWorkbenchAddButton({
  onClick,
  disabled = false,
  title = "Add",
  className = "",
}) {
  return (
    <button
      type="button"
      className={[
        "admin-workbench-add-button",
        className,
      ].filter(Boolean).join(" ")}
      onClick={onClick}
      disabled={disabled}
      title={title}
      aria-label={title}
    >
      +
    </button>
  );
}
