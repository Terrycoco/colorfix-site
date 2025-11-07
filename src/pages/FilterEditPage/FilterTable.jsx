import { useEffect, useState } from "react";
import {API_FOLDER} from '@helpers/config';

const FilterTable = ({ onSelect }) => {
  const [filters, setFilters] = useState([]);
    const [editingId, setEditingId] = useState(null);

    const fetchFilters = () => {
        fetch(`${API_FOLDER}/get-all-filters.php?d=${Date.now()}`)
            .then((res) => res.json())
            .then(setFilters)
            .catch((err) => console.error("Error fetching filters:", err));
    };
    
    useEffect(() => {
        fetchFilters();
    }, []);



    const handleChange = (e, id, field) => {
        setFilters((prev) =>
            prev.map((f) =>
            f.id === id ? { ...f, [field]: e.target.value } : f
            )
        );
    }; 

    const handleDelete = (id) => {
        if (!window.confirm('Delete this filter?')) return;

        fetch(`${API_FOLDER}/delete-filter.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id }),
        })
            .then((res) => res.json())
            .then(() => fetchFilters())
            .catch((err) => console.error('Delete failed:', err));
        };


    const handleSave = (id) => {
        const filter = filters.find((f) => f.id === id);
        if (!filter) return;

        fetch(`${API_FOLDER}/upsert-filter.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(filter),
        })
            .then((res) => res.json())
            .then(() => {
                setEditingId(null);
               fetchFilters(); 
            })
            .catch((err) => console.error('Save failed:', err));
        };

   
    const renderEditableCell = (filter, field) => {
        const key = `${filter.id}-${field}`;
        return (
            <td className='px-2 py-2' onClick={() => setEditingId(key)} style={{ cursor: 'pointer' }}>
            {editingId === key ? (
                <input
                    className="px-2 py-1 border  w-full"
                    value={filter[field] || ''}
                    onChange={(e) => handleChange(e, filter.id, field)}
                    onBlur={() => handleSave(filter.id)}
                    onKeyDown={(e) => e.key === 'Enter' && handleSave(filter.id)}
                    autoFocus
                />
            ) : (
                filter[field]
            )}
            </td>
        );
    };




 

  if (!filters.length) return <div className="p-4">Loading filters…</div>;

  return (
    <div className="p-4 flex flex-col gap-2">
        <button
            className="self-start px-3 py-1 text-white rounded "
            onClick={() => {
                const newRow = {
                id: null,
                label: '',
                name: '',
                tablename: '',
                fieldname: '',
                };
                setFilters((prev) => [newRow, ...prev]);
                setEditingId('new'); // use a string to distinguish
            }}
            >
            + Add Filter
            </button>

     <table className="inline-table text-sm border border-gray-300">
  <thead>
    <tr className="bg-gray-100">
      <th className="px-2 py-1 border border-gray-300 text-left">id</th>
      <th className="px-2 py-1 border border-gray-300 text-left">tablename</th>
      <th className="px-2 py-1 border border-gray-300 text-left">fieldname</th>
      <th className="px-2 py-1 border border-gray-300 text-left">name</th>
      <th className="px-2 py-1 border border-gray-300 text-left">label</th>
      <th className="px-2 py-1 border border-gray-300 text-left">Actions</th>
    </tr>
  </thead>
<tbody>
  {filters.map((filter) => (
    <tr key={filter.id} className="hover:bg-gray-50">
       <td className="px-2 py-1 w-[12px]">{filter.id}</td>
        {renderEditableCell(filter, 'tablename')}
           {renderEditableCell(filter, 'fieldname')}
      {renderEditableCell(filter, 'name')}
          {renderEditableCell(filter, 'label')}
          <td className="px-2 py-1 border border-gray-200">
                <button
                    onClick={() => handleDelete(filter.id)}
                    className="text-red-600 hover:underline"
                >
                    Delete
                </button>
                </td>
    </tr>
  ))}
</tbody>

</table>



    </div>
  );
};

export default FilterTable;
