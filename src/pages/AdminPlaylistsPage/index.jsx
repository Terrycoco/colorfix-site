import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import "./admin-playlists.css";

const LIST_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;

export default function AdminPlaylistsPage() {
  const navigate = useNavigate();
  const [query, setQuery] = useState("");
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    fetchPlaylists();
  }, []);

  async function fetchPlaylists() {
    setLoading(true);
    setError("");
    try {
      const res = await fetch(`${LIST_URL}?_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load playlists");
      setItems(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load playlists");
    } finally {
      setLoading(false);
    }
  }

  const filtered = useMemo(() => {
    if (!query.trim()) return items;
    const needle = query.trim().toLowerCase();
    return items.filter((row) => {
      const title = (row?.title || "").toLowerCase();
      const id = String(row?.playlist_id || "");
      return title.includes(needle) || id.includes(needle);
    });
  }, [items, query]);

  return (
    <div className="admin-playlists">
      <div className="playlist-panel">
        <div className="panel-header">
          <div className="panel-title">Playlists</div>
          <div className="header-actions">
            <button type="button" className="primary-btn" onClick={() => navigate("/admin/playlists/new")}>
              New Playlist
            </button>
            <button type="button" onClick={fetchPlaylists}>
              Refresh
            </button>
          </div>
        </div>

        <div className="panel-controls">
          <input
            type="text"
            placeholder="Search by id or title"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
        </div>

        {loading && <div className="panel-status">Loading…</div>}
        {error && <div className="panel-status error">{error}</div>}

        <div className="panel-list">
          {filtered.map((row) => (
            <button
              key={row.playlist_id}
              type="button"
              className="list-row"
              onClick={() => navigate(`/admin/playlists/${row.playlist_id}`)}
            >
              <div className="row-title">{row.title || "Untitled"}</div>
              <div className="row-meta">
                #{row.playlist_id} • {row.type} • {row.is_active ? "Active" : "Inactive"}
              </div>
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}
