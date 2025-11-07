// PaletteSwatch.jsx
import { useAppState } from '@context/AppStateContext';
import { isClickShielded, armClickShield } from '@helpers/utils';
import './swatches.css';
import { useNavigate } from 'react-router-dom';
import { PaletteToggleIcon } from '@components/Icons/PaletteIcons';
import DesignerIcon from '@components/Icons/DesignerIcon';
import FanDeckIcon from '@components/Icons/FanDeckIcon';

// small helper
function normalizeHex(hexLike) {
  if (!hexLike) return null;
  const s = String(hexLike).trim();
  const h = s.startsWith('#') ? s : `#${s}`;
  return /^#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})$/.test(h) ? h : null;
}
function hexToRgb(hex) {
  const h = normalizeHex(hex);
  if (!h) return null;
  let r, g, b;
  if (h.length === 4) {
    r = parseInt(h[1] + h[1], 16);
    g = parseInt(h[2] + h[2], 16);
    b = parseInt(h[3] + h[3], 16);
  } else {
    r = parseInt(h.slice(1, 3), 16);
    g = parseInt(h.slice(3, 5), 16);
    b = parseInt(h.slice(5, 7), 16);
  }
  return { r, g, b };
}
function pickTextColor({ r, g, b }, fallbackLightness) {
  if (typeof fallbackLightness === 'number') {
    return fallbackLightness > 70 ? '#111' : '#fff';
  }
  // relative luminance (simple)
  const L = 0.2126 * (r / 255) + 0.7152 * (g / 255) + 0.0722 * (b / 255);
  return L > 0.62 ? '#111' : '#fff';
}

export default function PaletteSwatch({ color, widthPercent = 20, onSelectColor }) {
  const navigate = useNavigate();
  const { addToPalette, removeFromPalette, palette } = useAppState();
  const inPalette = palette?.some((c) => c.id === color.id);

  // Determine fill color
  const hex = normalizeHex(color?.hex) || normalizeHex(color?.hex6);
  const rgb =
    hexToRgb(hex) ||
    (Number.isFinite(color?.r) && Number.isFinite(color?.g) && Number.isFinite(color?.b)
      ? { r: Number(color.r), g: Number(color.g), b: Number(color.b) }
      : { r: 200, g: 200, b: 200 });

  const fillCss = hex ? hex : `rgb(${rgb.r}, ${rgb.g}, ${rgb.b})`;
  const text = pickTextColor(rgb, typeof color?.hcl_l === 'number' ? color.hcl_l : undefined);

  const go = () => {
    if (isClickShielded()) return;
    history.replaceState(null, '', `#swatch-${color.id}`); // mark where to return
    navigate(`/color/${color.id}`); // go to detail
  };

  const isStain = Number(color?.is_stain) === 1;
  const nameHasStain = typeof color?.name === 'string' && /\bstain\b/i.test(color.name);
  const displayName = isStain && !nameHasStain ? `${color.name} (Stain)` : color.name;

  return (
    <div
      id={`swatch-${color.id}`}
      tabIndex={-1}
      className="pals-swatch"
      style={{ '--pals-width': `${widthPercent}%` }}
      onClick={go}
    >
      <div
        className="pals-fill"
        style={{
          backgroundColor: fillCss,
          color: text,
          '--stain-rgb': `${rgb.r}, ${rgb.g}, ${rgb.b}`,
        }}
        data-is-stain={isStain ? 1 : 0}
        data-stain-tone={typeof color?.hcl_l === 'number' && color.hcl_l <= 55 ? 'dark' : 'light'}
      >
        <button
          type="button"
          className="pals-btn palette"
          aria-label={inPalette ? 'Remove from palette' : 'Add to palette'}
          onClick={(e) => {
            e.preventDefault();
            e.stopPropagation();
            armClickShield(350);
            inPalette ? removeFromPalette(color.id) : addToPalette(color);
            onSelectColor?.(color, e);
          }}
          title={color.hue_cats}
        >
          <PaletteToggleIcon active={inPalette} color={text} className="pals-icon" />
        </button>

        {color?.chip_num?.length > 0 && (
          <span
            className="pals-chip-num"
            style={{ color: text }}
            aria-label={`Chip #${color.chip_num}`}
            title="chip #"
          >
            {color.chip_num}
          </span>
        )}

        <div className="pals-label" title={color.hue_cats}>
          <div className="pals-name">{displayName}</div>
          <div className="pals-meta">
            {color.brand} • H:{typeof color.hcl_h === 'number' ? Math.round(color.hcl_h) : '–'} • C:
            {typeof color.hcl_c === 'number' ? Math.round(color.hcl_c) : '–'} • L:
            {typeof color.hcl_l === 'number' ? Math.round(color.hcl_l) : '–'}
          </div>
        </div>
      </div>
    </div>
  );
}
