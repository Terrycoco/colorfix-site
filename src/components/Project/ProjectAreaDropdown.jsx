export default function ProjectAreaDropdown({
  rooms = [],
  value = "",
  onChange,
  disabled = false,
  includeNone = true,
  noneLabel = "Unassigned",
  includeAll = false,
  allLabel = "All Areas",
  className = "admin-field__control",
  valueKey = "id",
  ...selectProps
}) {
  const areas = Array.isArray(rooms) ? rooms : [];
  const selectedId = String(value ?? "");
  const knownSelection = selectedId === ""
    || (includeAll && selectedId === "__all__")
    || areas.some((area) => String(area[valueKey]) === selectedId);

  return (
    <select
      aria-label="Area / Room"
      {...selectProps}
      className={className}
      value={selectedId}
      disabled={disabled}
      onChange={(event) => onChange?.(event.target.value)}
    >
      {includeAll && <option value="__all__">{allLabel}</option>}
      {includeNone && <option value="">{noneLabel}</option>}
      {!includeNone && selectedId === "" && (
        <option value="" disabled>Select area</option>
      )}
      {!knownSelection && (
        <option value={selectedId} disabled>{selectedId} (not in Setup)</option>
      )}
      {areas.map((area) => (
        <option key={area.id} value={area[valueKey]}>{area.name}</option>
      ))}
    </select>
  );
}
