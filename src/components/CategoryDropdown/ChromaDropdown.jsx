import  { useEffect, useState } from 'react';
import {useAppState} from '@context/AppStateContext';
import './dropdown.css';


export default function ChromaDropdown({ onSelect }) {
  const { categories  } = useAppState();
  const [selected, setSelected] = useState(JSON.stringify({ category: 'all' }));
  const [chromaCategories, setChromaCategories] = useState([]);
 

  //USE EFFECTS
  useEffect(() => {
    if (Array.isArray(categories)) {
      setChromaCategories(categories.filter(cat => cat.type === "chroma"));
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
      <select id="chroma" value={selected} onChange={handleChange}>
        <option value={JSON.stringify({ category: 'all' })}>Show All</option>
        {chromaCategories.map((cat) => (
          <option key={cat.id + 'c'} value={JSON.stringify(cat)}>
            {cat.name}
          </option>
        ))}
      </select>
  );
}

