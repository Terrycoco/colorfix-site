import React from 'react';

/**
 * Intro layout registry
 *
 * Maps layout keys to lazy-loaded renderer components.
 * This file contains NO JSX.
 */

const introLayoutRegistry = {
  default: React.lazy(() => import('./IntroDefault')),
  text: React.lazy(() => import('./IntroText')),
};

/**
 * Resolve an intro layout renderer by key.
 * Falls back to the default layout safely.
 */
export function getIntroLayout(layoutKey) {
  if (!layoutKey) {
    return introLayoutRegistry.default;
  }

  return introLayoutRegistry[layoutKey] || introLayoutRegistry.default;
}
