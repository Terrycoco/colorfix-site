import { useEffect, useState } from "react";
import { useLocation, useParams, useSearchParams } from "react-router-dom";
import PlayerExperience from "@components/PlayerExperience";
import { applySourceToParams } from "@helpers/sourceParam";
import colorfixLogoUrl from "../../assets/brand/colorfix_lightbg.png";
import "./playerpage.css";

export default function PlayerPage() {
  const { playlistId, start, token: routeToken } = useParams();
  const [searchParams] = useSearchParams();
  const location = useLocation();

  const addCtaGroup = searchParams.get("add_cta_group") ?? "";
  const ctaAudience = searchParams.get("aud") ?? "";
  const sourceParam = searchParams.get("src") ?? "";
  const reservationTokenParam = location.pathname.startsWith("/t/") && routeToken
    ? routeToken
    : searchParams.get("reservation_token") ?? searchParams.get("token") ?? "";
  const freshParam = searchParams.get("fresh") ?? "";
  const reloadParam = searchParams.get("_") ?? "";
  const returnToParam = searchParams.get("return_to") ?? "";
  const debugTimingParam = searchParams.get("debug_timing") ?? "";
  const offsetParam = searchParams.get("offset") ?? "";
  const positionParam = searchParams.get("position") ?? searchParams.get("pos") ?? "";
  const slideIdParam = searchParams.get("slide_id") ?? searchParams.get("playlist_item_id") ?? searchParams.get("item_id") ?? "";
  const photoIdParam = searchParams.get("photo_id") ?? searchParams.get("photo_library_id") ?? "";
  const returnTo = resolveReturnTo(returnToParam);
  const startParamValue = start ?? searchParams.get("start") ?? "";

  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [errorCode, setErrorCode] = useState("");

  useEffect(() => {
    if (!playlistId && !reservationTokenParam) {
      setError("Playlist unavailable");
      setErrorCode("playlist_unavailable");
      setLoading(false);
      return;
    }

    const params = new URLSearchParams();

    if (reservationTokenParam) {
      params.set("reservation_token", reservationTokenParam);
    } else if (isNumericId(playlistId)) {
      params.set("playlist_instance_id", playlistId);
    } else {
      params.set("playlist_slug", playlistId);
    }

    if (startParamValue !== "") params.set("start", startParamValue);
    if (offsetParam !== "") params.set("offset", offsetParam);
    if (positionParam !== "") params.set("position", positionParam);
    if (slideIdParam !== "") params.set("slide_id", slideIdParam);
    if (photoIdParam !== "") params.set("photo_id", photoIdParam);
    if (addCtaGroup !== "") params.set("add_cta_group", addCtaGroup);
    if (ctaAudience !== "") params.set("aud", ctaAudience);
    if (returnTo !== "") params.set("return_to", returnTo);

    applySourceToParams(params, sourceParam);

    if (debugTimingParam !== "") params.set("debug_timing", debugTimingParam);
    if (freshParam !== "") params.set("fresh", freshParam);
    if (reloadParam !== "") params.set("_", reloadParam);

    let cancelled = false;

    setLoading(true);
    setError("");
    setErrorCode("");

    fetchPlayerPlaylist(`/api/v2/player-playlist.php?${params.toString()}`)
      .then((payload) => {
        if (cancelled) return;

        if (!payload?.ok || !payload?.data) {
          throw new Error(payload?.error || "Failed to load playlist");
        }

        if (payload?.timing_ms && typeof window !== "undefined") {
          console.log("player-playlist timing_ms", payload.timing_ms);
        }

        setData(payload.data);
      })
      .catch((err) => {
        if (cancelled) return;

        setError(err?.message || "Failed to load playlist");
        setErrorCode(err?.code || "");
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [
    playlistId,
    reservationTokenParam,
    startParamValue,
    offsetParam,
    positionParam,
    slideIdParam,
    photoIdParam,
    addCtaGroup,
    ctaAudience,
    returnTo,
    sourceParam,
    debugTimingParam,
    freshParam,
    reloadParam,
  ]);

  if (loading) {
    return (
      <div className="player-page">
        <PlayerLoadingIndicator label="Loading playlist" />
      </div>
    );
  }

  if (error) {
    if (errorCode === "playlist_unavailable") {
      return <PlaylistUnavailable />;
    }

    return (
      <div className="player-error" role="alert">
        <div className="player-error__panel">
          <div className="player-error__title">Playlist could not load</div>
          <div className="player-error__message">{error}</div>
          <button type="button" onClick={() => window.location.reload()}>
            Try again
          </button>
        </div>
      </div>
    );
  }

  return <PlayerExperience data={data} />;
}

function PlaylistUnavailable() {
  return (
    <main className="playlist-unavailable" role="alert">
      <section className="playlist-unavailable__panel" aria-labelledby="playlist-unavailable-title">
        <img className="playlist-unavailable__logo" src={colorfixLogoUrl} alt="ColorFix" />
        <h1 id="playlist-unavailable-title">This playlist isn&rsquo;t available.</h1>
        <p>It may have been moved or taken offline.</p>
        <div className="playlist-unavailable__actions">
          <a href="/picker?psi=11">Browse Playlists</a>
          <a href="/results/4">Go to ColorFix Home</a>
        </div>
      </section>
    </main>
  );
}

function PlayerLoadingIndicator({ label = "Loading" }) {
  return (
    <div className="player-page-loading" role="status" aria-live="polite" aria-label={label}>
      <span className="player-page-loading__dot" />
      <span className="player-page-loading__dot" />
      <span className="player-page-loading__dot" />
    </div>
  );
}

async function fetchPlayerPlaylist(url) {
  const options = {
    credentials: "include",
    headers: { Accept: "application/json" },
  };

  try {
    return await fetchJsonWithTimeout(url, options, 8000);
  } catch (err) {
    if (!isRetryablePlaylistError(err)) throw err;
    return fetchJsonWithTimeout(url, { ...options, cache: "reload" }, 8000);
  }
}

async function fetchJsonWithTimeout(url, options, timeoutMs) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const response = await fetch(url, { ...options, signal: controller.signal });
    const text = await response.text();

    let payload = null;

    try {
      payload = text ? JSON.parse(text) : null;
    } catch {
      throw new Error("Playlist returned a server page instead of data. Please try again.");
    }

    if (!response.ok) {
      const error = new Error(payload?.error || `Playlist request failed (${response.status})`);
      error.status = response.status;
      error.code = payload?.code || "";
      throw error;
    }

    return payload;
  } finally {
    clearTimeout(timer);
  }
}

function isTimeoutError(err) {
  return err?.name === "AbortError";
}

function isRetryablePlaylistError(err) {
  if (isTimeoutError(err)) return true;

  const message = String(err?.message || "").toLowerCase();

  return message.includes("server page")
    || message.includes("failed (5")
    || message.includes("network");
}

function resolveReturnTo(value) {
  if (!value) return "";

  const trimmed = String(value).trim();

  if (!trimmed.startsWith("/")) return "";
  if (trimmed.startsWith("//")) return "";

  return trimmed;
}

function isNumericId(value) {
  return /^[0-9]+$/.test(String(value || "").trim());
}
