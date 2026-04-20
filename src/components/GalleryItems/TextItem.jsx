import './TextItem.css';

function normalizeText(value) {
  return String(value || '').replace(/--/g, '—');
}

function renderBrandText(value) {
  const text = normalizeText(value);
  const parts = text.split(/(ColorFix)/g);

  return parts.map((part, index) => {
    if (part !== 'ColorFix') {
      return <span key={index}>{part}</span>;
    }

    return (
      <span key={index} className="text-item__brand">
        <span className="text-item__brand-color">Color</span>
        <span className="text-item__brand-fix">Fix</span>
      </span>
    );
  });
}

export default function TextItem({ item }) {
  return (
    <div className="item text-item">
      {item?.title ? <div className="text-item__title">{normalizeText(item.title)}</div> : null}
      {item?.subtitle ? <div className="text-item__subtitle">{normalizeText(item.subtitle)}</div> : null}
      <div className="text-item__body">{renderBrandText(item?.body)}</div>
    </div>
  );
}
