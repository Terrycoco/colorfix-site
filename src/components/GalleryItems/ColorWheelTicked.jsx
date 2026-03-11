import ColorWheel300 from "@components/ColorWheel/ColorWheel300";
import "./items.css";

function parseJsonMaybe(text) {
  if (!text || typeof text !== "string") return null;
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

const ColorWheelTicked = ({ item = {} }) => {
  const bodyMeta = parseJsonMaybe(item.body);
  const descMeta = parseJsonMaybe(item.description);
  const metadata = {
    ...(typeof bodyMeta === "object" ? bodyMeta : {}),
    ...(typeof descMeta === "object" ? descMeta : {}),
  };

  const label =
    item.display ||
    item.title ||
    item.name ||
    metadata.label ||
    "Hue Wheel (Degrees)";

  return (
    <div className="wheel-item color-wheel-ticked">
      {label && <div className="wheel-item__label">{label}</div>}
      <div className="wheel-item__wheel">
        <ColorWheel300 currentColor={null} base="labels-degrees" />
      </div>
      <div className="wheel-item__range">0° at top, clockwise.</div>
    </div>
  );
};

export default ColorWheelTicked;
