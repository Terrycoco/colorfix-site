import "./playerendscreen.css";

export default function PlayerEndScreen({
  children = null,
  showBranding = true,
  onExit,
}) {
  return (
    <div className="player-end-screen">
      {onExit && (
        <button
          className="player-exit player-exit--end"
          type="button"
          onClick={onExit}
          aria-label="Exit player"
        >
          ×
        </button>
      )}
      <div className="player-end-screen-inner">
        {children && (
          <div className="player-end-actions">
            {children}
          </div>
        )}
      </div>

      {showBranding && (
        <div className="player-end-branding">
          Brought to you by <strong>ColorFix</strong>
        </div>
      )}
    </div>
  );
}
