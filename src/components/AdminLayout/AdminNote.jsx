export default function AdminNote({
  children,
  className = "",
}) {
  return (
    <div className={["admin-note", className].filter(Boolean).join(" ")}>
      {children}
    </div>
  );
}
