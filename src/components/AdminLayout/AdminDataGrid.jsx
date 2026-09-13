import {
  useEffect,
  useMemo,
  useState,
} from "react";

import AdminWorkbenchDrawer
  from "./AdminWorkbenchDrawer.jsx";


function cssSize(value) {
  if (
    value === null ||
    value === undefined ||
    value === ""
  ) {
    return undefined;
  }

  return typeof value === "number"
    ? `${value}px`
    : String(value);
}


function resolveDrawerValue(
  value,
  item,
  fallback = null
) {
  if (typeof value === "function") {
    return value(item);
  }

  return value ?? fallback;
}


export default function AdminDataGrid({
  children,
  className = "",
  tableClassName = "",
  ariaLabel,

  items = null,
  columns = null,
  getRowKey = (item) => item.id,

  selectedKey: controlledSelectedKey,
  onSelectionChange,
  onRowDoubleClick,

  /*
   * Standard inspection drawer.
   *
   * When supplied:
   *   - double-click opens the drawer for that row
   *   - while open, single-clicking another row updates the drawer
   *   - double-clicking again closes the drawer
   *
   * Shape:
   * drawer={{
   *   title: (item) => "...",
   *   render: ({ item, close }) => <... />,
   *   footer: ({ item, close }) => <... />,
   *   width: 380,
   *   padded: true,
   *   portal: true,
   *   className: "",
   * }}
   */
  drawer = null,

  defaultSortKey = "",
  defaultSortDirection = "asc",

  bordered = false,
  minWidth,
  verticalAlign = "middle",
}) {
  const [
    internalSelectedKey,
    setInternalSelectedKey,
  ] = useState(
    null
  );

  const [
    sortKey,
    setSortKey,
  ] = useState(
    defaultSortKey
  );

  const [
    sortDirection,
    setSortDirection,
  ] = useState(
    defaultSortDirection
  );

  const [
    drawerOpen,
    setDrawerOpen,
  ] = useState(
    false
  );

  const [
    drawerItemKey,
    setDrawerItemKey,
  ] = useState(
    null
  );


  const selectedKey =
    controlledSelectedKey !== undefined
      ? controlledSelectedKey
      : internalSelectedKey;


  const sortedItems =
    useMemo(
      () => {
        if (
          !Array.isArray(
            items
          )
        ) {
          return [];
        }

        if (!sortKey) {
          return items;
        }

        const column =
          columns?.find(
            (col) =>
              col.key ===
              sortKey
          );

        if (!column) {
          return items;
        }

        const getValue =
          column.sortValue ||
          column.value ||
          (
            (item) =>
              item?.[
                column.key
              ]
          );

        return [
          ...items,
        ].sort(
          (a, b) => {
            const av =
              getValue(
                a
              );

            const bv =
              getValue(
                b
              );

            if (
              av == null &&
              bv == null
            ) {
              return 0;
            }

            if (
              av == null
            ) {
              return 1;
            }

            if (
              bv == null
            ) {
              return -1;
            }

            if (
              typeof av ===
                "number" &&
              typeof bv ===
                "number"
            ) {
              return sortDirection ===
                "asc"
                ? av - bv
                : bv - av;
            }

            const result =
              String(
                av
              ).localeCompare(
                String(
                  bv
                ),
                undefined,
                {
                  numeric:
                    true,
                  sensitivity:
                    "base",
                }
              );

            return sortDirection ===
              "asc"
              ? result
              : -result;
          }
        );
      },
      [
        items,
        columns,
        sortKey,
        sortDirection,
      ]
    );


  const dataMode =
    Array.isArray(
      items
    ) &&
    Array.isArray(
      columns
    );


  const hasDrawer =
    Boolean(
      dataMode &&
      drawer &&
      typeof drawer.render ===
        "function"
    );


  const drawerItem =
    useMemo(
      () => {
        if (
          !hasDrawer ||
          drawerItemKey ===
            null ||
          drawerItemKey ===
            undefined
        ) {
          return null;
        }

        return (
          sortedItems.find(
            (item) =>
              getRowKey(
                item
              ) ===
              drawerItemKey
          ) ||
          null
        );
      },
      [
        hasDrawer,
        drawerItemKey,
        sortedItems,
        getRowKey,
      ]
    );


  /*
   * If the active row is controlled by the parent, keep an open
   * drawer following that active row too.
   */
  useEffect(
    () => {
      if (
        !drawerOpen ||
        !hasDrawer ||
        selectedKey ===
          null ||
        selectedKey ===
          undefined
      ) {
        return;
      }

      setDrawerItemKey(
        selectedKey
      );
    },
    [
      drawerOpen,
      hasDrawer,
      selectedKey,
    ]
  );


  /*
   * If the row disappears after a reload/filter, close the drawer.
   */
  useEffect(
    () => {
      if (
        drawerOpen &&
        hasDrawer &&
        !drawerItem
      ) {
        setDrawerOpen(
          false
        );

        setDrawerItemKey(
          null
        );
      }
    },
    [
      drawerOpen,
      hasDrawer,
      drawerItem,
    ]
  );


  function handleSort(
    column
  ) {
    if (
      column.sortable ===
      false
    ) {
      return;
    }

    if (
      sortKey ===
      column.key
    ) {
      setSortDirection(
        (current) =>
          current ===
            "asc"
            ? "desc"
            : "asc"
      );
    } else {
      setSortKey(
        column.key
      );

      setSortDirection(
        "asc"
      );
    }
  }


  function handleSelect(
    item
  ) {
    const key =
      getRowKey(
        item
      );

    if (
      controlledSelectedKey ===
      undefined
    ) {
      setInternalSelectedKey(
        key
      );
    }

    /*
     * Once the inspection drawer is open, a normal row click navigates
     * the drawer to that row without closing/reopening it.
     */
    if (
      drawerOpen &&
      hasDrawer
    ) {
      setDrawerItemKey(
        key
      );
    }

    onSelectionChange?.(
      item,
      key
    );
  }


  function closeDrawer() {
    setDrawerOpen(
      false
    );
  }


  function handleDoubleClick(
    item
  ) {
    const key =
      getRowKey(
        item
      );

    /*
     * Built-in drawer behavior is only activated when a drawer renderer
     * has been supplied. Existing grids that use onRowDoubleClick
     * externally keep their old behavior untouched.
     */
    if (hasDrawer) {
      if (
        controlledSelectedKey ===
        undefined
      ) {
        setInternalSelectedKey(
          key
        );
      }

      onSelectionChange?.(
        item,
        key
      );

      if (drawerOpen) {
        setDrawerOpen(
          false
        );

        return;
      }

      setDrawerItemKey(
        key
      );

      setDrawerOpen(
        true
      );

      return;
    }

    onRowDoubleClick?.(
      item
    );
  }


  const wrapClasses = [
    "admin-data-grid-wrap",
    bordered
      ? "admin-data-grid-wrap--bordered"
      : "",
    className,
  ]
    .filter(
      Boolean
    )
    .join(
      " "
    );


  const tableClasses = [
    "admin-data-grid",
    dataMode
      ? "is-interactive"
      : "",
    verticalAlign ===
      "top"
      ? "admin-data-grid--top"
      : "",
    tableClassName,
  ]
    .filter(
      Boolean
    )
    .join(
      " "
    );


  const resolvedMinWidth =
    cssSize(
      minWidth
    );


  const drawerTitle =
    drawerItem
      ? resolveDrawerValue(
          drawer?.title,
          drawerItem,
          "Details"
        )
      : "Details";



  return (
    <>
      <div
        className={
          wrapClasses
        }
      >
        <table
          className={
            tableClasses
          }
          aria-label={
            ariaLabel
          }
          style={
            resolvedMinWidth
              ? {
                  "--admin-data-grid-min-width":
                    resolvedMinWidth,
                }
              : undefined
          }
        >
          {dataMode ? (
            <>
              <thead>
                <tr>
                  {columns.map(
                    (column) => (
                      <th
                        key={
                          column.key
                        }
                        onClick={() =>
                          handleSort(
                            column
                          )
                        }
                      >
                        {
                          column.label
                        }
                      </th>
                    )
                  )}
                </tr>
              </thead>

              <tbody>
                {sortedItems.map(
                  (item) => {
                    const key =
                      getRowKey(
                        item
                      );

                    return (
                      <tr
                        key={
                          key
                        }
                        className={
                          selectedKey ===
                          key
                            ? "is-selected"
                            : ""
                        }
                        onClick={() =>
                          handleSelect(
                            item
                          )
                        }
                        onDoubleClick={() =>
                          handleDoubleClick(
                            item
                          )
                        }
                      >
                        {columns.map(
                          (
                            column
                          ) => (
                            <td
                              key={
                                column.key
                              }
                            >
                              {column.render
                                ? column.render(
                                    item
                                  )
                                : column.value
                                  ? column.value(
                                      item
                                    )
                                  : item?.[
                                      column.key
                                    ]}
                            </td>
                          )
                        )}
                      </tr>
                    );
                  }
                )}
              </tbody>
            </>
          ) : (
            children
          )}
        </table>
      </div>


      {hasDrawer &&
      drawerItem ? (
        <AdminWorkbenchDrawer
          open={
            drawerOpen
          }
          width={
            drawer?.width ??
            380
          }
          title={
            drawerTitle
          }
          onClose={
            closeDrawer
          }
          footer={
            typeof drawer
              ?.footer ===
              "function"
              ? drawer.footer({
                  item:
                    drawerItem,
                  close:
                    closeDrawer,
                })
              : drawer
                  ?.footer ??
                null
          }
          portal={
            drawer
              ?.portal !==
            false
          }
          padded={
            drawer
              ?.padded !==
            false
          }
          className={
            drawer
              ?.className ||
            ""
          }
        >
          {drawer.render({
            item:
              drawerItem,
            close:
              closeDrawer,
          })}
        </AdminWorkbenchDrawer>
      ) : null}
    </>
  );
}
