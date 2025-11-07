import { useEffect, useState } from 'react';
import fetchSearchPresets from '@data/fetchSearchPresets';
import {useAppState} from '@context/AppStateContext';
import '@styles/named.css';
import {API_FOLDER} from '@helpers/config';
import CategorySelect from '@components/CategorySelect';

export default function SearchPresetList() {
  const { categories }  = useAppState();
  const  [presets, setPresets] = useState([]);
  const [edited, setEdited] = useState({}); // store edits by row ID
  const [sortField, setSortField] = useState('id');
  const [sortDir, setSortDir] = useState('asc');
  const [selectedPreset, setSelectedPreset] = useState([]);

  console.log('categories:', categories);

  useEffect(() => {
    fetchSearchPresets()
      .then((data) => setPresets(data))
      .catch(console.error);
  }, []);

  useEffect(() => {
      console.log("Categories changed, length:", categories?.length);
    }, [categories]);

  const sortableHeaders = [
      { key: 'id', label: 'ID' },
      { key: 'name', label: 'Name' },
      { key: 'category_id', label: 'Category (opt)'},
      { key: 'hue_min', label: 'Hue Min' },
      { key: 'hue_max', label: 'Hue Max' },
      { key: 'chroma_min', label: 'Chr Min' },
      { key: 'chroma_max', label: 'Chr Max' },
      { key: 'light_min', label: 'Light Min' },
      { key: 'light_max', label: 'Light Max' },
 
      { key: 'active', label: 'Active' },
      { key: 'locked', label: 'Locked' },
      { key: 'actions', label: 'Actions' },
           { key: 'description', label: 'Descr' },
      { key: 'notes', label: 'Notes' },
    ];

  const handleChange = (id, field, value) => {
    setEdited(prev => ({
      ...prev,
      [id]: {
        ...prev[id],
        [field]: value
      }
    }));
  };

  const handleSave = async (id) => {
      const updatedRow = edited[id];
      if (!updatedRow) return;

      const fullRow = { ...presets.find(preset => preset.id === id), ...updatedRow };


      try {
        const api = `${API_FOLDER}/upsert-search-preset.php`;
        console.log('api: ', api);
        const response = await fetch(api, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify(fullRow),
        });

        if (!response.ok) {
          // Log status and text if response not OK
          const text = await response.text();
          console.error('API error response:', response.status, text);
          alert('Save failed: ' + response.status);
          return;
        }


        const result = await response.json();
        if (!result.success) {
          console.error("Failed to save preset:", result);
           alert("Save failed: " + (result.message || 'Unknown error'));
          return;
        }

        // Merge updates into local state and clear edited row
        setPresets(prev =>
          prev.map(cat => (cat.id === id ? { ...cat, ...updatedRow } : cat))
        );
        setEdited(prev => {
          const copy = { ...prev };
          delete copy[id];
          return copy;
        });

      } catch (err) {
        console.error("Error saving preset:", err);
        alert("Save error. See console.");
      }
    };

  const handleSort = (key) => {
  if (key === sortField) {
    setSortDir(prev => (prev === 'asc' ? 'desc' : 'asc'));
  } else {
    setSortField(key);
    setSortDir('asc');
  }
  
};

