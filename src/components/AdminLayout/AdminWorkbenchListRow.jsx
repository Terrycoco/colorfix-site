export default function AdminWorkbenchListRow({
  selected = false,
  checked = false,
  checkable = false,

  onSelect,
  onCheck,
  onDoubleClick,

  children,

  wrap = false,
  style = {},
}) {
  return (
    <div
      onClick={onSelect}
      onDoubleClick={onDoubleClick}
      style={{
        minHeight: 30,
        boxSizing: "border-box",

        display: "flex",
        alignItems: wrap ? "flex-start" : "center",
        gap: 7,

        padding: wrap
          ? "6px 8px"
          : "0 8px",

        borderBottom:
          "1px solid var(--admin-layout-border-soft, #eceff1)",

        background: selected
          ? "var(--highlight-cyan)"
          : "transparent",

        cursor: "default",
        userSelect: "none",

        fontSize: 13,
        lineHeight: wrap ? 1.35 : 1.2,

        ...style,
      }}
    >
      {checkable ? (
        <input
          type="checkbox"
          checked={checked}
          onClick={(event) =>
            event.stopPropagation()
          }
          onChange={(event) =>
            onCheck?.(
              event.target.checked
            )
          }
        />
      ) : null}

      <div
        style={{
          flex: 1,
          minWidth: 0,
          whiteSpace: wrap
            ? "normal"
            : "nowrap",
          overflow: wrap
            ? "visible"
            : "hidden",
          textOverflow: wrap
            ? "clip"
            : "ellipsis",
        }}
      >
        {children}
      </div>
    </div>
  );
}