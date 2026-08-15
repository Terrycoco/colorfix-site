import { useMemo, useState } from "react";

export default function AdminDataGrid({
  children,
  className = "",
  ariaLabel,

  items = null,
  columns = null,
  getRowKey = (item) => item.id,

  selectedKey: controlledSelectedKey,
  onSelectionChange,
  onRowDoubleClick,

  defaultSortKey = "",
  defaultSortDirection = "asc",
}) {
  const [internalSelectedKey, setInternalSelectedKey] = useState(null);
  const [sortKey, setSortKey] = useState(defaultSortKey);
  const [sortDirection, setSortDirection] = useState(defaultSortDirection);

  const selectedKey =
    controlledSelectedKey !== undefined
      ? controlledSelectedKey
      : internalSelectedKey;

  const sortedItems = useMemo(() => {
    if (!Array.isArray(items)) return [];
    if (!sortKey) return items;

    const column = columns?.find((col) => col.key === sortKey);
    if (!column) return items;

    const getValue =
      column.sortValue ||
      column.value ||
      ((item) => item?.[column.key]);

    return [...items].sort((a, b) => {
      const av = getValue(a);
      const bv = getValue(b);

      if (av == null && bv == null) return 0;
      if (av == null) return 1;
      if (bv == null) return -1;

      if (typeof av === "number" && typeof bv === "number") {
        return sortDirection === "asc" ? av - bv : bv - av;
      }

      const result = String(av).localeCompare(
        String(bv),
        undefined,
        {
          numeric: true,
          sensitivity: "base",
        }
      );

      return sortDirection === "asc" ? result : -result;
    });
  }, [items, columns, sortKey, sortDirection]);

  function handleSort(column) {
    if (column.sortable === false) return;

    if (sortKey === column.key) {
      setSortDirection((current) =>
        current === "asc" ? "desc" : "asc"
      );
    } else {
      setSortKey(column.key);
      setSortDirection("asc");
    }
  }

  function handleSelect(item) {
    const key = getRowKey(item);

    if (controlledSelectedKey === undefined) {
      setInternalSelectedKey(key);
    }

    onSelectionChange?.(item, key);
  }

  const dataMode =
    Array.isArray(items) &&
    Array.isArray(columns);

  return (
    <div className={`admin-data-grid-wrap ${className}`.trim()}>
      <table
        className="admin-data-grid"
        aria-label={ariaLabel}
      >
        {dataMode ? (
          <>
            <thead>
              <tr>
               {columns.map((column) => (
                  <th
                    key={column.key}
                    onClick={() => handleSort(column)}
                  >
                    {column.label}
                  </th>
                ))}
              </tr>
            </thead>

            <tbody>
              {sortedItems.map((item) => {
                const key = getRowKey(item);

                return (
                  <tr
                    key={key}
                    className={
                      selectedKey === key
                        ? "is-selected"
                        : ""
                    }
                    onClick={() => handleSelect(item)}
                    onDoubleClick={() => onRowDoubleClick?.(item)}
                  >
                    {columns.map((column) => (
                      <td key={column.key}>
                        {column.render
                          ? column.render(item)
                          : column.value
                            ? column.value(item)
                            : item?.[column.key]}
                      </td>
                    ))}
                  </tr>
                );
              })}
            </tbody>
          </>
        ) : (
          children
        )}
      </table>
    </div>
  );
}