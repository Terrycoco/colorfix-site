import { useAppState } from '@/context/AppStateContext';


export default function WorkingBar() {
  const {
    working,
    selectedWorkingColor,
    setSelectedWorkingColor,
    showComplementsFilter,
    setShowComplementsFilter,
    removeFromWorking,
    clearWorking
  } = useAppState();

  const handleSelect = (color) => {
    setSelectedWorkingColor(color);
  };



  const handleRemoveFromWorking = () => {
    //if there is only one in working remove it
    console.log('got here:', working);
    if (working.length === 1) {
      clearWorking();
    } else {
      removeFromWorking(selectedWorkingColor);
    }
    console.log('working is now: ', working);
  }

  return (
  <div className="workingbar">
   

    <div className="workingbar-content">


    {working.length > 0 ? (
  <div className="working-swatch-list">
    {working.map((color) => (
      <div
        key={color.id}
        className={`working-swatch ${selectedWorkingColor?.id === color.id ? 'selected' : ''}`}
        style={{ backgroundColor: `rgb(${color.r}, ${color.g}, ${color.b})` }}
        title={color.name}
        onClick={() => handleSelect(color)}
      >
        {selectedWorkingColor?.id === color.id && (
          <span className="swatch-checkmark">✔</span>
        )}
      </div>
    ))}
  </div>
) : (
  <div className="working-placeholder">
    Select <strong>+</strong> to add working colors here
  </div>
)}


      <div className="working-toolbar">
        <button
          onClick={clearWorking}
          className="clear-working-button"
          disabled={working.length === 0}
        >
          Clear All
        </button>

        <button
          onClick={handleRemoveFromWorking}
          className="remove-swatch-button"
          disabled={!selectedWorkingColor && working.length > 1}
        >
          Remove
        </button>
      </div>
    </div>
  </div>
);
}
