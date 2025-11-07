import {useAppState} from '@context/AppStateContext';


function SwatchCardTiny({ color, className, onSelect=null }) {


  if (!color) return null; // gracefully handle empty props



  function handleClick() {
    if (onSelect) {
      onSelect(color); 
    } 
  }



  return (
    <div className={`swatch-card-tiny ${className}`} onClick={handleClick}>
      <div
        className="swatch-color"
        style={{ backgroundColor: `rgb(${color.r}, ${color.g}, ${color.b})` }}
      ></div>
    </div>
  );
}

export default SwatchCardTiny;


 