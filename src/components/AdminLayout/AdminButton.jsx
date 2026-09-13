export default function AdminButton({
  variant = "primary",
  fullWidth = false,
  size = "",
  className = "",
  ...props
}) {
  return (
    <button
      {...props}
      className={[
        "admin-button",
        variant && variant !== "primary" ? `admin-button--${variant}` : "",
        fullWidth ? "admin-button--full" : "",
        size ? `admin-button--${size}` : "",
        className,
      ].filter(Boolean).join(" ")}
    />
  );
}
