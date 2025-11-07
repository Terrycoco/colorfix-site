import { useAppState } from '@context/AppStateContext';

export default function SortButtons(className) {
  const { sortBy, setSortBy, sortDescending, setSortDescending} = useAppState();

  const sortOptions = [
    { label: 'name', value: 'name'},
    { label: 'hue', value: 'hcl_h'},
    { label: 'chroma', value: 'hcl_c' },
    { label: 'lightness', value: 'hcl_l' },
  ];

  return (
    <div className={`sort-controls ${className}`} >
      <span className="sort-label">Sort by:</span>

      {sortOptions.map(({ label, value }) => (
        <label key={value} className="sort-option">
          <input
            type="radio"
            name="sort"
            value={value}
            checked={sortBy === value}
            onChange={() => setSortBy(value)}
          />
          {label}
        </label>
      ))}

      <label className="sort-option">
        <input
          type="checkbox"
          checked={sortDescending}
          onChange={(e) => setSortDescending(e.target.checked)}
        />
        Desc
      </label>
    </div>
  );
}
