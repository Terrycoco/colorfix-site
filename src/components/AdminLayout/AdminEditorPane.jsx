export default function AdminEditorPane({
  kind = "fields",
  className = "",
  children,
}) {
  return (
    <div
      className={[
        "admin-editor__pane",
        kind ? `admin-editor__pane--${kind}` : "",
        className,
      ].filter(Boolean).join(" ")}
    >
      {children}
    </div>
  );
}
