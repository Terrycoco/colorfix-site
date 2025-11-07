export default function ItemEditForm ({formData, updateField, handleSubmit, queries, onNew}) {

const handleNew = () => {
  onNew();
}

return (
  <div className="w-full max-w-3xl bg-white shadow p-6 rounded-md">
      <p className="text-xs">id: {formData.id}</p>


<form onSubmit={handleSubmit} className="grid grid-cols-2 gap-4 text-sm ">
  
    <div>
      <label className="block font-medium">Handle*</label>
      <input
        type="text"
        value={formData.handle}
        onChange={(e) => updateField('handle', e.target.value)}
        className="w-full border px-2 py-1"
      />
    </div>
     <div>
      <label className="block font-medium">Insert Position</label>
      <input
        type="text"
        value={formData.insert_position}
        onChange={(e) => updateField('insert_position', e.target.value)}
        className="w-full border px-2 py-1"
      />
    </div>

  <div>
    <label className="block font-medium">Title</label>
    <input
      type="text"
      value={formData.title}
      onChange={(e) => updateField('title', e.target.value)}
      className="w-full border px-2 py-1"
    />
  </div>

  <div>
    <label className="block font-medium">Subtitle</label>
    <input
      type="text"
      value={formData.subtitle}
      onChange={(e) => updateField('subtitle', e.target.value)}
      className="w-full border px-2 py-1"
    />
  </div>
    <div>
    <label className="block font-medium">Display (used in search items)</label>
    <input
      type="text"
      value={formData.display}
      onChange={(e) => updateField('display', e.target.value)}
      className="w-full border px-2 py-1"
    />
  </div>

  <div>
    <label className="block font-medium">Description (used in search items)</label>
    <input
      type="text"
      value={formData.description}
      onChange={(e) => updateField('description', e.target.value)}
      className="w-full border px-2 py-1"
    />
  </div>

  <div>
    <label className="block font-medium">Insert With Query</label>
    {console.log('queries:', queries)}
    <select
      value={formData.query_id}
      onChange={(e) => updateField('query_id', parseInt(e.target.value))}
      className="w-full border px-2 py-1"
    >
      <option value="">-- Select Query --</option>
      {queries && queries.length > 0 && queries.map((q) => (
        <option key={q.query_id} value={q.query_id}>{`${q.name} (${q.query_id})`}</option>
      ))}
    </select>
  </div>

  <div>
    <label className="block font-medium">Item Type</label>
    <input
      type="text"
      value={formData.item_type}
      onChange={(e) => updateField('item_type', e.target.value)}
      className="w-full border px-2 py-1"
    />
  </div>

  <div>
    <label className="block font-medium">Image URL</label>
    <input
      type="text"
      value={formData.image_url}
      onChange={(e) => updateField('image_url', e.target.value)}
      className="w-full border px-2 py-1"
    />
  </div>

  <div>
    <label className="block font-medium">Target URL</label>
    <input
      type="text"
      value={formData.target_url}
      onChange={(e) => updateField('target_url', e.target.value)}
      className="w-full border px-2 py-1"
    />
  </div>

  <div>
    <label className="block font-medium">Body</label>
    <textarea
      value={formData.body}
      onChange={(e) => updateField('body', e.target.value)}
      className="w-full border px-2 py-1"
    />
  </div>

  <div>
    <label className="block font-medium">Color</label>
    <input
      type="text"
      value={formData.color}
      onChange={(e) => updateField('color', e.target.value)}
      className="w-full border px-2 py-1"
    />
  </div>

  <div className="col-span-2 flex items-center gap-4 mt-2">
    <label><input type="checkbox" checked={formData.is_clickable} onChange={(e) => updateField('is_clickable', e.target.checked ? 1 : 0)} /> Clickable</label>
    <label><input type="checkbox" checked={formData.is_pinnable} onChange={(e) => updateField('is_pinnable', e.target.checked ? 1 : 0)} /> Pinnable</label>
    <label><input type="checkbox" checked={formData.is_active} onChange={(e) => updateField('is_active', e.target.checked ? 1 : 0)} /> Active</label>
  </div>

  <div className="flex gap-2 col-span-2 mt-4">
    <button 
      type="submit" 
      className="bg-orange-500 text-white px-4 py-2 rounded">
        Save Item
    </button>
    <button
      onClick={handleNew}
      className="mb-4 px-4 py-2 bg-gray-200 rounded hover:bg-gray-300"
    >
      + New Item
    </button>

  </div>

</form>
</div>
);
}