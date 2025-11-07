// src/components/CategorySelect.jsx
import {useAppState} from '@context/AppStateContext';


//smart component
export default function CategorySelect({ value, onChange }) {

  const { categories } = useAppState();
  console.log('categories by select:', categories);

  return (
    <select
      value={value ?? ''}
      onChange={e => {
        const val = e.target.value === '' ? null : Number(e.target.value);
        onChange(val);
      }}
      className="border px-1 w-32"
    >
      <option value="">-- None --</option>
      {categories.map(cat => (
        <option key={cat.id} value={cat.id}>
          {cat.name} (ID: {cat.id})
        </option>
      ))}
    </select>
  );
}
