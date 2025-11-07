import {useAppState} from '@context/AppStateContext';
import { useNavigate } from 'react-router-dom';


function SwatchCard({ color, className, onSelect=null }) {
  const navigate = useNavigate();
  const { setInfoCardVisible, setCurrentColorDetail, showField, working, setWorking, showMessage} = useAppState();

  if (!color) return null; // gracefully handle empty props

  function changeColor(e) {
    e.stopPropagation();
    if (onSelect) {
       onSelect(color);
    } else {
      setCurrentColor(color);
    }
    console.log('currentColor: ', color);
  }


  function handleClick() {
    if (onSelect) {
      onSelect(color);  //can override behavior on click
    } else {
      setCurrentColorDetail(color);
      navigate(`/color/${color.id}`);
    }
  }


    const addToWorking = (color) => {
      if (working.find(c => c.id === color.id)) return; // avoid duplicates

      if (working.length >= 10) {
        showMessage('You can only add up to 10 colors to your working palette.');
        return;
      }

      setWorking(prev => [...prev, color]);
    };

  return (
    <div
      className={`relative w-[150px] max-h-[200px] border border-gray-300 rounded-md overflow-hidden text-center m-2 font-sans flex flex-col shadow-md z-[300] ${className}`}
      onClick={handleClick}
    >
      <div
        className="relative w-full h-[150px]"
        style={{ backgroundColor: `rgb(${color.r}, ${color.g}, ${color.b})` }}
      >
        <div className="absolute top-1 left-1 bg-white text-black rounded-sm text-[10px] px-1 py-[2px] opacity-80 z-[400] grid grid-cols-[auto_auto] gap-x-[4px] gap-y-[1px] leading-none">
  <span className="text-left">h:</span> <span>{color.hcl_h?.toFixed(2)}</span>
  <span className="text-left">c:</span> <span>{color.hcl_c?.toFixed(2)}</span>
  <span className="text-left">l:</span> <span>{color.hcl_l?.toFixed(2)}</span>
</div>




        <span
          onClick={(e) => {
            e.stopPropagation();
            addToWorking(color);
          }}
          className="absolute top-[6px] right-[6px] w-6 h-6 bg-white border border-gray-300 rounded-full flex items-center justify-center font-bold text-gray-800 cursor-pointer opacity-0 hover:opacity-100 transition-opacity duration-200 ease-in-out z-10"
        >
          +
        </span>
      </div>

      <div className="p-2 text-[0.7rem] text-gray-800">{color.name}</div>
</div>

  );
}

export default SwatchCard;


 