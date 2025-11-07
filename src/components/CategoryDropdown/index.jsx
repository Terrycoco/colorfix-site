import { useEffect, useState, useRef } from 'react';
import { useAppState } from '@context/AppStateContext';
import './dropdown.css';

const SHOW_ALL = '__ALL__'; // sentinel value for the <select>

function CategoryDropdown({ onSelect, useShowAll = false }) {
  const { categories } = useAppState();
  const [selected, setSelected] = useState(SHOW_ALL);
  const [hueCategories, setHueCategories] = useState([]);

  // Track last user tap/pointer time to distinguish tap vs. auto-focus
  const lastPointerDownAtRef = useRef(0);

  // Build list: hue + neutral
  useEffect(() => {
    if (Array.isArray(categories)) {
      setHueCategories(
        categories
          .filter(c => c && ['hue', 'neutral'].includes(c.type))
          .sort((a, b) =>
            String(a.name).localeCompare(String(b.name), undefined, {
              sensitivity: 'base',
              numeric: true,
            })
          )
      );
    } else {
      setHueCategories([]);
    }
  }, [categories]);

  function handleChange(e) {
    const val = e.target.value;
    setSelected(val);

    if (val === SHOW_ALL) {
      onSelect && onSelect(null); // clear in parent
      return;
    }

    try {
      const cat = JSON.parse(val); // cat is the real { id, name, type, ... }
      onSelect && onSelect(cat);
    } catch {
      onSelect && onSelect(null);
    }
  }

  // Prevent native picker from auto-opening when focus was not from a tap
  function handleFocus(e) {
    // Only enforce on coarse pointers (phones/tablets)
    const isCoarse = typeof window !== 'undefined' && window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
    if (!isCoarse) return;

    const since = Date.now() - (lastPointerDownAtRef.current || 0);
    // If focus didn’t come from a recent pointer/tap (e.g., keyboard “Next”/Enter), blur to avoid auto-open
    if (since > 350) {
      // Briefly blur; user can still tap to open intentionally
      e.target.blur();
    }
  }

  return (
    <div
      className="dropdown-container"
      // Record taps anywhere in the container right before select receives focus
      onPointerDown={() => { lastPointerDownAtRef.current = Date.now(); }}
    >
      <label htmlFor="category" className="dropdown-label">Color Category</label>

      <select
        id="category"
        value={selected}
        onChange={handleChange}
        onFocus={handleFocus}
        // Also record taps directly on the select (some browsers deliver events differently)
        onPointerDown={() => { lastPointerDownAtRef.current = Date.now(); }}
      >
        {useShowAll && (
          <option value={SHOW_ALL}>Show All</option>
        )}
        {hueCategories.map((cat) => (
          <option key={cat.id} value={JSON.stringify(cat)}>
            {cat.name}
          </option>
        ))}
      </select>
    </div>
  );
}

export default CategoryDropdown;
export { default as LightnessDropdown } from './LightnessDropdown';
export { default as ChromaDropdown } from './ChromaDropdown';
