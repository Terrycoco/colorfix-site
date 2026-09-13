import AdminWorkbenchDrawer from "./AdminWorkbenchDrawer.jsx";

function cssSize(value, fallback) {
  if (value === null || value === undefined || value === "") return fallback;
  return typeof value === "number" ? `${value}px` : String(value);
}

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
  className = "",
}) {
  const classes = [
    "admin-workbench",
    drawerOpen ? "is-drawer-open" : "",
    className,
  ].filter(Boolean).join(" ");

  return (
    <div
      className={classes}
      style={{
        "--admin-workbench-upper-height": cssSize(upperHeight, "48%"),
        "--admin-workbench-upper-left-width": cssSize(upperLeftWidth, "280px"),
        "--admin-workbench-drawer-width": cssSize(drawerWidth, "400px"),
      }}
    >
      {header ? (
        <div className="admin-workbench__header">
          {header}
        </div>
      ) : null}

      <div className="admin-workbench__body">
        <div className="admin-workbench__workspace">
          <div className="admin-workbench__upper">
            <div className="admin-workbench__upper-left">
              {upperLeft}
            </div>

            <div className="admin-workbench__upper-right">
              {upperRight}
            </div>
          </div>

          <div className="admin-workbench__lower">
            {lower}
          </div>
        </div>

        {drawerOpen ? (
          <div className="admin-workbench__drawer-slot">
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
