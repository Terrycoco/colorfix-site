import React from "react";

import {
  interpolate,
  useCurrentFrame,
} from "remotion";

import ColorWheel300
  from "../ColorWheel/ColorWheel300.jsx";


const CENTER = 150;


/**
 * REMOTION HUE-WHEEL SUPPORT COMPONENT
 *
 * Renderer-only equipment used by GenericVideo.
 *
 * The static wheel does not animate. The scene title/subtitle and wheel
 * fade in together. Each authored spoke then draws outward according to
 * frame offsets already translated by VideoLayerBuilder.
 *
 * This component contains no YouTube defaults. All presentation values
 * arrive as props from the Chef blueprint through the translator.
 */
export default function HueWheelVideo({
  sceneStartFrame = 0,
  wheelFadeFrames = 0,
  size = 520,
  spokeWidth = 4.5,
  spokes = [],
  title = "",
  subtitle = "",
  textColor = "#ffffff",
  fontFamily = "Helvetica, Arial, sans-serif",
  textMaxWidth = 1400,
  textGap = 12,
  contentGap = 28,
  titleFontSize = 64,
  titleFontWeight = 600,
  subtitleFontSize = 38,
  subtitleFontWeight = 400,
}) {
  const frame =
    useCurrentFrame();

  const localFrame =
    Math.max(
      0,
      frame
      - finiteNumber(
          sceneStartFrame,
          0
        )
    );


  const fadeFrames =
    Math.max(
      0,
      Math.round(
        finiteNumber(
          wheelFadeFrames,
          0
        )
      )
    );


  const revealOpacity =
    fadeFrames <= 0
      ? 1
      : interpolate(
          localFrame,
          [
            0,
            fadeFrames,
          ],
          [
            0,
            1,
          ],
          {
            extrapolateLeft:
              "clamp",

            extrapolateRight:
              "clamp",
          }
        );


  const normalizedSpokes =
    Array.isArray(
      spokes
    )
      ? spokes
      : [];


  return (
    <div
      style={{
        width:
          "100%",

        height:
          "100%",

        display:
          "flex",

        flexDirection:
          "column",

        alignItems:
          "center",

        justifyContent:
          "center",

        gap:
          positiveNumber(
            contentGap,
            0
          ),

        boxSizing:
          "border-box",

        color:
          textColor,

        fontFamily,

        opacity:
          revealOpacity,
      }}
    >
      {
        (
          String(
            title || ""
          )
            .trim()
          ||
          String(
            subtitle || ""
          )
            .trim()
        ) && (
          <div
            style={{
              width:
                "100%",

              maxWidth:
                positiveNumber(
                  textMaxWidth,
                  1400
                ),

              display:
                "flex",

              flexDirection:
                "column",

              alignItems:
                "center",

              gap:
                positiveNumber(
                  textGap,
                  0
                ),

              textAlign:
                "center",

              lineHeight:
                1.16,
            }}
          >
            {
              String(
                title || ""
              )
                .trim() && (
                <div
                  style={{
                    fontSize:
                      positiveNumber(
                        titleFontSize,
                        64
                      ),

                    fontWeight:
                      positiveNumber(
                        titleFontWeight,
                        600
                      ),
                  }}
                >
                  {
                    String(
                      title
                    )
                  }
                </div>
              )
            }

            {
              String(
                subtitle || ""
              )
                .trim() && (
                <div
                  style={{
                    fontSize:
                      positiveNumber(
                        subtitleFontSize,
                        38
                      ),

                    fontWeight:
                      positiveNumber(
                        subtitleFontWeight,
                        400
                      ),
                  }}
                >
                  {
                    String(
                      subtitle
                    )
                  }
                </div>
              )
            }
          </div>
        )
      }

      <ColorWheel300
        size={
          positiveNumber(
            size,
            520
          )
        }
      >
        {normalizedSpokes.map(
          (
            spoke,
            index
          ) => (
            <HueSpoke
              key={
                `${
                  spoke?.hue ?? "hue"
                }-${
                  spoke?.color ?? "color"
                }-${index}`
              }

              spoke={
                spoke
              }

              localFrame={
                localFrame
              }

              spokeWidth={
                spokeWidth
              }
            />
          )
        )}
      </ColorWheel300>
    </div>
  );
}


function HueSpoke({
  spoke,
  localFrame,
  spokeWidth,
}) {
  const hue =
    normalizeHue(
      finiteNumber(
        spoke?.hue,
        0
      )
    );

  const color =
    String(
      spoke?.color ||
      "#111111"
    );

  const animate =
    spoke?.animate !==
      false;

  const startRadius =
    Math.max(
      0,
      finiteNumber(
        spoke?.start_radius,
        0
      )
    );

  const endRadius =
    Math.max(
      startRadius,
      finiteNumber(
        spoke?.end_radius,
        startRadius
      )
    );


  const startFrameOffset =
    Math.max(
      0,
      Math.round(
        finiteNumber(
          spoke?.start_frame_offset,
          0
        )
      )
    );

  const durationFrames =
    Math.max(
      0,
      Math.round(
        finiteNumber(
          spoke?.duration_frames,
          0
        )
      )
    );


  let progress = 1;


  if (animate) {
    if (
      durationFrames <= 0
    ) {
      progress =
        localFrame >=
          startFrameOffset
          ? 1
          : 0;

    } else {
      progress =
        interpolate(
          localFrame,
          [
            startFrameOffset,
            startFrameOffset
              + durationFrames,
          ],
          [
            0,
            1,
          ],
          {
            extrapolateLeft:
              "clamp",

            extrapolateRight:
              "clamp",
          }
        );
    }
  }


  const radius =
    startRadius
    + (
      (
        endRadius
        - startRadius
      )
      * progress
    );


  const start =
    pointAtHue(
      hue,
      startRadius
    );

  const end =
    pointAtHue(
      hue,
      radius
    );


  return (
    <line
      x1={
        start.x
      }

      y1={
        start.y
      }

      x2={
        end.x
      }

      y2={
        end.y
      }

      stroke={
        color
      }

      strokeWidth={
        positiveNumber(
          spokeWidth,
          4.5
        )
      }

      strokeLinecap="round"
    />
  );
}


function pointAtHue(
  hue,
  radius
) {
  const angle =
    (
      (
        hue
        - 90
      )
      * Math.PI
    )
    / 180;


  return {
    x:
      Number(
        (
          CENTER
          + radius
            * Math.cos(
              angle
            )
        )
          .toFixed(
            3
          )
      ),

    y:
      Number(
        (
          CENTER
          + radius
            * Math.sin(
              angle
            )
        )
          .toFixed(
            3
          )
      ),
  };
}


function normalizeHue(
  hue
) {
  return (
    (
      hue % 360
    )
    + 360
  )
  % 360;
}


function finiteNumber(
  value,
  fallback
) {
  const number =
    Number(
      value
    );


  return Number.isFinite(
    number
  )
    ? number
    : fallback;
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
    && number >= 0
  )
    ? number
    : fallback;
}
