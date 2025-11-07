export default function QueryInfoPanel({ queryOptions, queryInfo, setQueryInfo }) {
  const update = (field, value) => {
    setQueryInfo({ ...queryInfo, [field]: value });
  };

  return (
    <div className="p-2 bg-white rounded-lg border border-gray-300 text-xs space-y-2 w-full max-w-md">
      <div className="grid grid-cols-2 gap-2">
        {/* Left Column */}
        <div className="space-y-1">
          <label className="block text-gray-600">Name *</label>
          <input
            type="text"
            value={queryInfo.name || ''}
            onChange={(e) => update('name', e.target.value)}
            className="w-full border border-gray-300 rounded px-2 py-1 text-xs"
          />

          <label className="block text-gray-600">Display</label>
          <input
            type="text"
            value={queryInfo.display || ''}
            onChange={(e) => update('display', e.target.value)}
            className="w-full border border-gray-300 rounded px-2 py-1 text-xs"
          />

          <label className="block text-gray-600">Type (group THIS belongs to)</label>
          <input
            type="text"
            value={queryInfo.type || ''}
            onChange={(e) => update('type', e.target.value)}
            className="w-full border border-gray-300 rounded px-2 py-1 text-xs"
          />

          <label className="block text-gray-600">Item Type (how to render THIS)</label>
          <input
            type="text"
            value={queryInfo.item_type || ''}
            onChange={(e) => update('item_type', e.target.value)}
            className="w-full border border-gray-300 rounded px-2 py-1 text-xs"
          />

          <label className="block text-gray-600">Color</label>
          <input
            type="text"
            value={queryInfo.color || ''}
            onChange={(e) => update('color', e.target.value)}
            className="w-full border border-gray-300 rounded px-2 py-1 text-xs"
          />

     <label className="inline-flex items-center mt-2">
            <input
              type="checkbox"
              checked={queryInfo.active}
              onChange={(e) => update('active', e.target.checked)}
              className="mr-1 text-blue-500"
            />
            <span className="text-gray-700">Active</span>
          </label>

          <label className="inline-flex items-center">
            <input
              type="checkbox"
              checked={queryInfo.pinnable}
              onChange={(e) => update('pinnable', e.target.checked)}
              className="ml-2 mr-1 text-blue-500"
            />
            <span className="text-gray-700">Pinnable</span>
          </label>

           <label className="inline-flex items-center">
            <input
              type="checkbox"
              checked={queryInfo.has_header}
              onChange={(e) => update('has_header', e.target.checked)}
              className="ml-2 mr-1 text-blue-500"
            />
            <span className="text-gray-700">Header?</span>
          </label>





        </div>

        {/* Right Column */}
        <div className="space-y-1">
          <label className="block text-gray-600">query_id</label>
          <div className="border border-gray-200 rounded px-2 py-1 bg-gray-50 text-gray-700">{queryInfo.query_id ?? 'new'}</div>

          <label className="block text-gray-600">Sort Order</label>
          <input
            type="number"
            step="0.1"
            value={queryInfo.sort_order ?? ''}
            onChange={(e) => update('sort_order', parseFloat(e.target.value))}
            className="w-full border border-gray-300 rounded px-2 py-1 text-xs"
          />

          <label className="block text-gray-600">Parent ID</label>
          <select
            value={queryInfo.parent_id ?? ''}
            onChange={(e) => update('parent_id', e.target.value ? parseInt(e.target.value) : null)}
            className="w-full border border-gray-300 rounded px-2 py-1 text-xs"
          >
            <option value="">None</option>
            {queryOptions.map((q) => (
              <option key={q.query_id} value={q.query_id}>
                {q.name}
              </option>
            ))}
          </select>

       <label className="block text-gray-600">On Click Query</label>
          <select
            value={queryInfo.on_click_query ?? ''}
            onChange={(e) => update('on_click_query', e.target.value ? parseInt(e.target.value) : null)}
            className="w-full border border-gray-300 rounded px-2 py-1 text-xs"
          >
            <option value="">None</option>
            {queryOptions.map((q) => (
              <option key={q.query_id} value={q.query_id}>
                {q.name}
              </option>
            ))}
          </select>


          <label className="block text-gray-600">On Click URL</label>
          <input
            type="text"
            value={queryInfo.on_click_url || ''}
            onChange={(e) => update('on_click_url', e.target.value)}
            className="w-full border border-gray-300 rounded px-2 py-1 text-xs"
          />

          <label className="block text-gray-600">Image URL</label>
          <input
            type="text"
            value={queryInfo.image_url || ''}
            onChange={(e) => update('image_url', e.target.value)}
            className="w-full border border-gray-300 rounded px-2 py-1 text-xs"
          />

     
        </div>
      </div>

      {/* Notes Field Full Width */}
      <div className="space-y-1 pt-1">


          <label className="block text-gray-600">Header Title</label>
          <input
            type="text"
            value={queryInfo.header_title || ''}
            onChange={(e) => update('header_title', e.target.value)}
            className="w-full border border-gray-300 rounded px-2 py-1 text-xs"
          />

          <label className="block text-gray-600">Header Subtitle</label>
          <input
            type="text"
            value={queryInfo.header_subtitle || ''}
            onChange={(e) => update('header_subtitle', e.target.value)}
            className="w-full border border-gray-300 rounded px-2 py-1 text-xs"
          />


       <label className="block text-gray-600">Header Content</label>
        <textarea
          value={queryInfo.header_content || ''}
          onChange={(e) => update('header_content', e.target.value)}
          rows={2}
          className="w-full border border-gray-300 rounded px-2 py-1 text-xs resize-y"
        />





       <label className="block text-gray-600">Notes</label>
        <textarea
          value={queryInfo.notes || ''}
          onChange={(e) => update('notes', e.target.value)}
          rows={2}
          className="w-full border border-gray-300 rounded px-2 py-1 text-xs resize-y"
        />

        
        <label className="block text-gray-600">Description (explanation for user)</label>
        <textarea
          value={queryInfo.description || ''}
          onChange={(e) => update('description', e.target.value)}
          rows={2}
          className="w-full border border-gray-300 rounded px-2 py-1 text-xs resize-y"
        />

   
      </div>
    </div>
  );
}
