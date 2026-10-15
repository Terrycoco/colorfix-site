// components/GalleryItems/QuoteItem.jsx
import './items.css';

export default function QuoteItem({ item }) {
  const { subtitle, body, color } = item;

  return (
    <div
      className="item quote-item"
      style={{ backgroundColor: color || '#f4f4f4' }}
    >
      <div className="quote-text">{body}</div>
      {subtitle && <div className="quote-author">{subtitle}</div>}
    </div>
  );
}
