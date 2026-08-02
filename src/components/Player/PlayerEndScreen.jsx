import "./playerendscreen.css";
import BrandFooterLogo from "@components/BrandFooterLogo";

export default function PlayerEndScreen({
  children = null,
  showBranding = true,
  scrollable = false,
}) {
  const hasActions = Boolean(children);
  const canScroll = hasActions && scrollable;

  return (
    <div className={`player-end-screen${canScroll ? " has-actions" : " is-empty"}`}>
      <div className={`player-end-screen-inner${canScroll ? " has-actions" : " is-empty"}`}>
        {hasActions && (
          <div className="player-end-actions">
            {children}
          </div>
        )}
      </div>

      {showBranding && (
        <div className="player-end-footer">
          <div className="player-end-branding">
            <BrandFooterLogo />
          </div>
        </div>
      )}
    </div>
  );
}
