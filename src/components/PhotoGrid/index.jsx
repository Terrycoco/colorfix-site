/**
 * PhotoGrid
 * Props:
 *  - items: [{ asset_id, title, thumb_url, tags:[] }]
 *  - onPick: (item) => void
 *  - emptyText?: string
 */
export default function PhotoGrid({ items = [], onPick, emptyText = "No results" }) {
  if (!items.length) {
    return <div className="photo-grid-empty">{emptyText}</div>;
  }
  return (
    <div className="photo-grid">
      {items.map(item => (
        <div
          key={item.asset_id}
          className="photo-card"
          onClick={() => onPick && onPick(item)}
          role="button"
          tabIndex={0}
          onKeyDown={(e) => { if (e.key === "Enter") onPick && onPick(item); }}
        >
          <div className="photo-thumb-wrap">
            <img className="photo-thumb" src={item.thumb_url} alt="" crossOrigin="anonymous" />
          </div>
          <div className="photo-meta">
            <div className="photo-title">{item.title || item.asset_id}</div>
            <div className="photo-tags">
              {(item.tags || []).map(t => <span key={t} className="photo-tag">{t}</span>)}
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}
