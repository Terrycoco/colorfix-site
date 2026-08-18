export default function AdminWorkbenchTabs({
  tabs = [],
  activeKey,
  onChange,
  action = null,
  children,
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

        borderTop:
          "1px solid var(--admin-layout-border, #d8dde3)",
      }}
    >
      <div
        style={{
          height: 38,
          flexShrink: 0,

          display: "flex",
          alignItems: "stretch",

          gap: 4,

          padding: "0 8px",

          borderBottom:
            "1px solid var(--admin-layout-border, #d8dde3)",

          background:
            "var(--admin-layout-header-bg, #eef1f3)",
        }}
      >
        <div
          style={{
            display: "flex",
            alignItems: "stretch",

            minWidth: 0,

            gap: 2,
          }}
        >
          {tabs.map((tab) => {
            const selected =
              tab.key === activeKey;

            return (
              <button
                key={tab.key}
                type="button"

                onClick={() =>
                  onChange?.(tab.key)
                }

                style={{
                  border:
                    "1px solid var(--admin-layout-border, #cbd1d6)",

                  borderBottom:
                    selected
                      ? "1px solid var(--highlight-cyan)"
                      : "1px solid var(--admin-layout-border, #cbd1d6)",

                  background:
                    selected
                      ? "var(--highlight-cyan)"
                      : "#dfe4e8",

                  color:
                    selected
                      ? "#1f2933"
                      : "#39434d",

                  padding: "0 12px",

                  fontSize: 12,

                  fontWeight:
                    selected
                      ? 700
                      : 600,

                  cursor: "pointer",
                }}
              >
                {tab.label}
              </button>
            );
          })}
        </div>

        {action ? (
          <div
            style={{
              display: "flex",
              alignItems: "center",

              marginLeft: 2,
            }}
          >
            {action}
          </div>
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
    </section>
  );
}