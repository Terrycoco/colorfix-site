// components/GalleryItems/QuoteItem.jsx
import './items.css';

export default function QuoteItem({ item }) {
  const {
    title, 
    subtitle,      // e.g., short quote
    body,        // optional longer quote or author
    color        // optional background or text color
  } = item;

  const style = {
    backgroundColor: color || '#f4f4f4',
    color: '#222',
    padding: '1.5rem',
    fontStyle: 'italic',
    textAlign: 'center',
    marginTop: '1rem'
  };

  return (
    <div className="item quote-item" style={style}>
      <div className="quote-text">{body}</div>
      {subtitle && <div className="quote-author" style={{ marginTop: '1rem', fontWeight: 'light', fontSize: '.75rem' }}>{subtitle}</div>}
    </div>
  );
}
