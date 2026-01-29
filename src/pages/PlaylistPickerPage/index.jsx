import { useEffect, useMemo, useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import "@pages/PlaylistThumbsPage/playlist-thumbs.css";
import "./playlist-picker.css";

const SET_URL = "/api/v2/playlist-instance-sets/get.php";

export default function PlaylistPickerPage() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const setId = Number(searchParams.get("psi") || 0);
  const ctaAudience = searchParams.get("aud") ?? "";
  const addCtaGroup = searchParams.get("add_cta_group") ?? "";
  const demoParam = searchParams.get("demo") ?? "";
  const isHoaView = ctaAudience.toLowerCase() === "hoa";

  const [title, setTitle] = useState("");
  const [subtitle, setSubtitle] = useState("");
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!setId) {
      setError("Missing playlist set id");
      setLoading(false);
      return;
    }
    setLoading(true);
    setError("");
    fetch(`${SET_URL}?id=${setId}`, { headers: { Accept: "application/json" } })
      .then((r) => r.json())
      .then((payload) => {
        if (!payload?.ok || !payload?.set) {
          throw new Error(payload?.error || "Failed to load playlist set");
        }
        setTitle(formatTitle(payload.set.title || "Choose a playlist"));
        setSubtitle(formatTitle(payload.set.subtitle || ""));
        setItems(Array.isArray(payload.set.items) ? payload.set.items : []);
      })
      .catch((err) => {
        setError(err?.message || "Failed to load playlist set");
      })
      .finally(() => setLoading(false));
  }, [setId]);

  const tiles = useMemo(() => {
    return (items || []).map((item) => ({
      id: item.id,
      playlist_instance_id: item.playlist_instance_id,
      item_type: item.item_type || "instance",
      target_set_id: item.target_set_id,
      title: formatTitle(item.title || ""),
      photo_url: item.photo_url || "",
    }));
  }, [items]);

  const buildPlaylistUrl = (playlistInstanceId) => {
    const params = new URLSearchParams();
    if (addCtaGroup !== "") params.set("add_cta_group", addCtaGroup);
    if (ctaAudience !== "") params.set("aud", ctaAudience);
    if (demoParam !== "") params.set("demo", demoParam);
    if (setId) params.set("psi", String(setId));
    const qs = params.toString();
    return `/playlist/${playlistInstanceId}${qs ? `?${qs}` : ""}`;
  };

  const buildSetUrl = (targetSetId) => {
    if (!targetSetId) return "#";
    const params = new URLSearchParams();
    if (addCtaGroup !== "") params.set("add_cta_group", addCtaGroup);
    if (ctaAudience !== "") params.set("aud", ctaAudience);
    if (demoParam !== "") params.set("demo", demoParam);
    params.set("psi", String(targetSetId));
    const qs = params.toString();
    return `/picker${qs ? `?${qs}` : ""}`;
  };

  const handleExit = () => {
    if (isHoaView) {
      navigate("/hoa");
      return;
    }
    if (window.history.length > 1) {
      navigate(-1);
      return;
    }
    navigate("/");
  };

  if (loading) return <div className="playlist-thumbs__status">Loading…</div>;
  if (error) return <div className="playlist-thumbs__status error">{error}</div>;

  return (
    <div className="playlist-thumbs playlist-thumbs--end playlist-picker">
      <button
        type="button"
        className="playlist-thumbs__exit"
        onClick={handleExit}
        aria-label="Exit picker"
      >
        ×
      </button>
      <div className="playlist-thumbs__panel">
        <header className="playlist-thumbs__header">
          <div className="playlist-thumbs__header-row">
            <div>
              <h1>{title}</h1>
              {subtitle && <p>{subtitle}</p>}
            </div>
          </div>
        </header>
        {!tiles.length && (
          <div className="playlist-thumbs__status">No models found in this set.</div>
        )}
        <div className="playlist-thumbs__grid-wrap">
          <div className="playlist-thumbs__grid">
            {tiles.map((tile) => (
              <a
                key={tile.id ?? `${tile.item_type}-${tile.playlist_instance_id || tile.target_set_id}`}
                className="playlist-thumbs__card"
                href={
                  tile.item_type === "set"
                    ? buildSetUrl(tile.target_set_id || "")
                    : buildPlaylistUrl(tile.playlist_instance_id)
                }
              >
                <div className="playlist-thumbs__image">
                  {tile.photo_url ? (
                    <img src={tile.photo_url} alt={tile.title} loading="lazy" />
                  ) : (
                    <div className="playlist-thumbs__placeholder">No Image</div>
                  )}
                </div>
                <div className="playlist-thumbs__title">{tile.title}</div>
              </a>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

function formatTitle(value) {
  return String(value || "").replace(/\s*--\s*/g, " — ").trim();
}
