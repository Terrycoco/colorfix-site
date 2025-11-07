import  { useEffect, useState } from 'react';
import {useAppState} from '@context/AppStateContext';


export default function LightnessDropdown({ onSelect }) {
  const { categories  } = useAppState();
  const [selected, setSelected] = useState(JSON.stringify({ category: 'all' }));
  const [hueCategories, setHueCategories] = useState([]);
 

  //USE EFFECTS
  useEffect(() => {
    if (Array.isArray(categories)) {
      setHueCategories(categories.filter(cat => cat.type === "lightness"));
    }
  }, [categories]);

 //handlers
  const handleChange = (e) => {
    const cat = JSON.parse(e.target.value);
    console.log('Selected:', cat);
    setSelected(e.target.value);  // keep it a string
    onSelect(cat);                // pass real object to parent
  };

  return (
      <select id="hcl_l" value={selected} onChange={handleChange}>
        <option value={JSON.stringify({ category: 'all' })}>Show All</option>
        {hueCategories.map((cat) => (
          <option key={cat.id + 'l'} value={JSON.stringify(cat)}>
            {cat.name}
          </option>
        ))}
      </select>
  );
}

