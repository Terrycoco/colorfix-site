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

function buildSlicePath(startHue, endHue, center = 150, inner = 95, outer = 146) {
  if (startHue === null || endHue === null) return null;
  let start = startHue;
  let end = endHue;
  if (end <= start) end += 360;
  const sweep = end - start;
  const largeArc = sweep > 180 ? 1 : 0;

  const startRad = ((start - 90) * Math.PI) / 180;
  const endRad = ((end - 90) * Math.PI) / 180;

  const sxOuter = center + outer * Math.cos(startRad);
  const syOuter = center + outer * Math.sin(startRad);
  const exOuter = center + outer * Math.cos(endRad);
  const eyOuter = center + outer * Math.sin(endRad);

  const sxInner = center + inner * Math.cos(endRad);
  const syInner = center + inner * Math.sin(endRad);
  const exInner = center + inner * Math.cos(startRad);
  const eyInner = center + inner * Math.sin(startRad);

  const path = [
    `M ${sxOuter.toFixed(2)} ${syOuter.toFixed(2)}`,
    `A ${outer} ${outer} 0 ${largeArc} 1 ${exOuter.toFixed(2)} ${eyOuter.toFixed(2)}`,
    `L ${sxInner.toFixed(2)} ${syInner.toFixed(2)}`,
    `A ${inner} ${inner} 0 ${largeArc} 0 ${exInner.toFixed(2)} ${eyInner.toFixed(2)}`,
    "Z",
  ].join(" ");

  return path;
}

const WheelItem = ({ item = {} }) => {
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
    item.light_cat_name ||
    "Hue Slice";

  const normalizedName = (value) => String(value || "").trim().toLowerCase();
  const categoryNameCandidates = [
    metadata.category,
    metadata.hue_category,
    metadata.hue_cats,
    item.hue_cats,
    item.category_name,
    item.category,
    label,
  ].map(normalizedName);

  const matchedCategory = useMemo(() => {
    if (!Array.isArray(categories)) return null;
    return categories.find((cat) => {
      if (!cat?.name || cat.type !== "hue") return false;
      const n = normalizedName(cat.name);
      return categoryNameCandidates.includes(n);
    }) || null;
  }, [categories, categoryNameCandidates.join("|")]);

  const startHue = pickHue(
    matchedCategory?.hue_min,
    metadata.hue_min,
    item.hue_min,
    metadata.hue_start,
    item.hue_start,
    metadata.hue_from,
    item.hue_from,
    metadata.hue_a,
    item.hue_a,
    metadata.min_hue,
    item.min_hue
  );
  const endHue = pickHue(
    matchedCategory?.hue_max,
    metadata.hue_max,
    item.hue_max,
    metadata.hue_end,
    item.hue_end,
    metadata.hue_to,
    item.hue_to,
    metadata.hue_b,
    item.hue_b,
    metadata.max_hue,
    item.max_hue
  );

  const slicePath = startHue !== null && endHue !== null ? buildSlicePath(startHue, endHue) : null;
  const displayStart = startHue !== null ? Math.round(startHue) : null;
  const displayEnd = endHue !== null ? Math.round(endHue) : null;

  return (
    <div className="wheel-item">
      {label && <div className="wheel-item__label">{label}</div>}
      <div className="wheel-item__wheel">
        <ColorWheel300 currentColor={null}>
          {slicePath && (
            <path
              className="wheel-item__slice"
              d={slicePath}
              fill="rgba(0,0,0,0.07)"
              stroke="rgba(0,0,0,0.25)"
              strokeWidth="1.5"
            />
          )}
          {startHue !== null && (
            <ColorWheelIndicator
              hue={startHue}
              center={150}
              radius={150}
              strokeColor="#111"
              dashed
            />
          )}
          {endHue !== null && (
            <ColorWheelIndicator
              hue={endHue}
              center={150}
              radius={150}
              strokeColor="#111"
              dashed
            />
          )}
        </ColorWheel300>
      </div>
      {displayStart !== null && displayEnd !== null && (
        <div className="wheel-item__range">
          Hue {displayStart}&deg; – {displayEnd}&deg;
        </div>
      )}
    </div>
  );
};

export default WheelItem;
