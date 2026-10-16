import { Children, isValidElement } from "react";
import AdminObjectList from "./AdminObjectList";
import AdminObjectListItem from "./AdminObjectListItem";
import useAdminMobile from "./useAdminMobile";
import MobileListPane from "./mobile/MobileListPane";



export default function AdminListPane({
  title,
  actions = null,
  searchValue,
  onSearchChange,
  searchPlaceholder = "Search...",
  sortValue,
  sortOptions = [],
  onSortChange,
  toolbar = null,
  children,
  className = "",
}) {
  const isMobile = useAdminMobile();
  const hasSearch = typeof onSearchChange === "function";
  const hasSort =
    typeof onSortChange === "function" &&
    Array.isArray(sortOptions) &&
    sortOptions.length > 0;

  // Present standard selectable object lists with the mobile picker.
  // Non-standard/custom panes stay on their existing implementation.
  const nodes = Children.toArray(children);
  const objectList = nodes.find((node) => isValidElement(node) && node.type === AdminObjectList);
  const navItems = objectList ? Children.toArray(objectList.props.children) : [];
  const canUsePicker = !toolbar && (objectList || hasSearch)
    && navItems.every((item) => isValidElement(item)
      && item.type === AdminObjectListItem
      && !item.props.status
      && !item.props.onStatusClick);

  if (isMobile && canUsePicker) {
    return (
      <MobileListPane
        title={title}
        actions={actions}
        searchPlaceholder={searchPlaceholder}
        className={className}
      >
        {children}
      </MobileListPane>
    );
  }

  return (
    <section className={`admin-list-pane ${className}`.trim()}>
      <header className="admin-list-pane__header">
        <h2 className="admin-list-pane__title">{title}</h2>

        {actions ? (
          <div className="admin-list-pane__actions">{actions}</div>
        ) : null}
      </header>

      {hasSearch || hasSort ? (
        <div className="admin-list-pane__toolbar">
          {hasSearch ? (
            <input
              type="search"
              className="admin-field__control"
              value={searchValue ?? ""}
              placeholder={searchPlaceholder}
              aria-label={searchPlaceholder}
              onChange={(event) => onSearchChange(event.target.value)}
            />
          ) : null}

          {hasSort ? (
            <div
              className="admin-list-pane__sort"
              role="group"
              aria-label="Sort"
            >
              {sortOptions.map((option) => {
                const active =
                  String(sortValue ?? "") === String(option.value);

                return (
                  <button
                    key={option.value}
                    type="button"
                    className={[
                      "admin-list-pane__sort-button",
                      active ? "is-active" : "",
                    ].filter(Boolean).join(" ")}
                    aria-pressed={active}
                    onClick={() => onSortChange(option.value)}
                  >
                    {option.label}
                  </button>
                );
              })}
            </div>
          ) : null}
        </div>
      ) : null}

      {toolbar ? (
        <div className="admin-list-pane__toolbar">{toolbar}</div>
      ) : null}

      <div className="admin-list-pane__body">{children}</div>
    </section>
  );
}
