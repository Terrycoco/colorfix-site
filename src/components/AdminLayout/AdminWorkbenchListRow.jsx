export default function AdminWorkbenchListRow({
  selected = false,
  checked = false,
  checkable = false,
  onSelect,
  onCheck,
  onDoubleClick,
  children,
  wrap = false,
  className = "",
  style = undefined,
}) {
  const classes = [
    "admin-workbench-list-row",
    selected ? "is-selected" : "",
    wrap ? "is-wrap" : "",
    className,
  ].filter(Boolean).join(" ");

  return (
    <div
      className={classes}
      onClick={onSelect}
      onDoubleClick={onDoubleClick}
      style={style}
    >
      {checkable ? (
        <input
          type="checkbox"
          checked={checked}
          onClick={(event) => event.stopPropagation()}
          onChange={(event) => onCheck?.(event.target.checked)}
        />
      ) : null}

      <div className="admin-workbench-list-row__content">
        {children}
      </div>
    </div>
  );
}
