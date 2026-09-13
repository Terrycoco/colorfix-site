export default function AdminEditorBody({
  layout = "single",
  className = "",
  children,
}) {
  return (
    <div
      className={[
        "admin-editor__body",
        layout === "split" ? "admin-editor__body--split" : "",
        className,
      ].filter(Boolean).join(" ")}
    >
      {children}
    </div>
  );
}
