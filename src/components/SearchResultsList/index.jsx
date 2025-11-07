export default function SearchResultsList({ results = [] }) {
  if (!results.length) return null;

  return (
    <div className="flex-grow overflow-y-auto pt-4 pb-24">
      <ul className="space-y-3 w-full">
        {results.map((color) => (
          <li key={color.id}>
            <a
              href={`/color/${color.id}`}
              className="flex items-center justify-between w-full p-3 border border-gray-200 rounded-xl shadow-sm bg-white"
            >
              <div>
                <div className="font-medium text-base">{color.name}</div>
                <div className="text-sm text-gray-500">
                  {color.brand_full &&
                    `(${color.brand_full}${color.code ? ` · ${color.code}` : ""})`}
                </div>
              </div>
              <div
                className="w-6 h-6 rounded-full border border-gray-300 shrink-0"
                style={{
                  backgroundColor: `rgb(${color.r}, ${color.g}, ${color.b})`,
                }}
              />
            </a>
          </li>
        ))}
      </ul>
    </div>
  );
}
