export default function AdminNotice({
  children,
  variant = "info",
  className = "",
}) {
  return (
    <div
      className={[
        "admin-notice",
        variant ? `admin-notice--${variant}` : "",
        className,
      ].filter(Boolean).join(" ")}
      role={variant === "danger" ? "alert" : "status"}
    >
      {children}
    </div>
  );
}
