import { useEffect, useState } from "react";
import { useParams, useSearchParams } from "react-router-dom";
import Player from "@components/Player";
import "./admin-player.css";

export default function AdminPlayerPage() {
  const { playlistId, start } = useParams();
  const [searchParams] = useSearchParams();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!playlistId) {
      setError("Missing playlist id");
      setLoading(false);
      return;
    }
    const startParam = start ?? searchParams.get("start") ?? "";
    const params = new URLSearchParams();
    params.set("playlist_id", playlistId);
    if (startParam !== "") params.set("start", startParam);
    setLoading(true);
    setError("");
    fetch(`/api/v2/player-playlist.php?${params.toString()}`, {
      credentials: "include",
      headers: { Accept: "application/json" },
    })
      .then((r) => r.json())
      .then((payload) => {
        if (!payload?.ok || !payload?.data) {
          throw new Error(payload?.error || "Failed to load playlist");
        }
        setData(payload.data);
      })
      .catch((err) => {
        setError(err?.message || "Failed to load playlist");
      })
      .finally(() => setLoading(false));
  }, [playlistId, start, searchParams]);

  return (
    <div className="admin-player-page">
      <div className="admin-player-header">
        <div className="admin-player-title">Player Preview</div>
        <div className="admin-player-subtitle">Playlist {playlistId || "—"}</div>
      </div>
      <div className="admin-player-stage">
        <div className="admin-player-phone">
          {loading && <div className="admin-player-status">Loading…</div>}
          {error && <div className="admin-player-status error">{error}</div>}
          {!loading && !error && (
            <Player
              items={data?.items || []}
              startIndex={data?.start_index ?? 0}
              onFinished={() => {}}
              onAbort={() => {}}
              embedded
            />
          )}
        </div>
      </div>
    </div>
  );
}
