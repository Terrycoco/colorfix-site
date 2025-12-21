import "./playerendscreen.css";

export default function PlayerEndScreen({
  showReplay = true,
  onReplay,
  children = null,
}) {
  return (
    <div className="player-end-screen">
      <div className="player-end-screen-inner">
        {showReplay && (
          <button
            type="button"
            className="player-replay-btn"
            onClick={onReplay}
          >
            ↺ Replay
          </button>
        )}

        {children && (
          <div className="player-end-actions">
            {children}
          </div>
        )}
      </div>
    </div>
  );
}
