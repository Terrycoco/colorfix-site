export default function AdminWorkbenchPane({
  title = "",
  action = null,
  children,
  style = {},
}) {
  return (
    <section
      style={{
        minWidth: 0,
        minHeight: 0,

        display: "flex",
        flexDirection: "column",

        background:
          "var(--admin-layout-bg, #fff)",

        ...style,
      }}
    >
      <div
        style={{
          height: 38,
          flexShrink: 0,

          display: "flex",
          alignItems: "center",

          gap: 6,

          padding: "0 8px",

          borderBottom:
            "1px solid var(--admin-layout-border, #d8dde3)",

          background:
            "var(--admin-layout-header-bg, #f3f4f5)",
        }}
      >
        <strong
          style={{
            fontSize: 12,
          }}
        >
          {title}
        </strong>

        {action}
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
    </section>
  );
}