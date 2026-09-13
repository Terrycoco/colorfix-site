export default function AdminSectionHeader({
  title,
  meta = null,
  actions = null,
  className = "",
}) {
  return (
    <div className={["admin-section-header", className].filter(Boolean).join(" ")}>
      <div className="admin-section-header__main">
        {title ? <h2 className="admin-section-header__title">{title}</h2> : null}
        {meta ? <div className="admin-section-header__meta">{meta}</div> : null}
      </div>

      {actions ? (
        <div className="admin-section-header__actions">
          {actions}
        </div>
      ) : null}
    </div>
  );
}
