import './swatches.css';

// A computed color reference, independent of any named paint/database row.
export default function ReferenceSwatch({ color }) {
  const hex = String(color?.hex6 || '').toUpperCase();
  if (!/^[0-9A-F]{6}$/.test(hex)) return null;
  const format = value => Number.isFinite(value) ? value.toFixed(1) : '–';
  const hue = format(color.hcl_h);
  const chroma = format(color.hcl_c);
  const lightness = format(color.hcl_l);
  return (
    <div
      className="pals-swatch pals-reference-swatch"
      role="img"
      aria-label={`Searched color #${hex}. Hue ${hue}, chroma ${chroma}, lightness ${lightness}.`}
    >
      <div className="pals-fill" style={{ backgroundColor: `#${hex}`, color: color.hcl_l > 70 ? '#111' : '#fff' }}>
        <div className="pals-label">
          <div className="pals-name">#{hex}</div>
          <div className="pals-meta">H:{hue} • C:{chroma} • L:{lightness}</div>
        </div>
      </div>
    </div>
  );
}
