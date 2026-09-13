export default function AdminStack({
  children,
  gap = "md",
  fill = false,
  className = "",
}) {
  return (
    <div
      className={[
        "admin-stack",
        gap ? `admin-stack--${gap}` : "",
        fill ? "admin-stack--fill" : "",
        className,
      ].filter(Boolean).join(" ")}
    >
      {children}
    </div>
  );
}
