const FAMILY_OPTIONS = [
  ["", "All Families"],
  ["white", "Whites"],
  ["beige", "Beiges"],
  ["greige", "Greiges"],
  ["gray", "Grays"],
  ["brown", "Browns"],
  ["black", "Blacks"],
  ["red", "Reds"],
  ["orange", "Oranges"],
  ["yellow", "Yellows"],
  ["green", "Greens"],
  ["blue", "Blues"],
  ["purple", "Purples"],
  ["pink", "Pinks"],
];

export default function PaletteFilters({
  query,
  onQueryChange,
  visibility,
  onVisibilityChange,
  paletteType,
  onPaletteTypeChange,
  colorFamily,
  onColorFamilyChange,
  paletteTypes = [],
  resultCount = 0,
  totalCount = 0,
  onClear,
}) {
  const hasFilters =
    query.trim() !== ""
    || visibility !== "all"
    || paletteType !== ""
    || colorFamily !== "";

  return (
    <div className="admin-palette-filters">
      <input
        className="admin-palette-filters__search"
        type="search"
        value={query}
        onChange={(event) => onQueryChange(event.target.value)}
        placeholder="Find palette…"
        aria-label="Find palette"
      />

      <div className="admin-palette-filters__grid">
        <select
          value={visibility}
          onChange={(event) => onVisibilityChange(event.target.value)}
          aria-label="Filter by visibility"
        >
          <option value="all">All</option>
          <option value="public">Public</option>
          <option value="private">Private</option>
        </select>

        <select
          value={paletteType}
          onChange={(event) => onPaletteTypeChange(event.target.value)}
          aria-label="Filter by palette type"
        >
          <option value="">All Types</option>
          {paletteTypes.map((type) => (
            <option key={type} value={type}>
              {type.charAt(0).toUpperCase() + type.slice(1)}
            </option>
          ))}
        </select>

        <select
          value={colorFamily}
          onChange={(event) => onColorFamilyChange(event.target.value)}
          aria-label="Filter by color family"
        >
          {FAMILY_OPTIONS.map(([value, label]) => (
            <option key={value || "all"} value={value}>
              {label}
            </option>
          ))}
        </select>

        <button
          className="admin-palette-filters__clear"
          type="button"
          onClick={onClear}
          disabled={!hasFilters}
        >
          Clear
        </button>
      </div>

      <div className="admin-palette-filters__count">
        {resultCount === totalCount
          ? `${totalCount} palettes`
          : `${resultCount} of ${totalCount}`}
      </div>
    </div>
  );
}
