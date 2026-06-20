import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { API_FOLDER, SHARE_FOLDER } from "@helpers/config";
import "./admin-playlists.css";

const LIST_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;
const GET_URL = `${API_FOLDER}/v2/admin/playlists/get.php`;
const INSTANCES_URL = `${API_FOLDER}/v2/admin/playlist-instances/list.php`;
const DELETE_URL = `${API_FOLDER}/v2/admin/playlists/delete.php`;

export default function AdminPlaylistsPage() {
  const navigate = useNavigate();
  const [query, setQuery] = useState("");
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [deletingId, setDeletingId] = useState(null);
  const [activeId, setActiveId] = useState(null);
  const [detail, setDetail] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [instances, setInstances] = useState([]);
  const [instancesLoading, setInstancesLoading] = useState(false);

  useEffect(() => {
    fetchPlaylists();
  }, []);

  useEffect(() => {
    if (!activeId) {
      setDetail(null);
      setInstances([]);
      return;
    }
    fetchPlaylistDetail(activeId);
    fetchInstances(activeId);
  }, [activeId]);

  async function fetchPlaylists(forceReload = false) {
    setLoading(true);
    setError("");
    try {
      const res = await fetch(LIST_URL, {
        credentials: "include",
        cache: forceReload ? "reload" : "default",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load playlists");
      setItems(data.items || []);
      if (!activeId && Array.isArray(data.items) && data.items[0]?.playlist_id) {
        setActiveId(data.items[0].playlist_id);
      }
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

  async function fetchInstances(playlistId) {
    if (!playlistId) {
      setInstances([]);
      return;
    }
    setInstancesLoading(true);
    try {
      const params = new URLSearchParams({
        playlist_id: String(playlistId),
        _: String(Date.now()),
      });
      const res = await fetch(`${INSTANCES_URL}?${params.toString()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load instances");
      setInstances(Array.isArray(data.items) ? data.items : []);
    } catch {
      setInstances([]);
    } finally {
      setInstancesLoading(false);
    }
  }

  async function fetchPlaylistDetail(id) {
    setDetailLoading(true);
    try {
      const res = await fetch(`${GET_URL}?playlist_id=${id}&_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load playlist");
      setDetail(data);
    } catch (err) {
      setDetail(null);
      setError(err?.message || "Failed to load playlist");
    } finally {
      setDetailLoading(false);
    }
  }

  function buildShareUrl(instance) {
    if (!instance?.playlist_instance_id) return "";
    const params = new URLSearchParams();
    params.set("id", String(instance.playlist_instance_id));
    if (instance.audience && instance.audience !== "any") {
      params.set("aud", instance.audience);
    }
    return `${SHARE_FOLDER}/playlist.php?${params.toString()}`;
  }

  function buildPlayerUrl(instance) {
    if (!instance?.playlist_instance_id) return "";
    const path = instance.player_url
      ? instance.player_url.replace(/^\/playlist\//, "/p/")
      : `/p/${instance.playlist_instance_id}`;
    const params = new URLSearchParams();
    if (instance.audience && instance.audience !== "any") {
      params.set("aud", instance.audience);
    }
    params.set("src", "admin");
    params.set("close", "1");
    params.set("return_to", "/admin/playlists");
    const qs = params.toString();
    return `${window.location.origin}${path}${qs ? `?${qs}` : ""}`;
  }

  function handleShareInstance(instance) {
    const url = buildShareUrl(instance);
    if (!url) return;
    if (navigator.share) {
      navigator.share({ title: "ColorFix Playlist", url }).catch(() => {});
      return;
    }
    if (navigator.clipboard?.writeText) {
      navigator.clipboard.writeText(url).catch(() => {});
    }
  }

  function handleEmailInstance(instance) {
    const url = buildShareUrl(instance);
    if (!url) return;
    const subject = encodeURIComponent(instance.instance_name || instance.display_title || "ColorFix Playlist");
    const body = encodeURIComponent(url);
    window.location.href = `mailto:?subject=${subject}&body=${body}`;
  }

  const filtered = useMemo(() => {
    const sorted = [...items].sort((a, b) => {
      const aLabel = String(a?.title || a?.playlist_id || "").toLowerCase();
      const bLabel = String(b?.title || b?.playlist_id || "").toLowerCase();
      const byLabel = aLabel.localeCompare(bLabel, undefined, {
        numeric: true,
        sensitivity: "base",
      });
      if (byLabel !== 0) return byLabel;
      return Number(a?.playlist_id || 0) - Number(b?.playlist_id || 0);
    });
    if (!query.trim()) return sorted;
    const needle = query.trim().toLowerCase();
    return sorted.filter((row) => {
      const title = (row?.title || "").toLowerCase();
      const id = String(row?.playlist_id || "");
      return title.includes(needle) || id.includes(needle);
    });
  }, [items, query]);

  const selectedPlaylist = useMemo(() => {
    return filtered.find((row) => row.playlist_id === activeId)
      || items.find((row) => row.playlist_id === activeId)
      || null;
  }, [activeId, filtered, items]);

  const selectedPlaylistId = selectedPlaylist?.playlist_id || "";

  const linkedInstances = useMemo(() => {
    const playlistId = Number(activeId || 0);
    if (!playlistId) return [];
    return instances
      .filter((item) => Number(item.playlist_id || 0) === playlistId)
      .sort((a, b) => {
        const aLabel = String(a?.instance_name || a?.display_title || a?.playlist_instance_id || "").toLowerCase();
        const bLabel = String(b?.instance_name || b?.display_title || b?.playlist_instance_id || "").toLowerCase();
        return aLabel.localeCompare(bLabel);
      });
  }, [activeId, instances]);

  const viewInstance = useMemo(() => (
    linkedInstances.find((instance) => Number(instance?.is_active || 0) === 1)
    || linkedInstances[0]
    || null
  ), [linkedInstances]);

  function handleViewPlaylist() {
    if (!viewInstance) return;
    window.open(buildPlayerUrl(viewInstance), "_blank", "noopener");
  }

  return (
    <div className="admin-playlists">
      <div className="playlist-panel playlist-panel--list">
        <div className="panel-header">
          <div className="panel-title">Playlists</div>
          <div className="header-actions">
            <button type="button" className="primary-btn" onClick={() => navigate("/admin/playlists/new")}>
              New Playlist
            </button>
            <button type="button" onClick={() => fetchPlaylists(true)}>
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

        <div className="playlist-picker">
          <label htmlFor="admin-playlist-select">Choose Playlist</label>
          <select
            id="admin-playlist-select"
            value={selectedPlaylistId}
            onChange={(e) => setActiveId(e.target.value ? Number(e.target.value) : null)}
          >
            <option value="">Pick a playlist...</option>
            {filtered.map((row) => (
              <option key={row.playlist_id} value={row.playlist_id}>
                {row.title || "Untitled"} - #{row.playlist_id}
              </option>
            ))}
          </select>
        </div>

        <div className="playlist-action-strip">
          <button type="button" className="primary-btn" onClick={handleViewPlaylist} disabled={!viewInstance}>
            View
          </button>
          <button
            type="button"
            onClick={() => navigate(`/admin/playlists/${selectedPlaylist.playlist_id}`)}
            disabled={!selectedPlaylist}
          >
            Edit
          </button>
          <button
            type="button"
            className="danger-btn"
            onClick={() => handleDelete(selectedPlaylist)}
            disabled={!selectedPlaylist || deletingId === selectedPlaylist.playlist_id}
          >
            {selectedPlaylist && deletingId === selectedPlaylist.playlist_id ? "Deleting..." : "Delete"}
          </button>
          {selectedPlaylist ? (
            <div className="playlist-action-strip__meta">
              #{selectedPlaylist.playlist_id}
              {" · "}
              {selectedPlaylist.type || "Untyped"}
              {" · "}
              {Number(selectedPlaylist.is_active) !== 0 ? "Active" : "Inactive"}
              {" · "}
              {Number(selectedPlaylist.is_public) === 1 ? "Public" : "Private"}
            </div>
          ) : (
            <div className="playlist-action-strip__meta">Choose a playlist to use the actions.</div>
          )}
        </div>

        <div className="playlist-mobile-card">
          <div className="playlist-mobile-card__eyebrow">Selected Playlist</div>
          <div className="playlist-mobile-card__title">
            {selectedPlaylist?.title || "Pick a playlist"}
          </div>

          {selectedPlaylist ? (
            <>
              <div className="playlist-mobile-card__meta">
                <div>#{selectedPlaylist.playlist_id}</div>
                <div>{selectedPlaylist.type || "Untyped"}</div>
                <div>{Number(selectedPlaylist.is_active) !== 0 ? "Active" : "Inactive"}</div>
                <div>{Number(selectedPlaylist.is_public) === 1 ? "Public" : "Private"}</div>
                <div>{detail?.items?.length ?? 0} items</div>
              </div>

              <div className="playlist-mobile-card__actions">
                <button type="button" className="primary-btn" onClick={handleViewPlaylist} disabled={!viewInstance}>
                  View
                </button>
                <button type="button" onClick={() => navigate(`/admin/playlists/${selectedPlaylist.playlist_id}`)}>
                  Edit
                </button>
              </div>

              <div className="playlist-mobile-card__section">
                <div className="playlist-mobile-card__label">Instances</div>
                {detailLoading || instancesLoading ? (
                  <div className="panel-status">Loading details…</div>
                ) : linkedInstances.length === 0 ? (
                  <div className="playlist-mobile-card__empty">No playlist instances are attached to this playlist yet.</div>
                ) : (
                  <div className="playlist-mobile-card__instances">
                    {linkedInstances.map((instance) => (
                      <div key={instance.playlist_instance_id} className="playlist-mobile-card__instance">
                        <div className="playlist-mobile-card__instance-title">
                          #{instance.playlist_instance_id} {instance.instance_name || "Untitled"}
                        </div>
                        {instance.display_title ? (
                          <div className="playlist-mobile-card__instance-subtitle">{instance.display_title}</div>
                        ) : null}
                        <div className="playlist-mobile-card__instance-actions">
                          <button
                            type="button"
                            className="primary-btn"
                            onClick={() => window.open(buildPlayerUrl(instance), "_blank", "noopener")}
                          >
                            Play
                          </button>
                          <button type="button" onClick={() => handleShareInstance(instance)}>
                            Share
                          </button>
                          <button type="button" onClick={() => handleEmailInstance(instance)}>
                            Email
                          </button>
                          <button type="button" onClick={() => navigate("/admin/playlist-instances")}>
                            Instance
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </>
          ) : (
            <div className="playlist-mobile-card__empty">
              Tap a playlist to see its details and the instances you can play or share from your phone.
            </div>
          )}
        </div>
      </div>

      <aside className="playlist-panel playlist-panel--detail">
        <div className="playlist-desktop-card">
          <div className="playlist-mobile-card__eyebrow">Selected Playlist</div>
          <div className="playlist-mobile-card__title">
            {selectedPlaylist?.title || "Pick a playlist"}
          </div>

          {selectedPlaylist ? (
            <>
              <div className="playlist-mobile-card__meta">
                <div>#{selectedPlaylist.playlist_id}</div>
                <div>{selectedPlaylist.type || "Untyped"}</div>
                <div>{Number(selectedPlaylist.is_active) !== 0 ? "Active" : "Inactive"}</div>
                <div>{Number(selectedPlaylist.is_public) === 1 ? "Public" : "Private"}</div>
                <div>{detail?.items?.length ?? 0} items</div>
              </div>

              <div className="playlist-mobile-card__actions">
                <button type="button" className="primary-btn" onClick={handleViewPlaylist} disabled={!viewInstance}>
                  View
                </button>
                <button type="button" onClick={() => navigate(`/admin/playlists/${selectedPlaylist.playlist_id}`)}>
                  Edit
                </button>
              </div>

              <div className="playlist-mobile-card__section">
                <div className="playlist-mobile-card__label">Instances</div>
                {detailLoading || instancesLoading ? (
                  <div className="panel-status">Loading details…</div>
                ) : linkedInstances.length === 0 ? (
                  <div className="playlist-mobile-card__empty">No playlist instances are attached to this playlist yet.</div>
                ) : (
                  <div className="playlist-mobile-card__instances">
                    {linkedInstances.map((instance) => (
                      <div key={instance.playlist_instance_id} className="playlist-mobile-card__instance">
                        <div className="playlist-mobile-card__instance-title">
                          #{instance.playlist_instance_id} {instance.instance_name || "Untitled"}
                        </div>
                        {instance.display_title ? (
                          <div className="playlist-mobile-card__instance-subtitle">{instance.display_title}</div>
                        ) : null}
                        <div className="playlist-mobile-card__instance-actions">
                          <button
                            type="button"
                            className="primary-btn"
                            onClick={() => window.open(buildPlayerUrl(instance), "_blank", "noopener")}
                          >
                            Play
                          </button>
                          <button type="button" onClick={() => handleShareInstance(instance)}>
                            Share
                          </button>
                          <button type="button" onClick={() => handleEmailInstance(instance)}>
                            Email
                          </button>
                          <button type="button" onClick={() => navigate("/admin/playlist-instances")}>
                            Instance
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            </>
          ) : (
            <div className="playlist-mobile-card__empty">
              Select a playlist to see its actions and linked instances here.
            </div>
          )}
        </div>
      </aside>
    </div>
  );
}
