import React, { useState,  useRef } from 'react';
import { useAppState } from '@context/AppStateContext';

export default function ColorSearchBox() {
  const [query, setQuery] = useState('');
  const [suggestion, setSuggestion] = useState('');
  const { colors, setCurrentColor } = useAppState();
  const inputRef = useRef();

  const handleChange = (e) => {
    const value = e.target.value;
    setQuery(value);

    if (value.trim() === '') {
      setSuggestion('');
      return;
    }

    const match = colors.find(c =>
      c.name.toLowerCase().startsWith(value.toLowerCase())
    );

    if (match) {
      setSuggestion(match.name);
    } else {
      setSuggestion('');
    }
  };

  const handleFocus = () => {
      setQuery('');         // ✅ clear input
      setSuggestion('');    // ✅ clear suggestion
     
  };

  const handleKeyDown = (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();

      const match = colors.find(
        color => color.name.toLowerCase() === suggestion.toLowerCase()
      );

      if (match) {
        setQuery(suggestion);
        setSuggestion('');
        setCurrentColor(match);
        inputRef.current.blur(); // optional: blur on selection
      }
    }

    // Right arrow auto-fills suggestion
    if (e.key === 'ArrowRight' && suggestion.toLowerCase().startsWith(query.toLowerCase())) {
      setQuery(suggestion);
      setSuggestion('');
    }
  };

  return (
    <div className="auto-complete-box">
      <div className="input-wrapper">
        <input
          type="text"
          ref={inputRef}
          value={query}
          onChange={handleChange}
          onKeyDown={handleKeyDown}
          placeholder="Start typing a color..."
          autoComplete="off"
          onFocus={handleFocus}
        />
        <div className="ghost-text">
          {suggestion}
        </div>
      </div>
    </div>
  );
}
