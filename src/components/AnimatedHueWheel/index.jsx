import { useEffect, useRef } from "react";
import ColorWheel300 from "../ColorWheel/ColorWheel300";
import "./animated-hue-wheel.css";

const CENTER = 150;
const DEFAULT_START_RADIUS = 0;
const DEFAULT_RADIUS = 136;
const LABEL_RADIUS = 144;
const WHEEL_FADE_MS = 420;
const SPOKE_DURATION_MS = 700;
const SPOKE_STAGGER_MS = 260;
const SPOKE_START_DELAY_MS = 220;

export default function AnimatedHueWheel({
  items = [],
  currentColor = null,
  animated = true,
  showLabels = true,
  showDots = false,
  pulseOnComplete = true,
  caption = "",
  base = "labels",
  size = 300,
  spokeStartRadius = DEFAULT_START_RADIUS,
  spokeEndRadius = DEFAULT_RADIUS,
  wheelFadeMs = WHEEL_FADE_MS,
  spokeDelayMs = SPOKE_START_DELAY_MS,
  spokeStaggerMs = SPOKE_STAGGER_MS,
  spokeDurationMs = SPOKE_DURATION_MS,
  spokeWidth = 2.75,
  className = "",
}) {
  const normalizedItems = normalizeItems(items);
  const timing = {
    wheelFadeMs: toMs(wheelFadeMs, WHEEL_FADE_MS),
    spokeDelayMs: toMs(spokeDelayMs, SPOKE_START_DELAY_MS),
    spokeStaggerMs: toMs(spokeStaggerMs, SPOKE_STAGGER_MS),
    spokeDurationMs: toMs(spokeDurationMs, SPOKE_DURATION_MS),
  };
  const radii = {
    start: toMs(spokeStartRadius, DEFAULT_START_RADIUS),
    end: toMs(spokeEndRadius, DEFAULT_RADIUS),
  };

  return (
    <figure
      className={[
        "animated-hue-wheel",
        animated ? "is-animated" : "is-static",
        className,
      ].filter(Boolean).join(" ")}
      style={{
        "--ahw-size": `${size}px`,
        "--ahw-wheel-fade-ms": `${animated ? timing.wheelFadeMs : 0}ms`,
        "--ahw-spoke-width": `${Number.isFinite(Number(spokeWidth)) ? Number(spokeWidth) : 2.75}`,
      }}
    >
      <div className="animated-hue-wheel__stage">
        <ColorWheel300 currentColor={currentColor} base={base} size={size}>
          {normalizedItems.map((item, index) => (
            <HueSpoke
              key={`${item.hue}-${item.label || ""}-${index}`}
              item={item}
              index={index}
              animated={animated && item.animate}
              showLabel={showLabels}
              showDot={showDots}
              showPulse={pulseOnComplete}
              timing={timing}
              radii={radii}
            />
          ))}
        </ColorWheel300>
      </div>
      {caption ? (
        <figcaption className="animated-hue-wheel__caption">{caption}</figcaption>
      ) : null}
    </figure>
  );
}

function HueSpoke({
  item,
  index,
  animated,
  showLabel,
  showDot,
  showPulse,
  timing,
  radii,
}) {
  const xAnimateRef = useRef(null);
  const yAnimateRef = useRef(null);
  const startRadius = item.startRadius ?? radii.start;
  const endRadius = item.endRadius ?? radii.end;
  const start = pointAtHue(item.hue, startRadius);
  const end = pointAtHue(item.hue, endRadius);
  const label = pointAtHue(item.hue, LABEL_RADIUS);
  const delay = animated
    ? timing.wheelFadeMs + item.delayMs + (index * timing.spokeStaggerMs)
    : timing.wheelFadeMs;
  const color = item.color || "#111";
  const animationKey = [
    item.hue,
    start.x,
    start.y,
    end.x,
    end.y,
    delay,
    item.durationMs,
  ].join(":");

  useEffect(() => {
    if (!animated) return undefined;
    const timer = window.setTimeout(() => {
      xAnimateRef.current?.beginElement();
      yAnimateRef.current?.beginElement();
    }, delay);
    return () => window.clearTimeout(timer);
  }, [animated, animationKey, delay]);

  return (
    <g
      className={[
        "animated-hue-wheel__marker",
        animated ? "is-spoke-animated" : "is-spoke-static",
        animated && showPulse ? "has-finish-pulse" : "",
      ].join(" ")}
      style={{
        "--ahw-delay-ms": `${delay}ms`,
        "--ahw-spoke-duration-ms": `${item.durationMs}ms`,
      }}
    >
      <line
        key={animationKey}
        className="animated-hue-wheel__spoke"
        x1={start.x}
        y1={start.y}
        x2={animated ? start.x : end.x}
        y2={animated ? start.y : end.y}
        stroke={color}
      >
        {animated ? (
          <>
            <animate
              ref={xAnimateRef}
              attributeName="x2"
              from={start.x}
              to={end.x}
              begin="indefinite"
              dur={formatSeconds(item.durationMs)}
              fill="freeze"
            />
            <animate
              ref={yAnimateRef}
              attributeName="y2"
              from={start.y}
              to={end.y}
              begin="indefinite"
              dur={formatSeconds(item.durationMs)}
              fill="freeze"
            />
          </>
        ) : null}
      </line>
      {showDot ? (
        <circle
          className="animated-hue-wheel__dot"
          cx={end.x}
          cy={end.y}
          r="3.5"
          fill={color}
        />
      ) : null}
      {showLabel && item.label ? (
        <text
          className="animated-hue-wheel__label"
          x={label.x}
          y={label.y}
          fill={color}
          textAnchor={label.x < CENTER - 8 ? "end" : label.x > CENTER + 8 ? "start" : "middle"}
          dominantBaseline="middle"
        >
          {item.label}
        </text>
      ) : null}
    </g>
  );
}

function normalizeItems(items) {
  if (!Array.isArray(items)) return [];
  return items
    .map((item) => {
      if (item?.hue == null || item.hue === "") return null;
      const hue = Number(item?.hue);
      if (!Number.isFinite(hue)) return null;
      return {
        hue: normalizeHue(hue),
        label: item?.label ? String(item.label) : "",
        color: item?.color ? String(item.color) : "",
        animate: item?.animate !== false,
        delayMs: toMs(item?.delayMs ?? item?.delay, SPOKE_START_DELAY_MS),
        durationMs: toMs(item?.durationMs ?? item?.duration, SPOKE_DURATION_MS),
        startRadius: optionalMs(item?.startRadius),
        endRadius: optionalMs(item?.endRadius),
      };
    })
    .filter(Boolean);
}

function toMs(value, fallback) {
  const parsed = Number(value);
  if (!Number.isFinite(parsed) || parsed < 0) return fallback;
  return parsed;
}

function optionalMs(value) {
  if (value == null || value === "") return null;
  const parsed = Number(value);
  if (!Number.isFinite(parsed) || parsed < 0) return null;
  return parsed;
}

function formatSeconds(ms) {
  return `${Number(ms / 1000).toFixed(3)}s`;
}

function normalizeHue(hue) {
  return ((hue % 360) + 360) % 360;
}

function pointAtHue(hue, radius) {
  const angle = ((hue - 90) * Math.PI) / 180;
  return {
    x: Number((CENTER + radius * Math.cos(angle)).toFixed(3)),
    y: Number((CENTER + radius * Math.sin(angle)).toFixed(3)),
  };
}
