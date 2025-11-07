const QueryResultsGrid = ({ rows }) => {
  if (!rows || rows.length === 0) {
    return <div className="text-sm italic text-gray-500">No results</div>;
  }

  const columns = Object.keys(rows[0]);

  return (
   <div className="max-h-[40vh] overflow-auto w-full px-1">
  <div className="grid grid-cols-6 gap-1">
      <table className="min-w-full table-auto border-collapse">
        <thead>
          <tr className="bg-gray-100 text-left">
            {columns.map((col) => (
              <th key={col} className="px-2 py-1 border-b border-gray-300 font-medium text-xxs">
                {col}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, i) => (
            <tr key={i} className="hover:bg-gray-50">
              {columns.map((col) => (
                <td key={col} className="px-1 bg-white py-1 border-b border-gray-200 text-xs">
                  {row[col]}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
    </div>
  );
};

export default QueryResultsGrid;
