export default function AdminEditorForm({
  className = "",
  children,
  ...props
}) {
  return (
    <form
      {...props}
      className={[
        "admin-editor__form",
        className,
      ].filter(Boolean).join(" ")}
    >
      {children}
    </form>
  );
}
