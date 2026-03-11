import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import "./admin-playlists.css";

const LIST_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;
const DELETE_URL = `${API_FOLDER}/v2/admin/playlists/delete.php`;

export default function AdminPlaylistsPage() {
  const navigate = useNavigate();
  const [query, setQuery] = useState("");
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [deletingId, setDeletingId] = useState(null);

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

  async function handleDelete(row) {
    if (!row?.playlist_id) return;
    if (!window.confirm(`Delete playlist #${row.playlist_id}?`)) return;
    setError("");
    setDeletingId(row.playlist_id);
    try {
      const res = await fetch(DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ playlist_id: row.playlist_id }),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Delete failed");
      setItems((prev) => prev.filter((item) => item.playlist_id !== row.playlist_id));
    } catch (err) {
      setError(err?.message || "Delete failed");
    } finally {
      setDeletingId(null);
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
            <div
              key={row.playlist_id}
              className="list-row"
            >
              <button
                type="button"
                className="list-row-main"
                onClick={() => navigate(`/admin/playlists/${row.playlist_id}`)}
              >
                <div className="row-title">{row.title || "Untitled"}</div>
                <div className="row-meta">
                  #{row.playlist_id} • {row.type} • {row.is_active ? "Active" : "Inactive"}
                </div>
              </button>
              <button
                type="button"
                className="list-row-delete"
                onClick={() => handleDelete(row)}
                disabled={deletingId === row.playlist_id}
              >
                {deletingId === row.playlist_id ? "Deleting..." : "Delete"}
              </button>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
