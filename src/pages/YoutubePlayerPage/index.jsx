import { useEffect, useMemo, useState } from "react";
import { useParams, useSearchParams } from "react-router-dom";
import YoutubePlayer from "@components/YoutubePlayer";
import "./youtube-player-page.css";

export default function YoutubePlayerPage() {
  const { playlistId } = useParams();
  const [searchParams] = useSearchParams();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [activeIndex, setActiveIndex] = useState(0);
  const autoplay = searchParams.get("autoplay") !== "0";

  useEffect(() => {
    if (!playlistId) {
      setError("Missing playlist");
      setLoading(false);
      return;
    }
    let cancelled = false;
    setLoading(true);
    setError("");
    fetch(`/api/v2/youtube-video-playlist.php?playlist_id=${encodeURIComponent(playlistId)}`, {
      credentials: "include",
      headers: { Accept: "application/json" },
      cache: "no-store",
    })
      .then((response) => response.json())
      .then((payload) => {
        if (cancelled) return;
        if (!payload?.ok || !payload?.data) {
          throw new Error(payload?.error || "Failed to load YouTube playlist");
        }
        setData(payload.data);
        setActiveIndex(0);
      })
      .catch((err) => {
        if (!cancelled) setError(err?.message || "Failed to load YouTube playlist");
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [playlistId]);

  const timeline = data?.video?.timeline || [];
  const activeDuration = useMemo(() => {
    const current = timeline[activeIndex];
    return Number(current?.duration_ms || data?.video?.default_slide_duration_ms || 4200);
  }, [activeIndex, data?.video?.default_slide_duration_ms, timeline]);

  useEffect(() => {
    if (!autoplay || !data?.items?.length) return undefined;
    const timer = window.setTimeout(() => {
      setActiveIndex((prev) => (prev + 1) % data.items.length);
    }, activeDuration);
    return () => window.clearTimeout(timer);
  }, [activeDuration, autoplay, data?.items]);

  if (loading) {
    return <div className="youtube-player-page youtube-player-page--message">Loading YouTube player...</div>;
  }

  if (error) {
    return <div className="youtube-player-page youtube-player-page--message">{error}</div>;
  }

  return (
    <div className="youtube-player-page">
      <div className="youtube-player-page__frame">
        <YoutubePlayer
          slides={data?.items || []}
          activeIndex={activeIndex}
          title={data?.title || ""}
        />
      </div>
    </div>
  );
}
