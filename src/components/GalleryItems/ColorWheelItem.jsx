import { useMemo } from "react";
import ColorWheel300 from "@components/ColorWheel/ColorWheel300";
import ColorWheelIndicator from "@components/ColorWheel/ColorWheelIndicator";
import { useAppState } from "@context/AppStateContext";
import "./items.css";

function parseJsonMaybe(text) {
  if (!text || typeof text !== "string") return null;
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

function normalizeHue(value) {
  if (value === null || value === undefined) return null;
  const num = Number(value);
  if (!Number.isFinite(num)) return null;
  const mod = ((num % 360) + 360) % 360;
  return mod === 360 ? 0 : mod;
}

function pickHue(...candidates) {
  for (const candidate of candidates) {
    const parsed = normalizeHue(candidate);
    if (parsed !== null) return parsed;
  }
  return null;
}

function labelPos(hue, radius = 176, center = 150) {
  const angle = ((hue - 90) * Math.PI) / 180;
  return {
    x: center + radius * Math.cos(angle),
    y: center + radius * Math.sin(angle),
  };
}

const ColorWheelItem = ({ item = {} }) => {
  const { categories = [] } = useAppState();
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
    item.hue_cats ||
    metadata.family ||
    metadata.hue_family ||
    metadata.hue_category ||
    "Hue Family";

  const normalizedName = (value) => String(value || "").trim().toLowerCase();
  const categoryNameCandidates = [
    metadata.family,
    metadata.color_family,
    metadata.hue_family,
    metadata.hue_category,
    metadata.hue_cats,
    item.hue_cats,
    item.family,
    item.category_name,
    item.category,
    label,
  ].map(normalizedName);

  const matchedCategory = useMemo(() => {
    if (!Array.isArray(categories)) return null;
    return (
      categories.find((cat) => {
        if (!cat?.name || cat.type !== "hue") return false;
        const n = normalizedName(cat.name);
        return categoryNameCandidates.includes(n);
      }) || null
    );
  }, [categories, categoryNameCandidates.join("|")]);

  const startHue = pickHue(matchedCategory?.hue_min, metadata.hue_min, item.hue_min);
  const endHue = pickHue(matchedCategory?.hue_max, metadata.hue_max, item.hue_max);

  const hasRange = startHue !== null && endHue !== null;
  const displayStart = startHue !== null ? Math.round(startHue) : null;
  const displayEnd = endHue !== null ? Math.round(endHue) : null;

  return (
    <div className="wheel-item color-wheel-item">
      {null}
      <div className="wheel-item__wheel">
        <ColorWheel300 currentColor={null}>
          {null}
          {startHue !== null && (
            <ColorWheelIndicator
              hue={startHue}
              center={150}
              radius={150}
              endRadius={170}
              strokeColor="#111"
              dashed
            />
          )}
          {endHue !== null && (
            <ColorWheelIndicator
              hue={endHue}
              center={150}
              radius={150}
              endRadius={170}
              strokeColor="#111"
              dashed
            />
          )}
          {displayStart !== null && (
            <text
              className="wheel-item__degree"
              x={labelPos(startHue).x}
              y={labelPos(startHue).y}
              textAnchor="middle"
              dominantBaseline="middle"
            >
              {`${displayStart}°`}
            </text>
          )}
          {displayEnd !== null && (
            <text
              className="wheel-item__degree"
              x={labelPos(endHue).x}
              y={labelPos(endHue).y}
              textAnchor="middle"
              dominantBaseline="middle"
            >
              {`${displayEnd}°`}
            </text>
          )}
        </ColorWheel300>
      </div>
      {!hasRange && (
        <div className="wheel-item__range">No hue family match found.</div>
      )}
    </div>
  );
};

export default ColorWheelItem;
