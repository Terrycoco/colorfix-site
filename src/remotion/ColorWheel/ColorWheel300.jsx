import React from "react";
import { Img } from "remotion";

import wheel300Labels
  from "./wheel-300-labels.svg";


/**
 * REMOTION COLOR WHEEL PRIMITIVE
 *
 * Deterministic video-only copy of the site's ColorWheel300 shell.
 *
 * The base wheel is static artwork stored beside this component.
 * Children are rendered into the established 300 x 300 overlay
 * coordinate system used by the site's animated spokes.
 *
 * No useEffect, async fallback import, or browser error recovery.
 */
export default function ColorWheel300({
  children,
  size = 300,
}) {
  const resolvedSize =
    positiveNumber(
      size,
      300
    );


  return (
    <div
      style={{
        position:
          "relative",

        width:
          resolvedSize,

        height:
          resolvedSize,

        flex:
          "0 0 auto",

        overflow:
          "visible",
      }}
    >
      <Img
        src={
          wheel300Labels
        }

        style={{
          position:
            "absolute",

          inset:
            0,

          width:
            "100%",

          height:
            "100%",

          display:
            "block",
        }}
      />

      <svg
        width="100%"
        height="100%"
        viewBox="0 0 300 300"
        aria-hidden="true"

        style={{
          position:
            "absolute",

          inset:
            0,

          overflow:
            "visible",
        }}
      >
        {children}
      </svg>
    </div>
  );
}


function positiveNumber(
  value,
  fallback
) {
  const number =
    Number(
      value
    );


  return (
    Number.isFinite(
      number
    )
    && number > 0
  )
    ? number
    : fallback;
}
