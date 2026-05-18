import colorfixLightBgUrl from "../../assets/brand/colorfix_lightbg.png";
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
        <img src={colorfixLightBgUrl} alt="ColorFix" className="text-item__brand-image" />
      </span>
    );
  });
}

function renderBrandTitle(value) {
  const text = normalizeText(value).trim();
  if (text.toLowerCase() !== 'colorfix') {
    return text;
  }

  return (
    <img src={colorfixLightBgUrl} alt="ColorFix" className="text-item__title-brand-image" />
  );
}

export default function TextItem({ item }) {
  return (
    <div className="item text-item">
      {item?.title ? <div className="text-item__title">{renderBrandTitle(item.title)}</div> : null}
      {item?.subtitle ? <div className="text-item__subtitle">{normalizeText(item.subtitle)}</div> : null}
      <div className="text-item__body">{renderBrandText(item?.body)}</div>
    </div>
  );
}
