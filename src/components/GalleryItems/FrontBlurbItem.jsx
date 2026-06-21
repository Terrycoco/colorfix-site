import colorfixLightBgUrl from "../../assets/brand/colorfix_lightbg.png";
import "./FrontBlurbItem.css";

function normalizeText(value) {
  return String(value || "").replace(/--/g, "—");
}

function renderBrandText(value) {
  const text = normalizeText(value);
  const parts = text.split(/(\bColorFix\b)/g);

  return parts.map((part, index) => {
    if (part !== "ColorFix") {
      return <span key={index}>{part}</span>;
    }

    return (
      <span key={index} className="front-blurb-item__brand">
        <img src={colorfixLightBgUrl} alt="ColorFix" className="front-blurb-item__brand-image" />
      </span>
    );
  });
}

function renderBrandTitle(value) {
  const text = normalizeText(value).trim();
  if (text.toLowerCase() !== "colorfix") {
    return text;
  }

  return (
    <img src={colorfixLightBgUrl} alt="ColorFix" className="front-blurb-item__title-brand-image" />
  );
}

export default function FrontBlurbItem({ item }) {
  return (
    <div className="item front-blurb-item">
      {item?.title ? <h1 className="front-blurb-item__title">{renderBrandTitle(item.title)}</h1> : null}
      {item?.subtitle ? <div className="front-blurb-item__subtitle">{normalizeText(item.subtitle)}</div> : null}
      <div className="front-blurb-item__body">{renderBrandText(item?.body)}</div>
    </div>
  );
}
