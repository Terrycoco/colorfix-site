import React from "react";
import BrandBumperLogo from "./BrandBumperLogo/index.jsx";
import {
  AbsoluteFill,
  Img,
  interpolate,
  useCurrentFrame,
} from "remotion";

import {
  PINTEREST_BEFORE_AFTER_TIMING,
} from "./pinterestBeforeAfterTiming.js";

export function PinterestBeforeAfterVideo({ plan }) {
  const frame = useCurrentFrame();

  const {
    fps,
    beforeSeconds,
    dissolveSeconds,
    afterSeconds,
    endScreenSeconds
  } = PINTEREST_BEFORE_AFTER_TIMING;

  const beforeUrl = String(
    plan?.before?.image_url || ""
  ).trim();

  const afterUrl = String(
    plan?.after?.image_url || ""
  ).trim();

  const searchTitle = String(
    plan?.search_title || "Exterior Color Ideas"
  ).trim();

  const ctaText = String(
    plan?.cta_text || "See More Transformations"
  ).trim();

  const dissolveStart =
    Math.round(beforeSeconds * fps);

  const dissolveEnd =
    dissolveStart +
    Math.round(dissolveSeconds * fps);

  const endScreenStart =
    dissolveEnd +
    Math.round(afterSeconds * fps);

  const afterOpacity = interpolate(
    frame,
    [dissolveStart, dissolveEnd],
    [0, 1],
    {
      extrapolateLeft: "clamp",
      extrapolateRight: "clamp",
    }
  );

  const endScreenOpacity = interpolate(
    frame,
    [
      endScreenStart,
      endScreenStart + Math.round(0.5 * fps),
    ],
    [0, 1],
    {
      extrapolateLeft: "clamp",
      extrapolateRight: "clamp",
    }
  );

const totalFrames = Math.round(
  (
    beforeSeconds +
    dissolveSeconds +
    afterSeconds +
    endScreenSeconds
  ) * fps
);

const fadeOutFrames = Math.round(0.5 * fps);

const finalFadeOpacity = interpolate(
  frame,
  [
    totalFrames - fadeOutFrames,
    totalFrames - 1,
  ],
  [0, 1],
  {
    extrapolateLeft: "clamp",
    extrapolateRight: "clamp",
  }
);

  const logoProgress = interpolate(
      frame,
      [
        endScreenStart + Math.round(0.35 * fps),
        endScreenStart + Math.round(1.5 * fps),
      ],
      [0, 1],
      {
        extrapolateLeft: "clamp",
        extrapolateRight: "clamp",
      }
    );

  const phaseLabel =
    frame < dissolveEnd
      ? "BEFORE"
      : "AFTER";

  return (
    <AbsoluteFill
      style={{
        backgroundColor: "#000000",
        color: "#ffffff",
        fontFamily: "Arial, sans-serif",
      }}
    >
      {/* MAIN VIDEO LAYOUT */}
      <AbsoluteFill
        style={{
          opacity: 1 - endScreenOpacity,
          display: "grid",
          gridTemplateRows: "130px 700px 100px",
          alignContent: "center",
        }}
      >
        {/* TOP TITLE BAND */}
        <div
          style={{
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            padding: "10px 60px 12px",
            fontSize: 48,
            textAlign: "center",
            fontWeight: 700,
            lineHeight: 1.08,
          }}
        >
          {searchTitle}
        </div>

        {/* IMAGE AREA */}
        <div
          style={{
            position: "relative",
            minHeight: 0,
            overflow: "hidden",
            backgroundColor: "#111",
          }}
        >
          <AbsoluteFill>
            <Img
              src={beforeUrl}
              style={{
                width: "100%",
                height: "100%",
                objectFit: "contain",
              }}
            />
          </AbsoluteFill>

          <AbsoluteFill
            style={{
              opacity: afterOpacity,
            }}
          >
            <Img
              src={afterUrl}
              style={{
                width: "100%",
                height: "100%",
                objectFit: "contain",
              }}
            />
          </AbsoluteFill>
        </div>

        {/* BOTTOM BEFORE / AFTER BAND */}
        <div
          style={{
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            fontSize: 40,
            fontWeight: 800,
            letterSpacing: 5,
          }}
        >
          {phaseLabel}
        </div>
      </AbsoluteFill>

      {/* END SCREEN */}
      <AbsoluteFill
        style={{
          opacity: endScreenOpacity,
          backgroundColor: "#000000",
          color: "#ffffff",
          display: "flex",
          flexDirection: "column",
          alignItems: "center",
          justifyContent: "center",
          padding: 80,
          textAlign: "center",
        }}
      > 
        <div
          style={{
            fontSize: 68,
            fontWeight: 700,
            lineHeight: 1.08,
          }}
        >
          {ctaText}
        </div>

        <div
          style={{
            marginTop: 55,
          }}
        >
          <BrandBumperLogo
            signatureProgress={logoProgress}
            style={{
             "--brand-bumper-logo-size": "94px",
            }}
          />
        </div>
      </AbsoluteFill>
      <AbsoluteFill
          style={{
            backgroundColor: "#000000",
            opacity: finalFadeOpacity,
          }}
        />
    </AbsoluteFill>
  );
}