import { useState, useRef, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAppState } from '@context/AppStateContext';
import './searchitem.css';

export default function NameSearchItem({ item }) {
  const navigate = useNavigate();
  const itemRef = useRef(null);
  const inputRef = useRef(null);
  const formRef = useRef(null);

  const [active, setActive] = useState(false);
  const [term, setTerm] = useState('');
  const [floatingStyle, setFloatingStyle] = useState({});
  const [showInput, setShowInput] = useState(false);
  const [placeholderHeight, setPlaceholderHeight] = useState(0);
  const [openingFromRect, setOpeningFromRect] = useState(null);

  const { clearSearchFilters, noResults, setNoResults } = useAppState();
  const submittingRef = useRef(false);

  // Focus when active
  useEffect(() => {
    if (active && inputRef.current) inputRef.current.focus();
  }, [active]);

  // Prevent immediate close on the opening click
  useEffect(() => {
    if (!active) return;

    let justOpened = true;

    const handleClickOutside = (e) => {
      if (justOpened) {
        justOpened = false;
        return;
      }
      if (formRef.current && !formRef.current.contains(e.target)) {
        setShowInput(false);
        setActive(false);
        setTerm('');
        setOpeningFromRect(null);
      }
    };

    document.addEventListener('click', handleClickOutside);
    return () => document.removeEventListener('click', handleClickOutside);
  }, [active]);

  // Focus when input becomes visible
  useEffect(() => {
    if (showInput && inputRef.current) inputRef.current.focus();
  }, [showInput]);

  const handleSubmit = (e) => {
    e.preventDefault();
    submittingRef.current = true;
    clearSearchFilters();
    setNoResults(false);
    navigate(
      `/results/18?name=${encodeURIComponent('%' + term + '%')}&code=${encodeURIComponent('%' + term + '%')}`
    );
  };

  const handleClick = () => {
    const rect = itemRef.current?.getBoundingClientRect();
    const isMobile = window.innerWidth <= 430;

    // Preserve the tile's space so the gallery doesn't reflow
    if (rect) setPlaceholderHeight(rect.height);

    setActive(true);
    setShowInput(true);

    if (isMobile) {
      // Mobile: centered overlay
      setFloatingStyle({
        position: 'fixed',
        top: '50%',
        left: '50%',
        transform: 'translate(-50%, -50%)',
        width: '88vw',
        maxWidth: '380px',
        height: 'auto',
        zIndex: 3000,
      });
      setOpeningFromRect(null);
    } else {
      // Desktop: overlay right where you clicked (no centering), no reflow
      if (rect) {
        setOpeningFromRect(rect); // store for potential future tweaks
        setFloatingStyle({
          position: 'fixed',
          top: `${rect.top}px`,     // viewport-based; fixed doesn't need scrollY
          left: `${rect.left}px`,
          width: `${rect.width}px`,
          height: 'auto',
          zIndex: 2000,
        });
      } else {
        // Fallback: inline width if rect missing (shouldn't happen)
        setFloatingStyle({
          position: 'fixed',
          top: '20vh',
          left: '10vw',
          width: '600px',
          maxWidth: '90vw',
          height: 'auto',
          zIndex: 2000,
        });
      }
    }

    // Tiny delay to ensure reliable keyboard focus on mobile
    setTimeout(() => {
      if (inputRef.current) inputRef.current.focus();
    }, 50);
  };

  return (
    <>
      {/* Collapsed tile or placeholder to prevent reflow */}
      {!active ? (
  <div className="search-item item name-search" ref={itemRef} onClick={handleClick}>

          <div className="search-display">{item.display}</div>
          <div className="search-descr">{item.description}</div>
        </div>
      ) : (
        // keeps the grid height where this item was
        <div style={{ height: placeholderHeight }} />
      )}

      {/* Floating expanded card */}
      {active && (
        <div
          className="search-floating-card name-search-float"
          style={floatingStyle}
          onClick={(e) => e.stopPropagation()}
        >
          <div className="search-display">{item.display}</div>

          {showInput ? (
            <form ref={formRef} onSubmit={handleSubmit} className="search-form">
              <input
                ref={inputRef}
            
                  type="search"
                inputMode="search"
                value={term}
                onChange={(e) => setTerm(e.target.value)}
                className="search-input"
                placeholder="Search by name or code"
              />

              <button
                type="submit"
                onClick={(e) => e.stopPropagation()}
                className="search-button"
              >
                Search
              </button>

              {noResults && (
                <div className="search-error">Sorry, no results found.</div>
              )}
            </form>
          ) : (
            <div className="search-label">{item.label}</div>
          )}
        </div>
      )}
    </>
  );
}
