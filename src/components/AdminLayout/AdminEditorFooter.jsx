export default function AdminEditorFooter({
  leading = null,
  className = "",
  children,
}) {
  return (
    <footer
      className={[
        "admin-editor__footer",
        className,
      ].filter(Boolean).join(" ")}
    >
      <div className="admin-editor__footer-leading">
        {leading}
      </div>

      <div className="admin-editor__footer-actions">
        {children}
      </div>
    </footer>
  );
}
