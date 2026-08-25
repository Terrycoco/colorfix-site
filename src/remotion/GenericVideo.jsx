import React from "react";

import {
  AbsoluteFill,
  Img,
  interpolate,
  useCurrentFrame,
} from "remotion";

import BrandBumperLogo
  from "./BrandBumperLogo/index.jsx";


const COMPONENTS = {
  brand_bumper_logo:
    BrandBumperLogo,
};


/**
 * GENERIC REMOTION OVEN
 *
 * This component knows only generic rendering primitives:
 *
 *   rect
 *   image
 *   text
 *   component
 *
 * It does not know BAV, Pinterest, YouTube, BEFORE/AFTER,
 * dissolves, end cards, titles, captions, or product timing.
 *
 * The Creator/Recipe supplies exact layers, frame ranges,
 * positions, styles, and animations.
 */
export function GenericVideo({
  plan,
}) {
  const frame =
    useCurrentFrame();

  const layers =
    Array.isArray(
      plan?.layers
    )
      ? plan.layers
      : [];


  return (
    <AbsoluteFill
      style={{
        overflow:
          "hidden",
      }}
    >
      {layers.map(
        (
          layer,
          index
        ) => (
          <GenericLayer
            key={
              layer?.id ||
              `layer-${index}`
            }

            layer={
              layer
            }

            frame={
              frame
            }
          />
        )
      )}
    </AbsoluteFill>
  );
}


function GenericLayer({
  layer,
  frame,
}) {
  if (
    !layer
    ||
    typeof layer !==
      "object"
    ||
    Array.isArray(
      layer
    )
  ) {
    return null;
  }


  const startFrame =
    finiteNumber(
      layer.start_frame,
      0
    );

  const endFrame =
    finiteNumber(
      layer.end_frame,
      Number.MAX_SAFE_INTEGER
    );


  if (
    frame < startFrame
    ||
    frame >= endFrame
  ) {
    return null;
  }


  const box =
    objectOrEmpty(
      layer.box
    );

  const baseStyle = {
    position:
      "absolute",

    left:
      finiteNumber(
        box.x,
        0
      ),

    top:
      finiteNumber(
        box.y,
        0
      ),

    width:
      finiteNumber(
        box.width,
        0
      ),

    height:
      finiteNumber(
        box.height,
        0
      ),

    zIndex:
      finiteNumber(
        box.z,
        0
      ),

    boxSizing:
      "border-box",

    pointerEvents:
      "none",

    ...objectOrEmpty(
      layer.style
    ),
  };


  const props =
    structuredCloneSafe(
      objectOrEmpty(
        layer.props
      )
    );


  applyAnimations(
    baseStyle,
    props,
    Array.isArray(
      layer.animations
    )
      ? layer.animations
      : [],
    frame
  );


  const type =
    String(
      layer.type ||
      ""
    )
      .trim()
      .toLowerCase();


  if (type === "rect") {
    return (
      <div
        style={
          baseStyle
        }
      />
    );
  }


  if (type === "image") {
    const src =
      String(
        layer.src ||
        ""
      )
        .trim();


    if (!src) {
      throw new Error(
        `Generic video image layer '${layer.id || ""}' is missing src.`
      );
    }


    return (
      <div
        style={
          baseStyle
        }
      >
        <Img
          src={
            src
          }

          style={{
            width:
              "100%",

            height:
              "100%",

            display:
              "block",

            objectFit:
              layer.fit ||
              "contain",
          }}
        />
      </div>
    );
  }


  if (type === "text") {
    return (
      <div
        style={
          baseStyle
        }
      >
        {
          String(
            layer.text ??
            ""
          )
        }
      </div>
    );
  }


  if (type === "component") {
    const componentKey =
      String(
        layer.component ||
        ""
      )
        .trim();


    const Component =
      COMPONENTS[
        componentKey
      ];


    if (!Component) {
      throw new Error(
        `Generic video component '${componentKey}' is not registered.`
      );
    }


    return (
      <div
        style={
          baseStyle
        }
      >
        <Component
          {...props}
        />
      </div>
    );
  }


  throw new Error(
    `Generic video layer type '${type}' is not supported.`
  );
}


function applyAnimations(
  style,
  props,
  animations,
  frame
) {
  for (
    const animation
    of animations
  ) {
    if (
      !animation
      ||
      typeof animation !==
        "object"
      ||
      Array.isArray(
        animation
      )
    ) {
      continue;
    }


    const target =
      String(
        animation.target ||
        ""
      )
        .trim();


    if (!target) {
      continue;
    }


    const startFrame =
      finiteNumber(
        animation.start_frame,
        0
      );

    const endFrame =
      finiteNumber(
        animation.end_frame,
        startFrame
      );

    const from =
      finiteNumber(
        animation.from,
        0
      );

    const to =
      finiteNumber(
        animation.to,
        0
      );


    /*
     * Keyframe semantics:
     *
     *   before start -> leave the current/base value alone
     *   during range -> interpolate
     *   after end    -> hold the animation's final value
     *
     * This lets a Chef place multiple sequential animations on
     * the same generic property without the oven inventing behavior.
     */
    if (frame < startFrame) {
      continue;
    }


    const value =
      frame >= endFrame
        ? to
        : interpolate(
            frame,
            [
              startFrame,
              endFrame,
            ],
            [
              from,
              to,
            ],
            {
              extrapolateLeft:
                "clamp",

              extrapolateRight:
                "clamp",
            }
          );


    if (
      target.startsWith(
        "style."
      )
    ) {
      setPath(
        style,
        target.slice(6),
        value
      );

      continue;
    }


    if (
      target.startsWith(
        "props."
      )
    ) {
      setPath(
        props,
        target.slice(6),
        value
      );

      continue;
    }


    throw new Error(
      `Generic video animation target '${target}' is not supported.`
    );
  }
}


function setPath(
  object,
  path,
  value
) {
  const parts =
    String(
      path ||
      ""
    )
      .split(".")
      .filter(Boolean);


  if (!parts.length) {
    return;
  }


  let cursor =
    object;


  for (
    let index = 0;
    index < parts.length - 1;
    index += 1
  ) {
    const key =
      parts[index];


    if (
      !cursor[key]
      ||
      typeof cursor[key] !==
        "object"
      ||
      Array.isArray(
        cursor[key]
      )
    ) {
      cursor[key] = {};
    }


    cursor =
      cursor[key];
  }


  cursor[
    parts[
      parts.length - 1
    ]
  ] =
    value;
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


function objectOrEmpty(
  value
) {
  return (
    value
    &&
    typeof value ===
      "object"
    &&
    !Array.isArray(
      value
    )
  )
    ? value
    : {};
}


function structuredCloneSafe(
  value
) {
  return JSON.parse(
    JSON.stringify(
      value
    )
  );
}