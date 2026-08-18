export default function AdminWorkbenchAddButton({
  onClick,
  disabled = false,
  title = "Add",
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      title={title}
      aria-label={title}
      style={{
        width: 24,
        height: 24,
        padding: 0,
        display: "grid",
        placeItems: "center",
        fontSize: 18,
        lineHeight: 1,
      }}
    >
      +
    </button>
  );
}