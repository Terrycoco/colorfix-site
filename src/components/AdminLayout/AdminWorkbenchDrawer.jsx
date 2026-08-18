export default function AdminWorkbenchDrawer({
  open,
  width = 380,
  title = "",
  onClose,
  children,
}) {
  if (!open) {
    return null;
  }

  return (
    <aside
      style={{
        width,
        minWidth: width,
        maxWidth: width,
        minHeight: 0,
        display: "flex",
        flexDirection: "column",
        borderLeft:
          "1px solid var(--admin-layout-border, #d8dde3)",
        background:
          "var(--admin-layout-bg, #fff)",
      }}
    >
      <div
        style={{
          height: 40,
          flexShrink: 0,
          display: "flex",
          alignItems: "center",
          justifyContent: "space-between",
          gap: 8,
          padding: "0 10px",
          borderBottom:
            "1px solid var(--admin-layout-border, #d8dde3)",
          background:
            "var(--admin-layout-header-bg, #f7f8fa)",
        }}
      >
        <strong
          style={{
            fontSize: 12,
          }}
        >
          {title}
        </strong>

        {onClose ? (
          <button
            type="button"
            onClick={onClose}
            aria-label="Close drawer"
            title="Close"
            style={{
              padding: "2px 7px",
            }}
          >
            ×
          </button>
        ) : null}
      </div>

      <div
        style={{
          flex: 1,
          minHeight: 0,
          overflow: "auto",
        }}
      >
        {children}
      </div>
    </aside>
  );
}