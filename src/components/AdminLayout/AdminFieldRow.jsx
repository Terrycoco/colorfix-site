export default function AdminFieldRow({
  children,
  className = "",
}) {
  return (
    <div
      className={[
        "admin-field-row",
        className,
      ].filter(Boolean).join(" ")}
    >
      {children}
    </div>
  );
}
