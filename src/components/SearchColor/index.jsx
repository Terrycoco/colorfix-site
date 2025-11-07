import { useState, useEffect } from 'react';
import { useAppState } from '@context/AppStateContext';

export default function SearchColor({onSelectColor}) {
  const { colors, setCurrentColor } = useAppState();
  const [inputValue, setInputValue] = useState('');
  const [matchedName, setMatchedName] = useState('');

  // When input changes, find closest match
  useEffect(() => {
    if (!inputValue) {
      setMatchedName('');
      return;
    }

    const match = colors.find(c =>
      c.name.toLowerCase().startsWith(inputValue.toLowerCase())
    );

    setMatchedName(match ? match.name : '');
  }, [inputValue, colors]);

  const handleChange = (e) => {
    setInputValue(e.target.value);
  };

  const handleKeyDown = (e) => {
    if (e.key === 'Enter' && matchedName) {
      const colorObj = colors.find(c => c.name === matchedName);
      if (colorObj) {
        if (onSelectColor) {
          setInputValue(colorObj.name);
          setCurrentColor(colorObj); //why not
          onSelectColor(colorObj); //callback
          setInputValue('');
        } else {
           setCurrentColor(colorObj);
          setInputValue('');
        }
       
      }
    }
  };

  return (
    <div className="search-color-wrapper">
      <input
        type="text"
        placeholder="Search color name"
        value={inputValue}
        onChange={handleChange}
        onKeyDown={handleKeyDown}
        autoComplete="off"
      />
      {matchedName && matchedName.toLowerCase() !== inputValue.toLowerCase() && (
        <div className="autocomplete-hint">
          {matchedName}
        </div>
      )}
    </div>
  );
}
