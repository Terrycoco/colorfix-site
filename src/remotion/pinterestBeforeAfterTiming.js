/**
 * PINTEREST BEFORE / AFTER VIDEO TIMING
 *
 * Single source of truth for the Pinterest
 * Before -> After video dimensions and timing.
 *
 * Start simple. We will adjust these values after
 * watching an actual rendered video.
 */

const fps = 30;
const BEFORE= 1.5;
const DISSOLVE = 2;
const AFTER = 3;
const END = 4;


export const PINTEREST_BEFORE_AFTER_TIMING = {
  width: 1000,
  height: 1500,
  fps,

 

  beforeSeconds: BEFORE,
  dissolveSeconds: DISSOLVE,
  afterSeconds: AFTER,
  endScreenSeconds: END,

  durationInFrames: Math.round(
    (BEFORE + DISSOLVE + AFTER + END) * fps
  ),
};