import { parseAdminSearch } from "./adminSearch";

/**
 * Reusable controlled Admin search input.
 *
 * Input rendering and query interpretation live here; record fetching/filtering
 * remains with the owning page (or its department's API for server-backed lists).
 *
 * onChange(rawValue) keeps compatibility with AdminListPane's existing contract.
 * onQueryChange(parsedQuery) is optional for consumers needing structured queries.
 */
export default function AdminSearchField({
  value = "",
  onChange,
  onQueryChange,
  placeholder = "Search by name or #ID...",
  className = "admin-field__control",
  id,
  name,
  disabled = false,
  autoComplete = "off",
}) {
  const handleChange = (event) => {
    const next = event.target.value;
    onChange?.(next);
    onQueryChange?.(parseAdminSearch(next));
  };

  return (
    <input
      id={id}
      name={name}
      type="search"
      className={className}
      value={value ?? ""}
      placeholder={placeholder}
      aria-label={placeholder}
      autoComplete={autoComplete}
      disabled={disabled}
      onChange={handleChange}
    />
  );
}
