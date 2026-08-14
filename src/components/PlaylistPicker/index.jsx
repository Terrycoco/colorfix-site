import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";

const LIST_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;

export default function PlaylistPicker({
  value = "",
  onChange,
  label = "Playlist",
  placeholder = "Pick a playlist...",
  includeInactive = true,
  includePrivate = true,
  disabled = false,
}) {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    let active = true;

    async function loadPlaylists() {
      setLoading(true);
      setError("");

      try {
        const res = await fetch(`${LIST_URL}?_=${Date.now()}`, {
          credentials: "include",
        });

        const data = await res.json();

        if (!res.ok || !data?.ok) {
          throw new Error(data?.error || "Failed to load playlists");
        }

        if (!active) return;
        setItems(Array.isArray(data.items) ? data.items : []);
      } catch (err) {
        if (!active) return;
        setItems([]);
        setError(err?.message || "Failed to load playlists");
      } finally {
        if (active) setLoading(false);
      }
    }

    loadPlaylists();

    return () => {
      active = false;
    };
  }, []);

  const options = useMemo(() => {
    return items
      .filter((row) => {
        if (!includeInactive && Number(row?.is_active) === 0) return false;
        if (!includePrivate && Number(row?.is_public) !== 1) return false;
        return true;
      })
      .sort((a, b) => {
        const aLabel = String(a?.title || a?.playlist_id || "");
        const bLabel = String(b?.title || b?.playlist_id || "");

        const byLabel = aLabel.localeCompare(bLabel, undefined, {
          numeric: true,
          sensitivity: "base",
        });

        if (byLabel !== 0) return byLabel;

        return Number(a?.playlist_id || 0) - Number(b?.playlist_id || 0);
      });
  }, [items, includeInactive, includePrivate]);

  return (
    <label>
      {label}

      <select
        value={value || ""}
        disabled={disabled || loading}
        onChange={(e) => {
          const next = e.target.value;
          onChange?.(next ? Number(next) : null);
        }}
      >
        <option value="">
          {loading ? "Loading playlists..." : placeholder}
        </option>

        {options.map((row) => (
          <option key={row.playlist_id} value={row.playlist_id}>
            {row.title || "Untitled"} - #{row.playlist_id}
          </option>
        ))}
      </select>

      {error ? (
        <div className="field-hint">{error}</div>
      ) : null}
    </label>
  );
}