import { useEffect, useState } from 'react';
import { useAppState } from '@context/AppStateContext';

const ALL_VALUE = JSON.stringify({ category: 'all' });

export default function LightnessDropdown({ onSelect }) {
  const { categories } = useAppState();
  const [selected, setSelected] = useState(ALL_VALUE);
  const [lightnessCategories, setLightnessCategories] = useState([]);

  useEffect(() => {
    if (Array.isArray(categories)) {
      setLightnessCategories(categories.filter(cat => cat.type === 'lightness'));
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
    <select id="hcl_l" value={selected} onChange={handleChange}>
      <option value={ALL_VALUE}>Any</option>
      {lightnessCategories.map((cat) => (
        <option key={`${cat.id}-l`} value={JSON.stringify(cat)}>
          {formatLabel(cat)}
        </option>
      ))}
    </select>
  );
}
