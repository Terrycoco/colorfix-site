import { useEffect, useState } from 'react';
import { useAppState } from '@context/AppStateContext';
import './dropdown.css';

const ALL_VALUE = JSON.stringify({ category: 'all' });

export default function ChromaDropdown({ onSelect }) {
  const { categories } = useAppState();
  const [selected, setSelected] = useState(ALL_VALUE);
  const [chromaCategories, setChromaCategories] = useState([]);

  useEffect(() => {
    if (Array.isArray(categories)) {
      setChromaCategories(categories.filter(cat => cat.type === 'chroma'));
    }
  }, [categories]);

  const handleChange = (e) => {
    const raw = e.target.value;
    const cat = JSON.parse(raw);
    setSelected(raw);
    onSelect(cat);
  };

  const formatLabel = (cat) => {
    if (!cat || cat.category === 'all') return 'Any';
    const type = (cat.type || '').toLowerCase();
    return type ? `${cat.name} ${type}` : cat.name;
  };

  return (
    <select id="chroma" value={selected} onChange={handleChange}>
      <option value={ALL_VALUE}>Any</option>
      {chromaCategories.map((cat) => (
        <option key={`${cat.id}-c`} value={JSON.stringify(cat)}>
          {formatLabel(cat)}
        </option>
      ))}
    </select>
  );
}
