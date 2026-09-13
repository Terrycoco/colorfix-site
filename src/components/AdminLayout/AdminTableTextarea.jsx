function cssSize(value, fallback) {
  if (value === null || value === undefined || value === "") return fallback;
  return typeof value === "number" ? `${value}px` : String(value);
}

export default function AdminTableTextarea({
  width = "100%",
  className = "",
  ...props
}) {
  return (
    <textarea
      {...props}
      className={["admin-table-textarea", className].filter(Boolean).join(" ")}
      style={{ "--admin-table-textarea-width": cssSize(width, "100%") }}
    />
  );
}
