import React from 'react';
import "./introdefault.css";

/**
 * IntroDefault
 *
 * Presentation-only renderer for intro playlist items.
 * - No player logic
 * - No advance logic
 * - No star logic
 * - No state
 */
export default function IntroDefault({ item }) {
  const {
    title,
    subtitle,
    body,
  } = item || {};

  return (
    <div className="intro-default">
      {title && <div className="player-title-text">{title}</div>}
      {subtitle && <div className="player-subtitle-text">{subtitle}</div>}
      {body && <div className="intro-default__body">{body}</div>}
      <div className="intro-default__hint">Tap screen to begin</div>
    </div>
  );
}
