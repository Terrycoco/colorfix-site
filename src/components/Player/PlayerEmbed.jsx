import React from "react";
import Player from "./Player";
import "./player-embed.css";

export default function PlayerEmbed({
  slides = [],
  startIndex = 0,
  embedId = "embed",
  onPlaybackEnd,
  onLikeChange,
  className = "",
}) {
  return (
    <div className={`player-embed ${className}`.trim()}>
      <Player
        slides={slides}
        startIndex={startIndex}
        playlistInstanceId={`embed:${embedId}`}
        embedded={true}
        hideStars={true}
        onPlaybackEnd={onPlaybackEnd}
        onLikeChange={onLikeChange}
      />
    </div>
  );
}
