import { createPortal } from "react-dom";
import useAnchoredPopup from "../../hooks/useAnchoredPopup";
import "./dropdownnote.css";
import React, { useState, useRef, useEffect} from 'react';


/*options are expected
 {value: 'Some Value', 
 note: 'will appear on the side of the value when open'}
*/


function DropdownWithNote({ options, value, onChange, label }) {
  const [open, setOpen] = useState(false);
  const ref = useRef(null);

  const popupRef = useAnchoredPopup({ open, anchorRef: ref, onClose: () => setOpen(false) });

  const selected = options.find(opt => opt.value === value);

  
   // ⬇️ Close dropdown on outside click
  useEffect(() => {
    function handleClickOutside(event) {
      if (ref.current && !ref.current.contains(event.target) && !popupRef.current?.contains(event.target)) {
        setOpen(false);
      }
    }

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [popupRef]);

  return (
    <div className="fancy-dropdown" ref={ref}>
      {label && <label className="fancy-label">{label}</label>}
      <div className="fancy-selected" onClick={() => setOpen(!open)}>
        {selected ? selected.value : 'Select...'}
        <span className="fancy-arrow">{open ? '▲' : '▼'}</span>
      </div>

      {open && createPortal(
        <div ref={popupRef} className="fancy-options">
          {options.map(opt => (
            <div
              key={opt.value}
              className="fancy-option"
              onClick={() => {
                onChange(opt.value);
                setOpen(false);
              }}
            >
              <div className="fancy-option-label">{opt.value}</div>
              {opt.note && <div className="fancy-option-note">{opt.note}</div>}
            </div>
          ))}
        </div>, document.body
      )}
    </div>
  );
}

export default DropdownWithNote;
