import { useEffect, useState } from "react";
import { useNavigate, useParams, useSearchParams } from "react-router-dom";
import Player from "@components/Player";

export default function PlayerPage() {
  const { playlistId, start } = useParams();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();

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

  function handleExit() {
    if (window.history.length > 1) {
      navigate(-1);
    } else {
      navigate("/");
    }
  }

  if (loading) return null;
  if (error) return <div className="player-error">{error}</div>;

  return (
    <Player
      items={data?.items || []}
      startIndex={data?.start_index ?? 0}
      onFinished={handleExit}
      onAbort={handleExit}
    />
  );
}
