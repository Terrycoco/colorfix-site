import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./kicker-dropdown.css";

const LIST_URL = `${API_FOLDER}/v2/admin/kickers/list.php`;

export default function KickerDropdown({
  value = "",
  onChange,
  includeBlank = true,
  disabled = false,
}) {
  const [kickers, setKickers] = useState([]);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      try {
        const res = await fetch(`${LIST_URL}?_=${Date.now()}`, { credentials: "include" });
        const data = await res.json();
        if (!res.ok || !data?.ok) return;
        if (cancelled) return;
        setKickers(Array.isArray(data.items) ? data.items : []);
      } catch {
        /* ignore */
      }
    }
    load();
    return () => {
      cancelled = true;
    };
  }, []);

  const options = useMemo(
    () =>
      kickers.map((k) => ({
        value: String(k.kicker_id),
        label: k.is_active ? k.display_text : `${k.display_text} (inactive)`,
      })),
    [kickers]
  );

  return (
    <div className="kicker-dropdown">
      <select
        value={value ?? ""}
        onChange={(e) => onChange?.(e.target.value === "" ? null : e.target.value)}
        disabled={disabled}
      >
        {includeBlank && <option value="">No kicker</option>}
        {options.map((opt) => (
          <option key={opt.value} value={opt.value}>
            {opt.label}
          </option>
        ))}
      </select>
      <button
        type="button"
        className="kicker-dropdown__refresh"
        onClick={async () => {
          try {
            const res = await fetch(`${LIST_URL}?_=${Date.now()}`, { credentials: "include" });
            const data = await res.json();
            if (!res.ok || !data?.ok) return;
            setKickers(Array.isArray(data.items) ? data.items : []);
          } catch {
            /* ignore */
          }
        }}
        title="Refresh kickers"
        aria-label="Refresh kickers"
      >
        ↻
      </button>
      <button
        type="button"
        className="kicker-dropdown__manage"
        onClick={() => window.open("/admin/kickers", "_blank", "noopener")}
        title="Manage kickers"
        aria-label="Manage kickers"
      >
        +
      </button>
    </div>
  );
}