const sortedPresets = presets ?? [...presets].sort((a, b) => {
  const aVal = a[sortField];
  const bVal = b[sortField];

  // Handle nulls or undefineds gracefully
  if (aVal == null && bVal == null) return 0;
  if (aVal == null) return 1;
  if (bVal == null) return -1;

  // Handle numbers vs strings
  if (typeof aVal === 'number' && typeof bVal === 'number') {
    return sortDir === 'asc' ? aVal - bVal : bVal - aVal;
  } else {
    return sortDir === 'asc'
      ? String(aVal).localeCompare(String(bVal))
      : String(bVal).localeCompare(String(aVal));
  }
});


  const getValue = (cat, id, field) =>
    edited[id]?.[field] ?? (cat[field] ?? '');



  return (
    <div className="px-4">
      <div className="flex justify-between">
      <h4 className=" font-bold mb-4">Search Presets List</h4>
      <button
        onClick={() => {
          const newId = `new-${Date.now()}`; // temp ID for React key
          const newPreset = {
            id: newId,
            name: '',
            category_id: null,
            type: '',
            hue_min: null,
            hue_max: null,
            chroma_min: null,
            chroma_max: null,
            light_min: null,
            light_max: null,
            active: true,
            locked: false,
            description: '',
            notes: '',
          };
          setPresets(prev => [newPreset, ...prev]);
          setEdited(prev => ({
            ...prev,
            [newId]: newPreset,
          }));
          setSelectedPreset(newPreset);
        }}
      >
        + New Preset
      </button>

    </div>


      <table className="min-w-full border border-gray-300 text-sm">
        <thead className="bg-gray-100 text-gray-700 text-sm">
          <tr>
            {sortableHeaders.map(({ key, label }) => {
              const isSorted = key === sortField;
              const arrow = isSorted ? (sortDir === 'asc' ? ' ▲' : ' ▼') : '';
              return (
                <th
                  key={key}
                  className="px-2 py-1 text-sm cursor-pointer select-none"
                  onClick={() => handleSort(key)}
                >
                  {label}{arrow}
                </th>
              );
            })}
          </tr>
        </thead>
        <tbody>
          {sortedPresets?.map(cat => (
            <tr key={cat.id} 
                className={selectedPreset?.id === cat.id ? 'max-w-[13px] highlight-row' : 'border-t border-gray-200 max-w-[13px] '}
                onClick={() => setSelectedPreset(cat)}>
                
              <td className="px-2 py-1">{cat.id}</td>

              <td><input value={getValue(cat, cat.id, 'name')} onChange={(e) => handleChange(cat.id, 'name', e.target.value)} className="border px-1 w-24" /></td>



            <td>
 
            <CategorySelect
                value={getValue(cat, cat.id, 'category_id')}
                onChange={(selectedId) => handleChange(cat.id, 'category_id', selectedId)}
              />
               
            </td>


              <td><input type="number" value={getValue(cat, cat.id, 'hue_min')} onChange={(e) => handleChange(cat.id, 'hue_min', e.target.value)} className="border px-1 w-16" /></td>
              <td><input type="number" value={getValue(cat, cat.id, 'hue_max')} onChange={(e) => handleChange(cat.id, 'hue_max', e.target.value)} className="border px-1 w-16" /></td>

              <td><input type="number" value={getValue(cat, cat.id, 'chroma_min')} onChange={(e) => handleChange(cat.id, 'chroma_min', e.target.value)} className="border px-1 w-16" /></td>
              <td><input type="number" value={getValue(cat, cat.id, 'chroma_max')} onChange={(e) => handleChange(cat.id, 'chroma_max', e.target.value)} className="border px-1 w-16" /></td>

              <td><input type="number" value={getValue(cat, cat.id, 'light_min')} onChange={(e) => handleChange(cat.id, 'light_min', e.target.value)} className="border px-1 w-16" /></td>
              <td><input type="number" value={getValue(cat, cat.id, 'light_max')} onChange={(e) => handleChange(cat.id, 'light_max', e.target.value)} className="border px-1 w-16" /></td>

             


              <td>
                <input type="checkbox"
                  checked={getValue(cat, cat.id, 'active')}
                  onChange={(e) => handleChange(cat.id, 'active', e.target.checked)}
                />
              </td>
              <td>
                <input type="checkbox"
                  checked={getValue(cat, cat.id, 'locked')}
                  onChange={(e) => handleChange(cat.id, 'locked', e.target.checked)}
                />
              </td>


              <td>
                {edited[cat.id] ? (
                  <button
                    onClick={() => handleSave(cat.id)}
                    className="text-green-600 hover:underline text-xs"
                  >
                    Save
                  </button>
                ) : (
                  <span className="text-gray-400 text-xs">—</span>
                )}
              </td>
               <td>
                <textarea
                    value={getValue(cat, cat.id, 'description')}
                    onChange={(e) => handleChange(cat.id, 'description', e.target.value)}
                    className="border px-1 w-48 h-16 resize-none"
                />
                </td>
                <td>
                <textarea
                    value={getValue(cat, cat.id, 'notes')}
                    onChange={(e) => handleChange(cat.id, 'notes', e.target.value)}
                    className="border px-1 w-48 h-16 resize-none"
                />
                </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
