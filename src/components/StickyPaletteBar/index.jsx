import './stickypalette.css';
import { useAppState } from '@context/AppStateContext';
import { useNavigate, useLocation } from "react-router-dom";
 
export default function PaletteBar() {
  const navigate = useNavigate();
  const { pathname, search } = useLocation(); 
  const {
    palette,
    removeFromPalette,
    clearPalette,
    showPalette
  } = useAppState();


  function handlePaletteClick() {
    navigate('/my-palette#palette-hero');
  }

  const handleClick = (e, color) => {
    e.stopPropagation();
    // Treat /results/* as the long gallery page (adjust if yours differs)
    const isGallery = pathname.startsWith("/results");

  

     navigate(`/color/${color.id}`);
  };

  const hasSwatches = Array.isArray(palette) && palette.length > 0;

  if (!hasSwatches || !showPalette) return null;

  return (
    <div
      className="palette-bar palette-bar--open"
      onClick={handlePaletteClick}
      role="group"
      aria-label="Sticky palette"
    >
      <div className="palette-bar-swatches">
        {palette.map((color) => (
          <div
            key={color.id}
            className="palette-bar-tile"
            onClick={(e) => { handleClick(e, color); }}
          >
            <div
              className="palette-bar-swatch"
              style={{ backgroundColor: `rgb(${color.r}, ${color.g}, ${color.b})` }}
              title={color.name}
            />
          </div>
        ))}
      </div>
    </div>
  );
}
