import {useAppState} from '@context/AppStateContext';


function SwatchCardMini({ color, className, onSelect=null }) {


  if (!color) return null; // gracefully handle empty props



  function handleClick() {
    if (onSelect) {
      onSelect(color); 
    } 
  }



  return (
    <div className={`swatch-card-mini ${className}`} onClick={handleClick}>
      <div
        className="swatch-color "
        style={{ backgroundColor: `rgb(${color.r}, ${color.g}, ${color.b})` }}
      ></div>
      <div className="swatch-name-mini" >{color.name}</div>
    </div>
  );
}

export default SwatchCardMini;


 