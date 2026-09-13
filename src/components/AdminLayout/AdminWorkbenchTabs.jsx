export default function AdminWorkbenchTabs({
  tabs = [],
  activeKey,
  onChange,
  action = null,
  children,
  className = "",
}) {
  return (
    <section
      className={["admin-workbench-tabs", className].filter(Boolean).join(" ")}
    >
      <div className="admin-workbench-tabs__header">
        <div className="admin-workbench-tabs__list" role="tablist">
          {tabs.map((tab) => {
            const selected = tab.key === activeKey;

            return (
              <button
                key={tab.key}
                type="button"
                role="tab"
                aria-selected={selected}
                className={[
                  "admin-workbench-tabs__tab",
                  selected ? "is-active" : "",
                ].filter(Boolean).join(" ")}
                onClick={() => onChange?.(tab.key)}
              >
                {tab.label}
              </button>
            );
          })}
        </div>

        {action ? (
          <div className="admin-workbench-tabs__action">
            {action}
          </div>
        ) : null}
      </div>

      <div className="admin-workbench-tabs__body">
        {children}
      </div>
    </section>
  );
}
