import AdminWorkbenchDrawer from "./AdminWorkbenchDrawer.jsx";

export default function AdminWorkbench({
  header = null,

  upperLeft,
  upperRight,
  lower,

  drawerOpen = false,
  drawerWidth = 400,
  drawerTitle = "",
  drawerContent = null,
  onCloseDrawer,

  upperLeftWidth = 280,
  upperHeight = "48%",
}) {
  return (
    <div
      style={{
        height: "100%",
        minHeight: 0,
        minWidth: 0,

        display: "flex",
        flexDirection: "column",

        background:
          "var(--admin-layout-bg, #fff)",
      }}
    >
      {header ? (
        <div
          style={{
            flexShrink: 0,
          }}
        >
          {header}
        </div>
      ) : null}

      <div
        style={{
          flex: 1,
          minHeight: 0,
          minWidth: 0,

          padding: 12,

          boxSizing: "border-box",

          position: "relative",
          overflow: "hidden",
        }}
      >
        {/* MAIN WORKSPACE */}
        <div
          style={{
            position: "absolute",
            inset: 12,

            minWidth: 0,
            minHeight: 0,

            display: "grid",
            gridTemplateRows:
              `${upperHeight} minmax(0, 1fr)`,

            overflow: "hidden",

            border:
              "1px solid var(--admin-layout-border, #d8dde3)",

            background:
              "var(--admin-layout-bg, #fff)",
          }}
        >
          {/* UPPER AREA SHRINKS FOR DRAWER */}
          <div
            style={{
              minWidth: 0,
              minHeight: 0,

              width: drawerOpen
                ? `calc(100% - ${drawerWidth}px)`
                : "100%",

              display: "grid",

              gridTemplateColumns:
                `${upperLeftWidth}px minmax(0, 1fr)`,

              overflow: "hidden",
            }}
          >
            <div
              style={{
                minWidth: 0,
                minHeight: 0,

                borderRight:
                  "1px solid var(--admin-layout-border, #d8dde3)",
              }}
            >
              {upperLeft}
            </div>

            <div
              style={{
                minWidth: 0,
                minHeight: 0,
              }}
            >
              {upperRight}
            </div>
          </div>

          {/* LOWER REMAINS FULL WIDTH */}
          <div
            style={{
              minWidth: 0,
              minHeight: 0,

              overflow: "hidden",
            }}
          >
            {lower}
          </div>
        </div>

        {/* DRAWER OVERLAYS LOWER AREA */}
        {drawerOpen ? (
          <div
            style={{
              position: "absolute",

              top: 12,
              right: 12,
              bottom: 12,

              width: drawerWidth,

              display: "flex",

              zIndex: 5,
            }}
          >
            <AdminWorkbenchDrawer
              open
              width={drawerWidth}
              title={drawerTitle}
              onClose={onCloseDrawer}
            >
              {drawerContent}
            </AdminWorkbenchDrawer>
          </div>
        ) : null}
      </div>
    </div>
  );
}