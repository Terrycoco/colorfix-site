import PlayerExperience from "@components/PlayerExperience";

export default function RexPlayer({ playbackPlan }) {
  if (!playbackPlan) {
    return (
      <div className="player-error" role="alert">
        <div className="player-error__panel">
          <div className="player-error__title">Playlist could not load</div>
          <div className="player-error__message">
            REX did not provide a playback plan.
          </div>
        </div>
      </div>
    );
  }

  return <PlayerExperience data={playbackPlan} />;
}
