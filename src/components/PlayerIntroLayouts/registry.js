import IntroDefault from "./IntroDefault";
import IntroText from "./IntroText";

/**
 * Intro layout registry
 *
 * Maps layout keys to renderer components.
 * This file contains NO JSX.
 */
const introLayoutRegistry = {
  default: IntroDefault,
  text: IntroText,
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
