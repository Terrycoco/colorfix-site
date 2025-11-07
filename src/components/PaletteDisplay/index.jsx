import PaletteSwatch from '@components/Swatches/PaletteSwatch';
import './palettedisplay.css';

export default function PaletteDisplay({ colors }) {
  if (!colors || colors.length === 0) {
    return null; // or return <div>No palettes found.</div> if you want feedback
  }
  return (
    <div className="palette-wrapper">
      {colors.map((color, i) => (
        <PaletteSwatch key={i} color={color} />
        
      ))}
    </div>
  );
}
