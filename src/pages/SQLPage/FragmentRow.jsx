const FragmentRow = ({
  label,
  options,
  selectedId,
  text,
  name,
  onSelectChange,
  onTextChange,
  onNameChange,
  onSave,
}) => {
  return (
    <div className="flex items-start gap-2 mb-2">
      <label className="w-20 text-sm font-medium mt-1">{label}</label>

      <select
        value={selectedId}
        onChange={onSelectChange}
        className="bg-white text-sm p-1 border rounded w-auto min-w-[150px]"
      >
        <option value="new">-- New --</option>
        {options.map((opt) => (
          <option key={opt.id} value={opt.id}>
            {opt.name}
          </option>
        ))}
      </select>

      <textarea
        value={text}
        onChange={onTextChange}
        className="bg-white text-sm border rounded p-1 w-[400px] min-h-[60px] resize-y"
      />

      <input
        type="text"
        value={name}
        onChange={onNameChange}
        placeholder="Name"
        className="bg-white text-sm border rounded px-2 py-1 w-[120px]"
      />

      <button
        onClick={onSave}
        className="text-sm px-3 py-1 border rounded bg-blue-100 hover:bg-blue-200"
      >
        Save
      </button>
    </div>
  );
};

export default FragmentRow;
